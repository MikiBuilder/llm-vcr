<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Testing;

use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Exception\LlmVcrException;
use MikiBuilder\LlmVcr\RecordingPlatform;

/**
 * Lógica de las expectativas de Pest.
 *
 * Vive en una clase aparte del fichero de registro para que sea testeable
 * con PHPUnit y analizable por PHPStan a nivel 9. El fichero Pest.php es
 * solo pegamento declarativo.
 *
 * @internal
 */
final class PestSupport
{
    public const MISSING = "\0__llm_vcr_missing__\0";

    // ── Resolución de contexto ──────────────────────────────────────────

    /**
     * Localiza el fichero de test en curso recorriendo la pila de llamadas.
     *
     * Pest ejecuta closures, así que no hay clase de la que tirar: el
     * fichero fuera de vendor/ que parece un test es la mejor señal.
     */
    public static function currentTestFile(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50) as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || str_contains($file, '/vendor/')) {
                continue;
            }

            if (str_ends_with(basename($file), 'Test.php') || preg_match('#/tests?/#i', $file) === 1) {
                return $file;
            }
        }

        return null;
    }

    public static function cassetteDirForCurrentTest(): string
    {
        $file = self::currentTestFile();

        return $file === null
            ? getcwd() . '/cassettes'
            : dirname($file) . '/cassettes';
    }

    /**
     * Nombre derivado del fichero del test: estable y localizable a simple
     * vista en el árbol de ficheros.
     */
    public static function cassetteNameForCurrentTest(): string
    {
        $file = self::currentTestFile();

        if ($file === null) {
            return 'default';
        }

        $base = preg_replace('/\.php$/', '', basename($file)) ?? 'default';
        $base = preg_replace('/Test$/', '', $base) ?? $base;
        $base = preg_replace('/(?<!^)[A-Z]/', '-$0', $base) ?? $base;

        $slug = trim(strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base)), '-');

        return $slug === '' ? 'default' : $slug;
    }

    // ── Coerción de tipos ───────────────────────────────────────────────

    public static function resultFrom(mixed $value, string $expectation): Result
    {
        if (!$value instanceof Result) {
            self::fail(sprintf(
                '%s() espera un %s, recibió %s.',
                $expectation,
                Result::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    public static function platformFrom(mixed $value, string $expectation): RecordingPlatform
    {
        if (!$value instanceof RecordingPlatform) {
            self::fail(sprintf(
                '%s() espera una %s, recibió %s.',
                $expectation,
                RecordingPlatform::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    // ── Aserciones ──────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    public static function assertIsJson(Result $result): array
    {
        $data = $result->asStructured();

        if ($data === null) {
            self::fail(sprintf(
                "La respuesta del modelo no es JSON válido:\n%s",
                mb_substr($result->text, 0, 300),
            ));
        }

        self::countAssertion();

        return $data;
    }

    /**
     * @param array<string, string> $shape clave => tipo (admite "a|b" y rutas "meta.score")
     */
    public static function assertShape(Result $result, array $shape): void
    {
        $data = self::assertIsJson($result);

        foreach ($shape as $path => $expectedTypes) {
            $value = self::valueAtPath($data, $path);

            if ($value === self::MISSING) {
                self::fail(sprintf('Falta la clave "%s" en la respuesta del modelo.', $path));
            }

            $actual = get_debug_type($value);
            $allowed = array_map('trim', explode('|', $expectedTypes));

            if (!in_array($actual, $allowed, true)) {
                self::fail(sprintf(
                    'La clave "%s" es %s pero se esperaba %s. '
                    . 'Si el proveedor cambió el modelo, esto es deriva: revisa `llm-vcr drift`.',
                    $path,
                    $actual,
                    $expectedTypes,
                ));
            }
        }

        self::countAssertion();
    }

    public static function assertFromCassette(Result $result): void
    {
        if (!$result->fromCassette) {
            self::fail('Se esperaba una respuesta servida desde la cassette, pero vino de la API.');
        }

        self::countAssertion();
    }

    public static function assertNoLiveCalls(RecordingPlatform $platform): void
    {
        $live = $platform->stats()['live'];

        if ($live !== 0) {
            self::fail(sprintf(
                'Se esperaban 0 llamadas reales al proveedor, pero hubo %d. '
                . 'Falta grabar una cassette o el prompt cambió demasiado.',
                $live,
            ));
        }

        self::countAssertion();
    }

    public static function assertReplayed(RecordingPlatform $platform, int $expected): void
    {
        $replayed = $platform->stats()['replayed'];

        if ($replayed !== $expected) {
            self::fail(sprintf(
                'Se esperaban %d reproducciones desde cassette, hubo %d.',
                $expected,
                $replayed,
            ));
        }

        self::countAssertion();
    }

    /**
     * @param list<scalar> $allowed
     */
    public static function assertValueIn(Result $result, array $allowed, string $path): void
    {
        $data = self::assertIsJson($result);
        $value = self::valueAtPath($data, $path);

        if ($value === self::MISSING) {
            self::fail(sprintf('Falta la clave "%s" en la respuesta del modelo.', $path));
        }

        if (!in_array($value, $allowed, true)) {
            self::fail(sprintf(
                'El valor de "%s" (%s) no está entre los permitidos: %s.',
                $path,
                var_export($value, true),
                implode(', ', array_map(static fn (mixed $v): string => var_export($v, true), $allowed)),
            ));
        }

        self::countAssertion();
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    /**
     * Registra una aserción satisfecha en el contador de PHPUnit.
     *
     * Sin esto, un test cuyas únicas comprobaciones son expectativas de
     * llm-vcr se marca como "risky: did not perform any assertions", que
     * es ruido y erosiona la confianza en la suite.
     */
    public static function countAssertion(): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertThat(true, \PHPUnit\Framework\Assert::isTrue());
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function valueAtPath(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return self::MISSING;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Falla el test.
     *
     * Usa la excepción de PHPUnit si está disponible (Pest corre sobre él,
     * así que el fallo se integra en el informe) y cae a la del paquete si no.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError|LlmVcrException
     */
    public static function fail(string $message): never
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::fail($message);
        }

        throw new LlmVcrException($message);
    }
}
