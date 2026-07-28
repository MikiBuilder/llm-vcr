<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Bridge\Symfony;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;

/**
 * Envuelve plataformas LLM con grabación/reproducción usando la
 * configuración del bundle.
 *
 * Es el punto de entrada desde el código de la aplicación:
 *
 *     public function __construct(
 *         private PlatformFactory $vcr,
 *         private MiClienteLlm $cliente,
 *     ) {}
 *
 *     $platform = $this->vcr->wrap($this->cliente);
 *
 * Además lleva un registro de las plataformas creadas para que el
 * DataCollector pueda agregar sus métricas sin que nadie tenga que
 * pasárselas a mano.
 */
final class PlatformFactory
{
    /** @var list<RecordingPlatform> */
    private array $created = [];

    private readonly Mode $mode;

    public function __construct(
        private readonly string $cassetteDir,
        string $mode,
        private readonly MatcherInterface $matcher,
        private readonly Redactor $redactor,
    ) {
        $this->mode = Mode::tryFrom($mode) ?? Mode::Record;
    }

    /**
     * Envuelve una plataforma real.
     *
     * @param string|null $cassette nombre explícito; si se omite, se deriva del prompt de sistema
     */
    public function wrap(
        PlatformInterface $inner,
        ?string $cassette = null,
        ?Mode $mode = null,
        ?MatcherInterface $matcher = null,
    ): RecordingPlatform {
        $platform = new RecordingPlatform(
            inner: $inner,
            cassetteDir: $this->cassetteDir,
            mode: $mode ?? $this->mode,
            matcher: $matcher ?? $this->matcher,
            redactor: $this->redactor,
        );

        if ($cassette !== null) {
            $platform->useCassette($cassette);
        }

        $this->created[] = $platform;

        return $platform;
    }

    /**
     * Métricas agregadas de todas las plataformas creadas en esta petición.
     *
     * @return array{
     *     mode: string,
     *     live: int,
     *     replayed: int,
     *     latency_saved_ms: float,
     *     tokens_saved: int,
     *     hit_rate: float,
     *     platforms: int
     * }
     */
    public function aggregatedStats(): array
    {
        $live = 0;
        $replayed = 0;
        $latencySaved = 0.0;
        $tokensSaved = 0;

        foreach ($this->created as $platform) {
            $stats = $platform->stats();
            $live += $stats['live'];
            $replayed += $stats['replayed'];
            $latencySaved += $stats['latency_saved_ms'];
            $tokensSaved += $stats['tokens_saved'];
        }

        $total = $live + $replayed;

        return [
            'mode' => $this->mode->value,
            'live' => $live,
            'replayed' => $replayed,
            'latency_saved_ms' => round($latencySaved, 1),
            'tokens_saved' => $tokensSaved,
            'hit_rate' => $total > 0 ? round($replayed / $total, 3) : 0.0,
            'platforms' => count($this->created),
        ];
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function cassetteDir(): string
    {
        return $this->cassetteDir;
    }

    /**
     * Cuenta las cassettes existentes en disco.
     */
    public function cassetteCount(): int
    {
        $files = glob(rtrim($this->cassetteDir, '/') . '/*.json');

        return $files === false ? 0 : count($files);
    }

    /**
     * Necesario para el DataCollector: Symfony reutiliza servicios entre
     * peticiones en runtimes persistentes (FrankenPHP, RoadRunner).
     */
    public function reset(): void
    {
        $this->created = [];
    }
}
