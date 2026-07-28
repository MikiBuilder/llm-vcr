<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr;

use MikiBuilder\LlmVcr\Cassette\Cassette;
use MikiBuilder\LlmVcr\Cassette\Interaction;
use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Exception\CassetteNotFoundException;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Redaction\Redactor;

/**
 * Decorador de PlatformInterface que graba y reproduce interacciones LLM.
 *
 * CLAVE ARQUITECTÓNICA: es un DECORADOR, no un fork. Envuelve cualquier
 * implementación sin que el código de negocio se entere. Sustitución de
 * Liskov pura, y por tanto compatible con Symfony AI el día que saquen la 1.0
 * sin depender de sus internals.
 *
 * Uso típico:
 *
 *   $platform = new RecordingPlatform(
 *       inner: new GroqPlatform($apiKey),
 *       cassetteDir: __DIR__ . '/cassettes',
 *       mode: Mode::fromEnv(),
 *   );
 */
final class RecordingPlatform implements PlatformInterface
{
    private readonly MatcherInterface $matcher;
    private readonly Redactor $redactor;

    /** @var array<string, Cassette> caché en memoria para no releer disco */
    private array $loaded = [];

    /**
     * Nombre de cassette fijado explícitamente por el test en curso.
     * Cuando está puesto, gana sobre el nombre derivado del prompt.
     */
    private ?string $pinnedCassette = null;

    private int $liveCalls = 0;
    private int $replayedCalls = 0;
    private float $latencySavedMs = 0.0;
    private int $tokensSaved = 0;

    public function __construct(
        private readonly PlatformInterface $inner,
        private readonly string $cassetteDir,
        private readonly Mode $mode = Mode::Record,
        ?MatcherInterface $matcher = null,
        ?Redactor $redactor = null,
    ) {
        $this->matcher = $matcher ?? new SemanticMatcher();
        $this->redactor = $redactor ?? new Redactor();
    }

    /**
     * Fija la cassette a usar, ignorando el nombre derivado del prompt.
     *
     * Es lo que permite que un test diga "esto va en la cassette
     * clasificacion-tickets" y que el fichero sea localizable de un vistazo
     * en la PR. El trait de PHPUnit y el plugin de Pest lo usan por debajo.
     */
    public function useCassette(string $name): self
    {
        // Solo se sustituyen los caracteres inválidos. Los guiones que ya
        // vienen en el nombre se respetan, para que un separador
        // intencionado como "clase--metodo" sobreviva a la normalización.
        $slug = (string) preg_replace('/[^a-z0-9\-]+/', '-', mb_strtolower($name));
        $slug = trim($slug, '-');

        if ($slug === '') {
            throw new \InvalidArgumentException(
                'El nombre de la cassette no puede quedar vacío tras normalizarlo.',
            );
        }

        $this->pinnedCassette = $slug;

        return $this;
    }

    /**
     * Vuelve al nombrado automático a partir del prompt de sistema.
     */
    public function forgetCassette(): self
    {
        $this->pinnedCassette = null;

        return $this;
    }

    public function pinnedCassette(): ?string
    {
        return $this->pinnedCassette;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function invoke(string $model, array $messages, array $options = []): Result
    {
        if ($this->mode === Mode::Bypass) {
            ++$this->liveCalls;

            return $this->inner->invoke($model, $messages, $options);
        }

        $cassette = $this->cassetteFor($model, $messages);

        /*
         * SUTIL PERO CRÍTICO: el matching se hace siempre sobre el texto YA
         * REDACTADO. Lo que hay en la cassette pasó por el Redactor al
         * grabarse, así que si comparásemos contra el prompt en crudo, un
         * email real nunca casaría con su marcador <REDACTED:EMAIL> y la
         * cassette fallaría justo en las peticiones que contienen PII.
         * Ambos lados de la comparación deben estar en el mismo espacio.
         */
        $redactedMessages = $this->redactor->redactMessages($messages);
        $requestText = self::requestText($redactedMessages);

        if ($this->mode->readsFromCassette()) {
            $hit = $this->findMatch($cassette, $model, $requestText);

            if ($hit !== null) {
                return $this->replay($hit[0]);
            }
        }

        if (!$this->mode->canHitNetwork()) {
            throw CassetteNotFoundException::forCassette(
                $cassette->name(),
                $this->bestSimilarity($cassette, $model, $requestText),
                $this->matcher->threshold(),
            );
        }

        // Al proveedor real se le envían los mensajes ORIGINALES, sin redactar:
        // la redacción protege el fichero en disco, no degrada la petición.
        return $this->recordLive($cassette, $model, $messages, $redactedMessages, $options, $requestText);
    }

    private function replay(Interaction $interaction): Result
    {
        ++$this->replayedCalls;
        $this->latencySavedMs += $interaction->latencyMs;
        $this->tokensSaved += $interaction->inputTokens + $interaction->outputTokens;

        return new Result(
            text: $interaction->response,
            model: $interaction->model,
            inputTokens: $interaction->inputTokens,
            outputTokens: $interaction->outputTokens,
            latencyMs: 0.0,
            fromCassette: true,
        );
    }

    /**
     * @param list<array{role: string, content: string}> $messages         originales, van al proveedor
     * @param list<array{role: string, content: string}> $redactedMessages saneados, van al disco
     * @param array<string, scalar|null>                 $options
     */
    private function recordLive(
        Cassette $cassette,
        string $model,
        array $messages,
        array $redactedMessages,
        array $options,
        string $requestText,
    ): Result {
        ++$this->liveCalls;

        $result = $this->inner->invoke($model, $messages, $options);

        $cassette->add(new Interaction(
            model: $model,
            messages: $redactedMessages,
            options: $options,
            response: $this->redactor->redact($result->text),
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            latencyMs: $result->latencyMs,
            fingerprint: substr(hash('sha256', $this->matcher->normalize($requestText)), 0, 16),
            recordedAt: date('c'),
        ));

        $cassette->save();

        return $result;
    }

    /**
     * @return array{0: Interaction, 1: float}|null
     */
    private function findMatch(Cassette $cassette, string $model, string $requestText): ?array
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($cassette->interactions() as $interaction) {
            if ($interaction->model !== $model) {
                continue;
            }

            $score = $this->matcher->similarity($requestText, $interaction->requestText());
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $interaction;
            }
        }

        if ($best !== null && $bestScore >= $this->matcher->threshold()) {
            return [$best, $bestScore];
        }

        return null;
    }

    private function bestSimilarity(Cassette $cassette, string $model, string $requestText): float
    {
        $best = 0.0;

        foreach ($cassette->interactions() as $interaction) {
            if ($interaction->model !== $model) {
                continue;
            }
            $best = max($best, $this->matcher->similarity($requestText, $interaction->requestText()));
        }

        return $best;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function cassetteFor(string $model, array $messages): Cassette
    {
        $name = $this->pinnedCassette ?? self::cassetteName($model, $messages);

        if (!isset($this->loaded[$name])) {
            $path = rtrim($this->cassetteDir, '/') . '/' . $name . '.json';

            $this->loaded[$name] = $this->mode === Mode::Refresh
                ? new Cassette($name, $path)
                : Cassette::load($name, $path);
        }

        return $this->loaded[$name];
    }

    /**
     * Agrupa las interacciones por prompt de sistema: cada "caso de uso"
     * tiene su propia cassette, legible en la PR.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public static function cassetteName(string $model, array $messages): string
    {
        $system = '';
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $message['content'];
                break;
            }
        }

        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(mb_substr($system, 0, 40)));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'default';
        }

        return $slug . '-' . substr(hash('sha256', $model . $system), 0, 8);
    }

    /** @param list<array{role: string, content: string}> $messages */
    public static function requestText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $parts[] = $message['role'] . ': ' . $message['content'];
        }

        return implode("\n", $parts);
    }

    /**
     * @return array{
     *     mode: string,
     *     live: int,
     *     replayed: int,
     *     latency_saved_ms: float,
     *     tokens_saved: int,
     *     hit_rate: float
     * }
     */
    public function stats(): array
    {
        $total = $this->liveCalls + $this->replayedCalls;

        return [
            'mode' => $this->mode->value,
            'live' => $this->liveCalls,
            'replayed' => $this->replayedCalls,
            'latency_saved_ms' => round($this->latencySavedMs, 1),
            'tokens_saved' => $this->tokensSaved,
            'hit_rate' => $total > 0 ? round($this->replayedCalls / $total, 3) : 0.0,
        ];
    }
}
