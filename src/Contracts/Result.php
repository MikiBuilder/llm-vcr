<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Contracts;

/**
 * Resultado normalizado de una invocación a un modelo.
 *
 * Inmutable (readonly): un resultado grabado nunca debe poder mutarse
 * después de escribirse en la cassette.
 */
final readonly class Result
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $latencyMs = 0.0,
        public bool $fromCassette = false,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Decodifica la respuesta como JSON estructurado, si lo es.
     *
     * Tolera los envoltorios de bloque de código que muchos modelos añaden
     * pese a pedirles JSON puro (```json ... ```).
     *
     * @return array<mixed>|null
     */
    public function asStructured(): ?array
    {
        $trimmed = trim($this->text);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
