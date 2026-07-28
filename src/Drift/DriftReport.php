<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Drift;

/**
 * Resultado del análisis de deriva de una interacción.
 */
final readonly class DriftReport
{
    /** @param list<string> $structuralDiff */
    public function __construct(
        public string $model,
        public string $fingerprint,
        public string $recorded,
        public string $current,
        public float $similarity,
        public array $structuralDiff,
        public bool $drifted,
    ) {
    }

    public function severity(): Severity
    {
        if ($this->structuralDiff !== []) {
            return Severity::Critical;
        }

        if ($this->similarity < 0.6) {
            return Severity::High;
        }

        if ($this->drifted) {
            return Severity::Medium;
        }

        return Severity::Ok;
    }

    public function summary(): string
    {
        return $this->structuralDiff === []
            ? 'sin cambios de esquema'
            : implode(' | ', $this->structuralDiff);
    }
}
