<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Testing;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;
use PHPUnit\Framework\Assert;

/**
 * Trait de conveniencia para PHPUnit.
 *
 * Objetivo: que montar un test con LLM sea UNA línea, y que las aserciones
 * hablen el idioma del problema ("no ha tocado la red", "esto es JSON con
 * esta forma") en vez de obligarte a escribir plomería.
 *
 *   final class TicketTest extends TestCase
 *   {
 *       use InteractsWithLlm;
 *
 *       public function testClasifica(): void
 *       {
 *           $platform = $this->recordLlm(new GroqPlatform($key));
 *
 *           $result = $platform->invoke('llama-3.1-8b-instant', [...]);
 *
 *           $this->assertNoLiveLlmCalls();
 *           $this->assertLlmJsonShape(['categoria' => 'string'], $result);
 *       }
 *   }
 *
 * El directorio de cassettes se deriva del propio test, así que no hay que
 * configurar rutas: tests/Feature/TicketTest.php → tests/Feature/cassettes/.
 */
trait InteractsWithLlm
{
    private ?RecordingPlatform $llmPlatform = null;

    /**
     * Envuelve una plataforma real con grabación/reproducción.
     *
     * Por defecto usa el modo de LLM_VCR_MODE, con `replay` como fallback:
     * lo seguro es que CI nunca toque la red por accidente.
     */
    protected function recordLlm(
        PlatformInterface $inner,
        ?string $cassette = null,
        ?Mode $mode = null,
        ?MatcherInterface $matcher = null,
        ?Redactor $redactor = null,
    ): RecordingPlatform {
        $platform = new RecordingPlatform(
            inner: $inner,
            cassetteDir: $this->llmCassetteDir(),
            mode: $mode ?? Mode::fromEnv(default: Mode::Replay),
            matcher: $matcher,
            redactor: $redactor,
        );

        $platform->useCassette($cassette ?? $this->llmDefaultCassetteName());

        $this->llmPlatform = $platform;

        return $platform;
    }

    /**
     * Directorio de cassettes: junto al fichero de test que las usa.
     *
     * Sobrescribe este método si prefieres centralizarlas.
     */
    protected function llmCassetteDir(): string
    {
        $file = (new \ReflectionClass($this))->getFileName();

        if ($file === false) {
            return getcwd() . '/cassettes';
        }

        return dirname($file) . '/cassettes';
    }

    /**
     * Nombre por defecto: "clase-metodo", legible en el árbol de ficheros.
     */
    protected function llmDefaultCassetteName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        $class = preg_replace('/Test$/', '', $class) ?? $class;

        // El trait no sabe en qué TestCase acabará: se consulta name() por
        // reflexión para no asumir una versión concreta de PHPUnit.
        $method = 'test';
        if ((new \ReflectionClass($this))->hasMethod('name')) {
            /** @var mixed $resolved */
            $resolved = (new \ReflectionMethod($this, 'name'))->invoke($this);
            $method = is_string($resolved) ? $resolved : 'test';
        }
        $method = preg_replace('/^test/', '', $method) ?? $method;

        // El separador se aplica DESPUÉS de normalizar cada parte: si se
        // concatenara antes, useCassette() colapsaría "--" en "-" y el
        // nombre perdería la frontera visual entre clase y método.
        return self::slugPart($class) . '--' . self::slugPart($method);
    }

    private static function slugPart(string $value): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;

        return trim(strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $spaced)), '-');
    }

    private function llmPlatformOrFail(): RecordingPlatform
    {
        if ($this->llmPlatform === null) {
            Assert::fail(
                'No hay ninguna plataforma LLM registrada. Llama primero a $this->recordLlm(...).',
            );
        }

        return $this->llmPlatform;
    }

    // ── Aserciones ──────────────────────────────────────────────────────

    /**
     * La aserción que justifica el paquete: este test no ha tocado la red.
     *
     * Ponla en tu suite y CI te avisará el día que alguien añada un test
     * que se escapa a la API real y empieza a quemar cuota.
     */
    protected function assertNoLiveLlmCalls(string $message = ''): void
    {
        $stats = $this->llmPlatformOrFail()->stats();

        Assert::assertSame(
            0,
            $stats['live'],
            $message !== '' ? $message : sprintf(
                'Se esperaban 0 llamadas reales al proveedor, pero hubo %d. '
                . 'Falta grabar una cassette o el prompt cambió demasiado.',
                $stats['live'],
            ),
        );
    }

    protected function assertLlmCallsWereReplayed(int $expected, string $message = ''): void
    {
        $stats = $this->llmPlatformOrFail()->stats();

        Assert::assertSame($expected, $stats['replayed'], $message);
    }

    protected function assertResultCameFromCassette(Result $result, string $message = ''): void
    {
        Assert::assertTrue(
            $result->fromCassette,
            $message !== '' ? $message : 'Se esperaba una respuesta servida desde la cassette.',
        );
    }

    /**
     * Comprueba que la respuesta es JSON válido y devuelve el array.
     *
     * @return array<mixed>
     */
    protected function assertLlmJson(Result $result, string $message = ''): array
    {
        $data = $result->asStructured();

        Assert::assertIsArray(
            $data,
            $message !== '' ? $message : sprintf(
                "La respuesta del modelo no es JSON válido:\n%s",
                mb_substr($result->text, 0, 300),
            ),
        );

        return $data;
    }

    /**
     * Valida la FORMA del JSON: claves presentes y tipo de cada una.
     *
     * Es la aserción correcta para structured outputs: no fijas el valor
     * (que es no determinista) sino el contrato (que sí debe serlo).
     *
     *   $this->assertLlmJsonShape([
     *       'categoria'   => 'string',
     *       'urgencia'    => 'int',
     *       'confianza'   => 'float|null',
     *   ], $result);
     *
     * @param array<string, string> $shape clave => tipo esperado (admite "a|b" y rutas "meta.score")
     */
    protected function assertLlmJsonShape(array $shape, Result $result, string $message = ''): void
    {
        $data = $this->assertLlmJson($result, $message);

        foreach ($shape as $path => $expectedTypes) {
            $value = self::valueAtPath($data, $path);

            Assert::assertNotSame(
                self::MISSING,
                $value,
                sprintf('Falta la clave "%s" en la respuesta del modelo.', $path),
            );

            $actual = get_debug_type($value);
            $allowed = array_map('trim', explode('|', $expectedTypes));

            Assert::assertContains(
                $actual,
                $allowed,
                sprintf(
                    'La clave "%s" es %s pero se esperaba %s. '
                    . 'Si el proveedor cambió el modelo, esto es deriva: revisa `llm-vcr drift`.',
                    $path,
                    $actual,
                    $expectedTypes,
                ),
            );
        }
    }

    /**
     * Comprueba que un campo está dentro de un conjunto cerrado de valores.
     *
     * Pensado para enums devueltos por el modelo, donde el valor exacto
     * puede variar pero el dominio no debería.
     *
     * @param list<scalar> $allowed
     */
    protected function assertLlmValueIn(array $allowed, string $path, Result $result): void
    {
        $data = $this->assertLlmJson($result);
        $value = self::valueAtPath($data, $path);

        Assert::assertNotSame(self::MISSING, $value, sprintf('Falta la clave "%s".', $path));

        Assert::assertContains(
            $value,
            $allowed,
            sprintf('El valor de "%s" no está entre los permitidos.', $path),
        );
    }

    private const MISSING = "\0__llm_vcr_missing__\0";

    /**
     * @param array<mixed> $data
     */
    private static function valueAtPath(array $data, string $path): mixed
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
}
