<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Contracts;

/**
 * Contrato mínimo de una plataforma LLM.
 *
 * Deliberadamente pequeño: cuanto menor es la superficie, más fácil es
 * decorarla y más implementaciones pueden adaptarse (Groq, OpenAI,
 * OpenRouter, Ollama, Symfony AI...).
 */
interface PlatformInterface
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, scalar|null>                 $options
     */
    public function invoke(string $model, array $messages, array $options = []): Result;
}
