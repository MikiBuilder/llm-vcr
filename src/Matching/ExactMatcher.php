<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Matching;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;

/**
 * Matcher estricto: la petición debe ser idéntica tras normalizar espacios.
 *
 * Útil cuando el prompt es totalmente determinista y quieres garantía
 * absoluta de que la cassette corresponde exactamente a la petición.
 */
final class ExactMatcher implements MatcherInterface
{
    public function normalize(string $text): string
    {
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
}
