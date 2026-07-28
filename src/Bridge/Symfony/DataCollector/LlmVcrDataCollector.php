<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Bridge\Symfony\DataCollector;

use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recoge las métricas de llm-vcr para el Web Profiler.
 *
 * Responde de un vistazo a las preguntas que importan:
 *   - ¿esta petición ha llamado a la API real o ha ido desde disco?
 *   - ¿cuánto tiempo y cuántos tokens me he ahorrado?
 */
final class LlmVcrDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly PlatformFactory $factory,
    ) {
        $this->data = self::emptyData();
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $stats = $this->factory->aggregatedStats();

        $this->data = [
            'mode' => $stats['mode'],
            'live' => $stats['live'],
            'replayed' => $stats['replayed'],
            'latency_saved_ms' => $stats['latency_saved_ms'],
            'tokens_saved' => $stats['tokens_saved'],
            'hit_rate' => $stats['hit_rate'],
            'platforms' => $stats['platforms'],
            'cassette_dir' => $this->factory->cassetteDir(),
            'cassette_count' => $this->factory->cassetteCount(),
        ];
    }

    /** @return array<string, scalar> */
    private static function emptyData(): array
    {
        return [
            'mode' => 'record',
            'live' => 0,
            'replayed' => 0,
            'latency_saved_ms' => 0.0,
            'tokens_saved' => 0,
            'hit_rate' => 0.0,
            'platforms' => 0,
            'cassette_dir' => '',
            'cassette_count' => 0,
        ];
    }

    public function reset(): void
    {
        $this->data = self::emptyData();
        $this->factory->reset();
    }

    public function getName(): string
    {
        return 'llm_vcr';
    }

    /**
     * El tipo de retorno nullable lo impone AbstractDataCollector, aunque
     * aquí siempre haya plantilla. No se puede estrechar sin romper la firma
     * heredada.
     *
     * @phpstan-ignore return.unusedType
     */
    public static function getTemplate(): ?string
    {
        return '@LlmVcr/Collector/llm_vcr.html.twig';
    }

    // ── Accesores para la plantilla ─────────────────────────────────────

    public function getMode(): string
    {
        return (string) $this->data['mode'];
    }

    public function getLive(): int
    {
        return (int) $this->data['live'];
    }

    public function getReplayed(): int
    {
        return (int) $this->data['replayed'];
    }

    public function getTotal(): int
    {
        return $this->getLive() + $this->getReplayed();
    }

    public function getLatencySavedMs(): float
    {
        return (float) $this->data['latency_saved_ms'];
    }

    public function getTokensSaved(): int
    {
        return (int) $this->data['tokens_saved'];
    }

    public function getHitRate(): float
    {
        return (float) $this->data['hit_rate'];
    }

    public function getHitRatePercent(): int
    {
        return (int) round($this->getHitRate() * 100);
    }

    public function getPlatforms(): int
    {
        return (int) $this->data['platforms'];
    }

    public function getCassetteDir(): string
    {
        return (string) $this->data['cassette_dir'];
    }

    public function getCassetteCount(): int
    {
        return (int) $this->data['cassette_count'];
    }

    /**
     * Color del badge en la barra de depuración.
     *
     * Rojo si se ha tocado la red en un entorno donde no debería,
     * verde si todo vino de disco.
     */
    public function getStatusColor(): string
    {
        if ($this->getTotal() === 0) {
            return 'default';
        }

        if ($this->getLive() === 0) {
            return 'green';
        }

        return $this->getMode() === 'record' ? 'yellow' : 'red';
    }
}
