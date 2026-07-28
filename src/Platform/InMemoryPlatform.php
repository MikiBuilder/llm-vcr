<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Platform;

use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Contracts\Result;

/**
 * Plataforma en memoria para los tests del propio paquete.
 *
 * Acepta una respuesta fija, un mapa palabra-clave => respuesta, o un
 * callable para simular cualquier comportamiento (incluidos fallos).
 */
final class InMemoryPlatform implements PlatformInterface
{
    private int $calls = 0;

    /** @var list<array{model: string, messages: list<array{role: string, content: string}>}> */
    private array $received = [];

    /** @param string|array<string, string>|callable(string, list<array{role: string, content: string}>): (string|Result) $responder */
    public function __construct(
        private readonly mixed $responder,
        private readonly float $simulatedLatencyMs = 0.0,
    ) {
    }

    public function invoke(string $model, array $messages, array $options = []): Result
    {
        ++$this->calls;
        $this->received[] = ['model' => $model, 'messages' => $messages];

        if ($this->simulatedLatencyMs > 0.0) {
            usleep((int) ($this->simulatedLatencyMs * 1000));
        }

        $responder = $this->responder;

        if (is_callable($responder)) {
            $outcome = $responder($model, $messages);

            if ($outcome instanceof Result) {
                return $outcome;
            }

            return $this->wrap((string) $outcome, $model, $messages);
        }

        if (is_array($responder)) {
            $haystack = mb_strtolower(self::userContent($messages));

            foreach ($responder as $needle => $response) {
                if (str_contains($haystack, mb_strtolower($needle))) {
                    return $this->wrap($response, $model, $messages);
                }
            }

            return $this->wrap('', $model, $messages);
        }

        return $this->wrap((string) $responder, $model, $messages);
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function wrap(string $text, string $model, array $messages): Result
    {
        $input = self::userContent($messages);

        return new Result(
            text: $text,
            model: $model,
            inputTokens: max(1, (int) (mb_strlen($input) / 4)),
            outputTokens: max(1, (int) (mb_strlen($text) / 4)),
            latencyMs: $this->simulatedLatencyMs,
        );
    }

    /** @param list<array{role: string, content: string}> $messages */
    private static function userContent(array $messages): string
    {
        $out = '';
        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                $out .= ' ' . $message['content'];
            }
        }

        return trim($out);
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /** @return list<array{model: string, messages: list<array{role: string, content: string}>}> */
    public function received(): array
    {
        return $this->received;
    }
}
