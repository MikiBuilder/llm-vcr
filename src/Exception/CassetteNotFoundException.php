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
            "llm-vcr: no match found in cassette \"%s\" (replay mode).\n"
            . "  Best similarity found: %.2f (threshold: %.2f)\n"
            . "  How to fix it:\n"
            . "    1. Record the interaction:  LLM_VCR_MODE=record vendor/bin/phpunit\n"
            . "    2. Commit the generated cassette.\n"
            . "    3. If the prompt changed a lot on purpose, lower the matcher threshold.",
            $name,
            $bestSimilarity,
            $threshold,
        ));
    }
}
