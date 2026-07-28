<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Cassette\Cassette;
use MikiBuilder\LlmVcr\Cassette\Interaction;
use MikiBuilder\LlmVcr\Drift\DriftDetector;
use MikiBuilder\LlmVcr\Drift\Severity;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DriftDetector::class)]
final class DriftDetectorTest extends TestCase
{
    private function cassetteCon(string $respuestaGrabada): Cassette
    {
        $cassette = new Cassette('test', sys_get_temp_dir() . '/no-se-guarda.json');
        $cassette->add(new Interaction(
            model: 'llama-3.1-8b-instant',
            messages: [
                ['role' => 'system', 'content' => 'Clasifica tickets'],
                ['role' => 'user', 'content' => 'No puedo acceder'],
            ],
            options: [],
            response: $respuestaGrabada,
            inputTokens: 20,
            outputTokens: 15,
            latencyMs: 500.0,
            fingerprint: 'abc123',
        ));

        return $cassette;
    }

    #[Test]
    public function sinCambiosNoReportaDeriva(): void
    {
        $json = '{"categoria":"acceso","urgencia":4}';
        $detector = new DriftDetector(new InMemoryPlatform($json));

        $reports = $detector->analyze($this->cassetteCon($json));

        self::assertCount(1, $reports);
        self::assertFalse($reports[0]->drifted);
        self::assertSame(Severity::Ok, $reports[0]->severity());
    }

    /**
     * EL caso estrella: el proveedor cambia el tipo de un campo y tu DTO
     * tipado revienta en producción sin que hayas tocado nada.
     */
    #[Test]
    public function detectaCambioDeTipoComoCritico(): void
    {
        $detector = new DriftDetector(
            new InMemoryPlatform('{"categoria":"acceso","urgencia":"alta"}'),
        );

        $reports = $detector->analyze(
            $this->cassetteCon('{"categoria":"acceso","urgencia":4}'),
        );

        self::assertTrue($reports[0]->drifted);
        self::assertSame(Severity::Critical, $reports[0]->severity());
        self::assertStringContainsString('cambio de tipo en "urgencia": int -> string', $reports[0]->summary());
    }

    #[Test]
    public function detectaCampoNuevo(): void
    {
        $detector = new DriftDetector(
            new InMemoryPlatform('{"categoria":"acceso","urgencia":4,"confianza":0.91}'),
        );

        $reports = $detector->analyze(
            $this->cassetteCon('{"categoria":"acceso","urgencia":4}'),
        );

        self::assertSame(Severity::Critical, $reports[0]->severity());
        self::assertStringContainsString('campo nuevo: "confianza" (float)', $reports[0]->summary());
    }

    #[Test]
    public function detectaCampoEliminado(): void
    {
        $detector = new DriftDetector(new InMemoryPlatform('{"categoria":"acceso"}'));

        $reports = $detector->analyze(
            $this->cassetteCon('{"categoria":"acceso","urgencia":4}'),
        );

        self::assertStringContainsString('campo eliminado: "urgencia" (era int)', $reports[0]->summary());
    }

    #[Test]
    public function detectaCambiosEnJsonAnidado(): void
    {
        $diff = DriftDetector::structuralDiff(
            '{"meta":{"score":0.9,"tags":["a"]}}',
            '{"meta":{"score":"0.9","tags":["a"]}}',
        );

        self::assertContains('cambio de tipo en "meta.score": float -> string', $diff);
    }

    #[Test]
    public function conTextoPlanoNoJsonNoInventaDiferenciasEstructurales(): void
    {
        self::assertSame([], DriftDetector::structuralDiff('hola mundo', 'adios mundo'));
    }

    #[Test]
    public function textoMuyDistintoSeMarcaComoAlto(): void
    {
        $detector = new DriftDetector(
            new InMemoryPlatform('Lo siento, no puedo ayudarte con esa peticion concreta'),
        );

        $reports = $detector->analyze(
            $this->cassetteCon('El pedido fue enviado ayer por mensajeria urgente'),
        );

        self::assertTrue($reports[0]->drifted);
        self::assertSame(Severity::High, $reports[0]->severity());
    }

    #[Test]
    public function laSeveridadCriticaRompeElBuild(): void
    {
        self::assertSame(1, Severity::Critical->exitCode());
        self::assertSame(1, Severity::High->exitCode());
        self::assertSame(0, Severity::Ok->exitCode());
    }
}
