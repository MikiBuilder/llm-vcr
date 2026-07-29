<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Matching;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;

/**
 * Matcher por similitud semántica, no por hash exacto.
 *
 * Este es el diferencial frente a php-vcr: un prompt real cambia
 * constantemente (timestamps, IDs, nombres, orden del contexto RAG). Un hash
 * exacto invalida la cassette en cuanto tocas una coma.
 *
 * Estrategia: normalización de tokens volátiles + similitud de coseno sobre
 * bag-of-words con damping logarítmico. Sin dependencias, sin llamadas de red,
 * O(n) sobre el tamaño del prompt.
 */
final class SemanticMatcher implements MatcherInterface
{
    /**
     * Palabras vacías en español e inglés. No aportan señal semántica
     * y distorsionan la similitud al inflar el vector.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'un', 'una', 'por', 'con',
        'para', 'que', 'del', 'al', 'es', 'se', 'su', 'lo', 'como', 'mi', 'me',
        'the', 'of', 'and', 'to', 'in', 'is', 'you', 'are', 'for', 'this', 'that',
        'it', 'as', 'be', 'on', 'with', 'at', 'by', 'an', 'or', 'from',
    ];

    /**
     * @param float                 $threshold   umbral de coincidencia [0..1]
     * @param array<string, string> $extraNoise  patrones adicionales a normalizar (regex => reemplazo)
     */
    public function __construct(
        private readonly float $threshold = 0.82,
        private readonly array $extraNoise = [],
    ) {
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new \InvalidArgumentException('The threshold must be between 0.0 and 1.0.');
        }
    }

    /**
     * Elimina el ruido volátil que no cambia la semántica de la petición.
     */
    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        $volatile = [
            // Orden relevante: de lo más específico a lo más genérico.
            '/\d{4}-\d{2}-\d{2}[t ]\d{2}:\d{2}(:\d{2})?(\.\d+)?z?/' => '<timestamp>',
            '/\d{4}-\d{2}-\d{2}/' => '<date>',
            '/\d{2}\/\d{2}\/\d{4}/' => '<date>',
            '/\d{2}:\d{2}:\d{2}/' => '<time>',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/' => '<uuid>',
            '/\b[0-9a-f]{32,}\b/' => '<hash>',
            '/\b\d{6,}\b/' => '<id>',
            '/\s+/' => ' ',
        ];

        foreach ($this->extraNoise + $volatile as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return trim($text);
    }

    /**
     * @return array<string, int>
     */
    private function tokenize(string $text): array
    {
        $normalized = $this->normalize($text);
        preg_match_all('/[\p{L}\p{N}_<>]+/u', $normalized, $matches);

        $counts = [];
        foreach ($matches[0] as $token) {
            if (mb_strlen($token) < 2 || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Similitud de coseno con pesado logarítmico.
     *
     * El damping evita que una palabra repetida 20 veces domine el vector.
     */
    public function similarity(string $a, string $b): float
    {
        $va = $this->tokenize($a);
        $vb = $this->tokenize($b);

        if ($va === [] || $vb === []) {
            return $va === $vb ? 1.0 : 0.0;
        }

        $weight = static fn (int $count): float => 1.0 + log((float) $count);

        $dot = 0.0;
        foreach ($va as $token => $count) {
            if (isset($vb[$token])) {
                $dot += $weight($count) * $weight($vb[$token]);
            }
        }

        $normA = 0.0;
        foreach ($va as $count) {
            $normA += $weight($count) ** 2;
        }

        $normB = 0.0;
        foreach ($vb as $count) {
            $normB += $weight($count) ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        // El redondeo no es cosmético: sin él, la similitud de un texto
        // consigo mismo devuelve 0.9999999999999998 por acumulación de error
        // en coma flotante, y un umbral de 1.0 (ExactMatcher) nunca casaría.
        return min(1.0, round($dot / (sqrt($normA) * sqrt($normB)), 12));
    }

    public function threshold(): float
    {
        return $this->threshold;
    }
}
