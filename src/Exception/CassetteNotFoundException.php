<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Exception;

/**
 * Se lanza en modo replay cuando no hay ninguna interacción que coincida.
 *
 * El mensaje es deliberadamente pedagógico: quien se encuentra este error
 * en CI suele ser alguien que acaba de añadir un test y no sabe por qué falla.
 */
final class CassetteNotFoundException extends LlmVcrException
{
    public static function forCassette(string $name, float $bestSimilarity, float $threshold): self
    {
        return new self(sprintf(
            "llm-vcr: no hay coincidencia en la cassette \"%s\" (modo replay).\n"
            . "  Mejor similitud encontrada: %.2f (umbral: %.2f)\n"
            . "  Soluciones:\n"
            . "    1. Graba la interacción:  LLM_VCR_MODE=record vendor/bin/phpunit\n"
            . "    2. Commitea la cassette generada.\n"
            . "    3. Si el prompt cambió mucho a propósito, baja el umbral del matcher.",
            $name,
            $bestSimilarity,
            $threshold,
        ));
    }
}
