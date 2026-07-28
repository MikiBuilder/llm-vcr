<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Platform;

use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Exception\TransportException;

/**
 * Cliente para la API de Groq (free tier, sin tarjeta de crédito).
 *
 * La API es compatible con OpenAI, así que esta misma clase sirve para
 * cualquier endpoint compatible cambiando la baseUrl:
 *   - Groq:       https://api.groq.com/openai/v1
 *   - OpenRouter: https://openrouter.ai/api/v1
 *   - Ollama:     http://localhost:11434/v1
 *
 * Usa streams nativos de PHP en vez de cURL para no añadir dependencias:
 * el paquete debe instalarse en cualquier entorno sin fricción.
 */
final class GroqPlatform implements PlatformInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.groq.com/openai/v1',
        private readonly int $timeoutSeconds = 30,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'Falta la API key. Consigue una gratis en https://console.groq.com/keys '
                . 'y expórtala como GROQ_API_KEY.',
            );
        }
    }

    public static function fromEnv(string $variable = 'GROQ_API_KEY'): self
    {
        $key = getenv($variable);

        if (!is_string($key) || trim($key) === '') {
            throw new \InvalidArgumentException(sprintf(
                'La variable de entorno %s no está definida. '
                . 'Consigue una clave gratuita en https://console.groq.com/keys',
                $variable,
            ));
        }

        return new self($key);
    }

    public function invoke(string $model, array $messages, array $options = []): Result
    {
        $payload = array_merge(
            [
                'model' => $model,
                'messages' => $messages,
            ],
            $options,
        );

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'User-Agent: llm-vcr/0.1 (+https://github.com/MikiBuilder/llm-vcr)',
                ]),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $start = microtime(true);
        $raw = @file_get_contents($this->baseUrl . '/chat/completions', false, $context);
        $latencyMs = (microtime(true) - $start) * 1000;

        if ($raw === false) {
            throw new TransportException(sprintf(
                'No se pudo contactar con el proveedor en %s. ¿Hay conexión de red?',
                $this->baseUrl,
            ));
        }

        /**
         * $http_response_header es una variable mágica que PHP inyecta en el
         * ámbito local tras una petición HTTP por streams.
         *
         * @var list<string> $http_response_header
         */
        $status = self::statusFromHeaders($http_response_header);

        if ($status === 429) {
            throw new TransportException(
                'Rate limit alcanzado en el free tier. Espera un minuto o usa un modelo '
                . 'con más cuota (llama-3.1-8b-instant).',
            );
        }

        if ($status >= 400) {
            throw new TransportException(sprintf(
                'El proveedor respondió %d: %s',
                $status,
                mb_substr($raw, 0, 400),
            ));
        }

        return self::parseResponse($raw, $model, $latencyMs);
    }

    private static function parseResponse(string $raw, string $model, float $latencyMs): Result
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(
                'Respuesta no es JSON válido: ' . mb_substr($raw, 0, 200),
                previous: $e,
            );
        }

        /** @var list<array<string, mixed>> $choices */
        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];

        if ($choices === []) {
            throw new TransportException('La respuesta no contiene "choices".');
        }

        /** @var array<string, mixed> $message */
        $message = is_array($choices[0]['message'] ?? null) ? $choices[0]['message'] : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';

        /** @var array<string, mixed> $usage */
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new Result(
            text: $text,
            model: is_string($data['model'] ?? null) ? $data['model'] : $model,
            inputTokens: is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0,
            outputTokens: is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0,
            latencyMs: $latencyMs,
        );
    }

    /** @param list<string> $headers */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
