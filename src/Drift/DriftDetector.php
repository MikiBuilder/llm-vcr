<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Drift;

use MikiBuilder\LlmVcr\Cassette\Cassette;
use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;

/**
 * Detector de deriva semántica (model drift).
 *
 * Reproduce contra el proveedor real las peticiones grabadas y compara la
 * respuesta actual con la de la cassette. Responde a la pregunta que hoy
 * nadie responde en PHP: "¿ha cambiado el comportamiento de mi feature de IA
 * sin que yo haya tocado una sola línea de código?".
 *
 * Se ejecuta en un cron nocturno, no en cada push: consume cuota real.
 */
final class DriftDetector
{
    private readonly MatcherInterface $matcher;

    public function __construct(
        private readonly PlatformInterface $livePlatform,
        ?MatcherInterface $matcher = null,
        private readonly float $alertThreshold = 0.85,
    ) {
        $this->matcher = $matcher ?? new SemanticMatcher();
    }

    /**
     * @return list<DriftReport>
     */
    public function analyze(Cassette $cassette): array
    {
        $reports = [];

        foreach ($cassette->interactions() as $interaction) {
            $live = $this->livePlatform->invoke(
                $interaction->model,
                $interaction->messages,
                $interaction->options,
            );

            $similarity = $this->matcher->similarity($interaction->response, $live->text);
            $structuralDiff = self::structuralDiff($interaction->response, $live->text);

            $reports[] = new DriftReport(
                model: $interaction->model,
                fingerprint: $interaction->fingerprint,
                recorded: $interaction->response,
                current: $live->text,
                similarity: $similarity,
                structuralDiff: $structuralDiff,
                drifted: $similarity < $this->alertThreshold || $structuralDiff !== [],
            );
        }

        return $reports;
    }

    /**
     * Compara la FORMA del JSON (claves y tipos), no solo el texto.
     *
     * Un cambio de tipo rompe el DTO en producción aunque la similitud
     * textual siga siendo alta. Esto es lo que hoy no detecta nadie en PHP.
     *
     * @return list<string>
     */
    public static function structuralDiff(string $recorded, string $current): array
    {
        $a = json_decode($recorded, true);
        $b = json_decode($current, true);

        if (!is_array($a) || !is_array($b)) {
            return [];
        }

        $diff = [];
        $shapeA = self::shape($a);
        $shapeB = self::shape($b);

        foreach ($shapeA as $key => $type) {
            if (!array_key_exists($key, $shapeB)) {
                $diff[] = sprintf('campo eliminado: "%s" (era %s)', $key, $type);
                continue;
            }

            if ($shapeB[$key] !== $type) {
                $diff[] = sprintf('cambio de tipo en "%s": %s -> %s', $key, $type, $shapeB[$key]);
            }
        }

        foreach ($shapeB as $key => $type) {
            if (!array_key_exists($key, $shapeA)) {
                $diff[] = sprintf('campo nuevo: "%s" (%s)', $key, $type);
            }
        }

        return $diff;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function shape(array $data, string $prefix = ''): array
    {
        $shape = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $shape += self::shape($value, $path);
                continue;
            }

            $shape[$path] = get_debug_type($value);
        }

        return $shape;
    }
}
