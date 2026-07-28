<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Contracts;

/**
 * Estrategia de comparación entre la petición actual y una grabada.
 *
 * Intercambiable a propósito: ExactMatcher para máxima seguridad,
 * SemanticMatcher para tolerancia a prompts que cambian.
 */
interface MatcherInterface
{
    /**
     * Similitud entre dos peticiones, en el rango [0.0, 1.0].
     */
    public function similarity(string $a, string $b): float;

    /**
     * Umbral a partir del cual se considera coincidencia.
     */
    public function threshold(): float;

    /**
     * Normaliza el texto eliminando ruido volátil.
     */
    public function normalize(string $text): string;
}
