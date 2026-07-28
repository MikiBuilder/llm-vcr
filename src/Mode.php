<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr;

/**
 * Modos de operación del grabador.
 *
 * Un enum en vez de constantes string: PHP valida en tiempo de compilación
 * y el IDE autocompleta. Nadie puede pasar "recod" por error.
 */
enum Mode: string
{
    /** Graba si no existe; si existe, reproduce. Desarrollo local. */
    case Record = 'record';

    /** Solo reproduce. Si falta, falla explícitamente. CI. */
    case Replay = 'replay';

    /** Ignora cassettes, siempre API real. Depuración. */
    case Bypass = 'bypass';

    /** Regraba todo desde cero. Actualizar fixtures. */
    case Refresh = 'refresh';

    /**
     * Lee el modo de una variable de entorno, con fallback seguro.
     */
    public static function fromEnv(string $variable = 'LLM_VCR_MODE', self $default = self::Record): self
    {
        $value = getenv($variable);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return self::tryFrom(strtolower(trim($value))) ?? $default;
    }

    public function readsFromCassette(): bool
    {
        return match ($this) {
            self::Record, self::Replay => true,
            self::Bypass, self::Refresh => false,
        };
    }

    public function canHitNetwork(): bool
    {
        return $this !== self::Replay;
    }
}
