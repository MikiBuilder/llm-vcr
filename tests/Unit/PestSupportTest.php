<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Testing\PestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * La lógica de las expectativas de Pest se testea aquí, con PHPUnit, para
 * no obligar a instalar Pest en el CI del propio paquete.
 */
#[CoversClass(PestSupport::class)]
final class PestSupportTest extends TestCase
{
    private function makeResult(string $text, bool $fromCassette = false): Result
    {
        return new Result(
            text: $text,
            model: 'llama-3.1-8b-instant',
            inputTokens: 10,
            outputTokens: 5,
            fromCassette: $fromCassette,
        );
    }

    /**
     * Blindaje del bug que encontramos: si el fichero Pest.php se protege
     * con function_exists('expect'), las expectativas NUNCA se registran,
     * porque Composer lo carga antes que las funciones globales de Pest.
     * La guarda correcta es class_exists() sobre una CLASE.
     */
    #[Test]
    public function elRegistroDePestNoDependeDelOrdenDeCarga(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Testing/Pest.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            'class_exists(\Pest\Expectation::class)',
            $source,
            'La guarda debe ser class_exists() sobre una clase: dispara el autoloader '
            . 'y funciona sea cual sea el orden de carga de Composer.',
        );
        self::assertStringNotContainsString(
            "function_exists('expect')",
            $source,
            'function_exists() falla silenciosamente si Composer carga este fichero antes que Pest.',
        );
    }

    #[Test]
    public function elFicheroDePestEstaRegistradoEnElAutoload(): void
    {
        /** @var array{autoload?: array{files?: list<string>}} $composer */
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertContains(
            'src/Testing/Pest.php',
            $composer['autoload']['files'] ?? [],
            'Sin esto, las expectativas de Pest no se cargarían nunca.',
        );
    }

    #[Test]
    public function validaLaFormaDelJson(): void
    {
        PestSupport::assertShape(
            $this->makeResult('{"categoria":"acceso","urgencia":4,"meta":{"score":0.9}}'),
            ['categoria' => 'string', 'urgencia' => 'int', 'meta.score' => 'float'],
        );

        self::assertGreaterThan(0, self::getCount(), 'assertShape debe contar su aserción');
    }

    #[Test]
    public function detectaUnCambioDeTipo(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/is string but int was expected/');

        PestSupport::assertShape($this->makeResult('{"urgencia":"alta"}'), ['urgencia' => 'int']);
    }

    #[Test]
    public function admiteTiposAlternativos(): void
    {
        PestSupport::assertShape($this->makeResult('{"confianza":null}'), ['confianza' => 'float|null']);

        self::assertGreaterThan(0, self::getCount());
    }

    #[Test]
    public function avisaSiFaltaUnaClave(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/Missing key "urgencia"/');

        PestSupport::assertShape($this->makeResult('{"categoria":"acceso"}'), ['urgencia' => 'int']);
    }

    #[Test]
    public function rechazaUnaRespuestaQueNoEsJson(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        PestSupport::assertIsJson($this->makeResult('Lo siento, no puedo ayudarte con eso.'));
    }

    #[Test]
    public function exigeQueLaRespuestaVengaDeLaCassette(): void
    {
        PestSupport::assertFromCassette($this->makeResult('{"ok":true}', fromCassette: true));

        $this->expectException(AssertionFailedError::class);
        PestSupport::assertFromCassette($this->makeResult('{"ok":true}'));
    }

    #[Test]
    public function detectaLlamadasRealesNoDeseadas(): void
    {
        $dir = sys_get_temp_dir() . '/llm-vcr-pest-' . bin2hex(random_bytes(4));
        $platform = new RecordingPlatform(new InMemoryPlatform('ok'), $dir, Mode::Record);
        $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'test'],
            ['role' => 'user', 'content' => 'hola'],
        ]);

        try {
            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessageMatches('/0 live calls/');

            PestSupport::assertNoLiveCalls($platform);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    #[Test]
    public function validaUnDominioCerradoDeValores(): void
    {
        PestSupport::assertValueIn(
            $this->makeResult('{"sentimiento":"frustrado"}'),
            ['neutro', 'frustrado'],
            'sentimiento',
        );

        $this->expectException(AssertionFailedError::class);
        PestSupport::assertValueIn(
            $this->makeResult('{"sentimiento":"eufórico"}'),
            ['neutro', 'frustrado'],
            'sentimiento',
        );
    }

    #[Test]
    public function rechazaUnTipoIncorrectoEnLaExpectativa(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/expects a .*Result, got string/');

        PestSupport::resultFrom('no soy un Result', 'toBeLlmJson');
    }

    #[Test]
    public function recorreRutasAnidadas(): void
    {
        $data = ['meta' => ['score' => 0.9]];

        self::assertSame(0.9, PestSupport::valueAtPath($data, 'meta.score'));
        self::assertSame(PestSupport::MISSING, PestSupport::valueAtPath($data, 'meta.inexistente'));
    }
}
