<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Cassette;

/**
 * Una interacción grabada: petición + respuesta + metadatos.
 */
final readonly class Interaction
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, scalar|null>                 $options
     */
    public function __construct(
        public string $model,
        public array $messages,
        public array $options,
        public string $response,
        public int $inputTokens,
        public int $outputTokens,
        public float $latencyMs,
        public string $fingerprint,
        public string $recordedAt = '',
    ) {
    }

    /**
     * Texto canónico usado para el matching.
     */
    public function requestText(): string
    {
        $parts = [];
        foreach ($this->messages as $message) {
            $parts[] = $message['role'] . ': ' . $message['content'];
        }

        return implode("\n", $parts);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $request */
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        /** @var array<string, mixed> $response */
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];

        /** @var list<array{role: string, content: string}> $messages */
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
        /** @var array<string, scalar|null> $options */
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        return new self(
            model: is_string($request['model'] ?? null) ? $request['model'] : '',
            messages: $messages,
            options: $options,
            response: is_string($response['text'] ?? null) ? $response['text'] : '',
            inputTokens: is_numeric($response['input_tokens'] ?? null) ? (int) $response['input_tokens'] : 0,
            outputTokens: is_numeric($response['output_tokens'] ?? null) ? (int) $response['output_tokens'] : 0,
            latencyMs: is_numeric($response['latency_ms'] ?? null) ? (float) $response['latency_ms'] : 0.0,
            fingerprint: is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : '',
            recordedAt: is_string($data['recorded_at'] ?? null) ? $data['recorded_at'] : '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'recorded_at' => $this->recordedAt,
            'request' => [
                'model' => $this->model,
                'messages' => $this->messages,
                'options' => $this->options,
            ],
            'response' => [
                'text' => $this->response,
                'input_tokens' => $this->inputTokens,
                'output_tokens' => $this->outputTokens,
                'latency_ms' => round($this->latencyMs, 2),
            ],
        ];
    }
}
