<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Matching;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;

/**
 * Matcher por placeholders explícitos.
 *
 * El punto medio entre el hash exacto (frágil) y la similitud semántica
 * (difusa): TÚ declaras qué partes del prompt son variables, y el matcher
 * las sustituye por un marcador en AMBOS lados antes de comparar.
 *
 * El resultado sigue siendo una comparación exacta —determinista y
 * revisable en una PR— pero inmune a los datos dinámicos que tú decidas.
 *
 *   $matcher = new PlaceholderMatcher([
 *       'order_id' => '/PED-\d+/',
 *       'importe'  => '/\d+,\d{2} ?€/',
 *   ]);
 *
 *   "Revisa el pedido PED-4417 por 89,90 €"
 *   "Revisa el pedido PED-9902 por 12,50 €"
 *   → ambos se normalizan a:
 *   "Revisa el pedido {{order_id}} por {{importe}}"
 *   → coincidencia exacta, similitud 1.0
 *
 * Ventaja sobre el fuzzy: cero falsos positivos. Si dos prompts difieren
 * en algo que NO declaraste como variable, no casan. Y eso es lo correcto.
 */
final class PlaceholderMatcher implements MatcherInterface
{
    /** @var array<string, string> nombre => patrón regex */
    private readonly array $placeholders;

    /**
     * @param array<string, string> $placeholders nombre => patrón regex (sin delimitadores o con ellos)
     */
    public function __construct(
        array $placeholders = [],
        private readonly bool $withCommonDefaults = true,
    ) {
        $resolved = [];

        foreach ($placeholders as $name => $pattern) {
            $resolved[$name] = self::ensureDelimiters($pattern);
        }

        if ($withCommonDefaults) {
            // No pisan a los del usuario: los suyos se aplican primero.
            $resolved += [
                'timestamp' => '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?Z?/i',
                'date' => '/\d{4}-\d{2}-\d{2}/',
                'time' => '/\d{2}:\d{2}:\d{2}/',
                'uuid' => '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            ];
        }

        $this->placeholders = $resolved;
    }

    private static function ensureDelimiters(string $pattern): string
    {
        // Permite pasar '\d+' o '/\d+/' indistintamente.
        if ($pattern !== '' && $pattern[0] === '/' && str_contains(substr($pattern, 1), '/')) {
            return $pattern;
        }

        return '/' . str_replace('/', '\/', $pattern) . '/';
    }

    public function normalize(string $text): string
    {
        foreach ($this->placeholders as $name => $pattern) {
            $replaced = preg_replace($pattern, '{{' . $name . '}}', $text);

            if ($replaced === null) {
                throw new \InvalidArgumentException(sprintf(
                    'The pattern for placeholder "%s" is not a valid regular expression: %s',
                    $name,
                    $pattern,
                ));
            }

            $text = $replaced;
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function similarity(string $a, string $b): float
    {
        return $this->normalize($a) === $this->normalize($b) ? 1.0 : 0.0;
    }

    public function threshold(): float
    {
        return 1.0;
    }

    /**
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }
}
