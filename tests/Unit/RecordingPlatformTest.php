<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Exception\CassetteNotFoundException;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\RecordingPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingPlatform::class)]
final class RecordingPlatformTest extends TestCase
{
    private string $dir;

    /** @var list<array{role: string, content: string}> */
    private array $messages;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/llm-vcr-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->messages = [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte en JSON.'],
            ['role' => 'user', 'content' => 'No puedo acceder a mi cuenta desde ayer.'],
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    #[Test]
    public function enModoRecordLlamaAlProveedorYGrabaLaCassette(): void
    {
        $inner = new InMemoryPlatform('{"categoria":"acceso"}');
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Record);

        $result = $vcr->invoke('llama-3.1-8b-instant', $this->messages);

        self::assertSame('{"categoria":"acceso"}', $result->text);
        self::assertFalse($result->fromCassette);
        self::assertSame(1, $inner->callCount());
        self::assertCount(1, glob($this->dir . '/*.json') ?: []);
    }

    #[Test]
    public function laSegundaLlamadaSeSirveDeLaCassetteSinTocarElProveedor(): void
    {
        $inner = new InMemoryPlatform('{"categoria":"acceso"}');
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Record);

        $vcr->invoke('llama-3.1-8b-instant', $this->messages);
        $segunda = $vcr->invoke('llama-3.1-8b-instant', $this->messages);

        self::assertTrue($segunda->fromCassette);
        self::assertSame(1, $inner->callCount(), 'El proveedor solo debe llamarse una vez');
    }

    /**
     * El comportamiento que justifica el proyecto: en CI, cero red.
     */
    #[Test]
    public function enModoReplayNoTocaLaRedEnAbsoluto(): void
    {
        $grabador = new RecordingPlatform(
            new InMemoryPlatform('{"categoria":"acceso"}'),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', $this->messages);

        $inner = new InMemoryPlatform(static fn (): string => throw new \LogicException('¡No debe llamarse!'));
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Replay);

        $result = $vcr->invoke('llama-3.1-8b-instant', $this->messages);

        self::assertTrue($result->fromCassette);
        self::assertSame(0, $inner->callCount());
    }

    #[Test]
    public function enModoReplaySinCassetteFallaConMensajeUtil(): void
    {
        $vcr = new RecordingPlatform(new InMemoryPlatform('x'), $this->dir, Mode::Replay);

        $this->expectException(CassetteNotFoundException::class);
        $this->expectExceptionMessageMatches('/LLM_VCR_MODE=record/');

        $vcr->invoke('llama-3.1-8b-instant', $this->messages);
    }

    #[Test]
    public function reproduceAunqueElPromptCambieEnDatosVolatiles(): void
    {
        $grabador = new RecordingPlatform(
            new InMemoryPlatform('{"categoria":"acceso"}'),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte en JSON.'],
            ['role' => 'user', 'content' => 'Incidencia 445566 del 2026-01-10: no puedo acceder a mi cuenta.'],
        ]);

        $inner = new InMemoryPlatform('OTRA RESPUESTA');
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Replay);

        $result = $vcr->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte en JSON.'],
            ['role' => 'user', 'content' => 'Incidencia 998877 del 2026-07-25: no puedo acceder a mi cuenta.'],
        ]);

        self::assertSame('{"categoria":"acceso"}', $result->text);
        self::assertSame(0, $inner->callCount());
    }

    #[Test]
    public function noConfundeModelosDistintos(): void
    {
        $grabador = new RecordingPlatform(
            new InMemoryPlatform('respuesta del modelo pequeno'),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', $this->messages);

        $vcr = new RecordingPlatform(new InMemoryPlatform('x'), $this->dir, Mode::Replay);

        $this->expectException(CassetteNotFoundException::class);

        $vcr->invoke('llama-3.3-70b-versatile', $this->messages);
    }

    #[Test]
    public function elModoBypassIgnoraLasCassettes(): void
    {
        $grabador = new RecordingPlatform(
            new InMemoryPlatform('respuesta vieja'),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', $this->messages);

        $inner = new InMemoryPlatform('respuesta nueva');
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Bypass);

        $result = $vcr->invoke('llama-3.1-8b-instant', $this->messages);

        self::assertSame('respuesta nueva', $result->text);
        self::assertFalse($result->fromCassette);
        self::assertSame(1, $inner->callCount());
    }

    #[Test]
    public function laCassetteNoContieneSecretos(): void
    {
        $vcr = new RecordingPlatform(new InMemoryPlatform('ok'), $this->dir, Mode::Record);

        $vcr->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets.'],
            ['role' => 'user', 'content' => 'Soy usuario@example.com, telefono 611223344, clave sk-proj-AbCdEf0123456789XyZ'],
        ]);

        $ficheros = glob($this->dir . '/*.json') ?: [];
        $contenido = file_get_contents($ficheros[0]);

        self::assertIsString($contenido);
        self::assertStringNotContainsString('usuario@example.com', $contenido);
        self::assertStringNotContainsString('611223344', $contenido);
        self::assertStringNotContainsString('sk-proj-AbCdEf0123456789XyZ', $contenido);
        self::assertStringContainsString('<REDACTED:', $contenido);
    }

    /**
     * Regresión: la cassette guarda el texto redactado, así que el matching
     * debe hacerse también sobre texto redactado. Si se compara el prompt en
     * crudo contra la cassette saneada, un email real nunca casa con
     * <REDACTED:EMAIL> y falla justo en las peticiones que contienen PII.
     */
    #[Test]
    public function reproduceCorrectamenteCuandoElPromptContienePii(): void
    {
        $conPii = [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte en JSON.'],
            ['role' => 'user', 'content' => 'Soy usuario@example.com, telefono 611223344, no puedo acceder.'],
        ];

        $grabador = new RecordingPlatform(
            new InMemoryPlatform('{"categoria":"acceso"}'),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', $conPii);

        $inner = new InMemoryPlatform(static fn (): string => throw new \LogicException('¡No debe llamarse!'));
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Replay);

        $result = $vcr->invoke('llama-3.1-8b-instant', $conPii);

        self::assertTrue($result->fromCassette);
        self::assertSame('{"categoria":"acceso"}', $result->text);
        self::assertSame(0, $inner->callCount());
    }

    #[Test]
    public function alProveedorSeLeEnvianLosMensajesSinRedactar(): void
    {
        $inner = new InMemoryPlatform('ok');
        $vcr = new RecordingPlatform($inner, $this->dir, Mode::Record);

        $vcr->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets.'],
            ['role' => 'user', 'content' => 'Mi email es real@example.com'],
        ]);

        $recibido = $inner->received()[0]['messages'][1]['content'];

        self::assertSame(
            'Mi email es real@example.com',
            $recibido,
            'La redacción protege el fichero en disco, no debe degradar la petición al modelo',
        );
    }

    #[Test]
    public function lasEstadisticasReflejanElAhorro(): void
    {
        $grabador = new RecordingPlatform(
            new InMemoryPlatform('{"ok":true}', simulatedLatencyMs: 5.0),
            $this->dir,
            Mode::Record,
        );
        $grabador->invoke('llama-3.1-8b-instant', $this->messages);

        $vcr = new RecordingPlatform(new InMemoryPlatform('x'), $this->dir, Mode::Replay);
        $vcr->invoke('llama-3.1-8b-instant', $this->messages);
        $vcr->invoke('llama-3.1-8b-instant', $this->messages);

        $stats = $vcr->stats();

        self::assertSame(0, $stats['live']);
        self::assertSame(2, $stats['replayed']);
        self::assertSame(1.0, $stats['hit_rate']);
        self::assertGreaterThan(0, $stats['tokens_saved']);
    }

    #[Test]
    public function agrupaPorPromptDeSistemaEnCassettesDistintas(): void
    {
        $vcr = new RecordingPlatform(new InMemoryPlatform('ok'), $this->dir, Mode::Record);

        $vcr->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasificador de tickets'],
            ['role' => 'user', 'content' => 'hola'],
        ]);
        $vcr->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Traductor al catalan'],
            ['role' => 'user', 'content' => 'hola'],
        ]);

        self::assertCount(2, glob($this->dir . '/*.json') ?: []);
    }

    #[Test]
    public function elNombreDeCassetteEsLegibleYEstable(): void
    {
        $nombre = RecordingPlatform::cassetteName('llama-3.1-8b-instant', $this->messages);

        self::assertStringStartsWith('clasifica-tickets-de-soporte-en-json', $nombre);
        self::assertSame($nombre, RecordingPlatform::cassetteName('llama-3.1-8b-instant', $this->messages));
    }
}
