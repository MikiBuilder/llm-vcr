<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Drift;

enum Severity: string
{
    /** Cambió la FORMA del JSON: tu DTO va a petar. */
    case Critical = 'CRITICA';

    /** El contenido cambió radicalmente. */
    case High = 'ALTA';

    /** Deriva perceptible pero no estructural. */
    case Medium = 'MEDIA';

    case Ok = 'OK';

    /** Código de salida para CI: distinto de 0 rompe el build. */
    public function exitCode(): int
    {
        return match ($this) {
            self::Critical, self::High => 1,
            self::Medium, self::Ok => 0,
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::High => '🟠',
            self::Medium => '🟡',
            self::Ok => '🟢',
        };
    }
}
