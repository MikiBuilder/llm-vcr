<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\Testing\InteractsWithLlm;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

#[CoversTrait(InteractsWithLlm::class)]
final class InteractsWithLlmTest extends TestCase
{
    use InteractsWithLlm;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/llm-vcr-trait-' . bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    protected function llmCassetteDir(): string
    {
        return $this->tmpDir;
    }

    /** @return list<array{role: string, content: string}> */
    private function mensajes(): array
    {
        return [
            ['role' => 'system', 'content' => 'Clasifica tickets en JSON.'],
            ['role' => 'user', 'content' => 'No puedo acceder a mi cuenta.'],
        ];
    }

    #[Test]
    public function montarUnaPlataformaEsUnaLinea(): void
    {
        $platform = $this->recordLlm(new InMemoryPlatform('{"ok":true}'), mode: Mode::Record);

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        self::assertSame('{"ok":true}', $result->text);
    }

    #[Test]
    public function elNombreDeCassettePorDefectoDerivaDelTest(): void
    {
        $platform = $this->recordLlm(new InMemoryPlatform('x'), mode: Mode::Record);

        self::assertSame('interacts-with-llm--el-nombre-de-cassette-por-defecto-deriva-del-test', $platform->pinnedCassette());
    }

    #[Test]
    public function sePuedeFijarElNombreDeCassette(): void
    {
        $platform = $this->recordLlm(new InMemoryPlatform('x'), cassette: 'Tickets Soporte', mode: Mode::Record);

        self::assertSame('tickets-soporte', $platform->pinnedCassette());

        $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        self::assertFileExists($this->tmpDir . '/tickets-soporte.json');
    }

    #[Test]
    public function porDefectoElModoEsReplayParaQueCiNoToqueLaRed(): void
    {
        $platform = $this->recordLlm(new InMemoryPlatform('x'));

        self::assertSame(Mode::Replay, $platform->mode());
    }

    #[Test]
    public function assertNoLiveLlmCallsPasaCuandoTodoVieneDeCassette(): void
    {
        $grabador = $this->recordLlm(new InMemoryPlatform('{"ok":true}'), cassette: 'demo', mode: Mode::Record);
        $grabador->invoke('llama-3.1-8b-instant', $this->mensajes());

        $platform = $this->recordLlm(new InMemoryPlatform('x'), cassette: 'demo', mode: Mode::Replay);
        $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->assertNoLiveLlmCalls();
        $this->assertLlmCallsWereReplayed(1);
    }

    #[Test]
    public function assertNoLiveLlmCallsFallaSiSeTocoLaRed(): void
    {
        $platform = $this->recordLlm(new InMemoryPlatform('x'), cassette: 'otra', mode: Mode::Record);
        $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/0 live calls/');

        $this->assertNoLiveLlmCalls();
    }

    #[Test]
    public function assertLlmJsonShapeValidaElContratoNoElValor(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"categoria":"acceso","urgencia":4,"meta":{"score":0.9}}'),
            cassette: 'shape',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->assertLlmJsonShape([
            'categoria' => 'string',
            'urgencia' => 'int',
            'meta.score' => 'float',
        ], $result);
    }

    #[Test]
    public function assertLlmJsonShapeDetectaUnCambioDeTipo(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"urgencia":"alta"}'),
            cassette: 'tipo',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/is string but int was expected/');

        $this->assertLlmJsonShape(['urgencia' => 'int'], $result);
    }

    #[Test]
    public function assertLlmJsonShapeAceptaTiposAlternativos(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"confianza":null}'),
            cassette: 'nullable',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->assertLlmJsonShape(['confianza' => 'float|null'], $result);
    }

    #[Test]
    public function assertLlmJsonShapeAvisaSiFaltaUnaClave(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"categoria":"acceso"}'),
            cassette: 'falta',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/Missing key "urgencia"/');

        $this->assertLlmJsonShape(['urgencia' => 'int'], $result);
    }

    #[Test]
    public function assertLlmJsonToleraBloquesDeCodigoMarkdown(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform("```json\n{\"categoria\":\"acceso\"}\n```"),
            cassette: 'markdown',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        self::assertSame(['categoria' => 'acceso'], $this->assertLlmJson($result));
    }

    #[Test]
    public function assertLlmValueInValidaUnDominioCerrado(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"sentimiento":"frustrado"}'),
            cassette: 'enum',
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', $this->mensajes());

        $this->assertLlmValueIn(['neutro', 'frustrado', 'enfadado'], 'sentimiento', $result);
    }
}
