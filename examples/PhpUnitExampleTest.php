<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Examples;

use MikiBuilder\LlmVcr\Matching\PlaceholderMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\Testing\InteractsWithLlm;
use PHPUnit\Framework\TestCase;

/**
 * Ejemplo de uso con PHPUnit — cópialo a tu proyecto.
 *
 * En un caso real, `new InMemoryPlatform(...)` sería
 * `GroqPlatform::fromEnv()` o tu cliente LLM.
 */
final class PhpUnitExampleTest extends TestCase
{
    use InteractsWithLlm;

    private const SYSTEM = 'Clasifica tickets de soporte. Responde solo JSON: '
        . '{"categoria": string, "sentimiento": string, "urgencia": int}';

    public function testClasificaUnProblemaDeAcceso(): void
    {
        // Una línea para montar la plataforma. Las cassettes van a
        // examples/cassettes/ y el nombre sale del propio test.
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"categoria":"acceso","sentimiento":"frustrado","urgencia":4}'),
            mode: Mode::Record,
        );

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => 'No puedo acceder a mi cuenta desde ayer.'],
        ]);

        // Se valida el CONTRATO, no el valor exacto: los valores de un LLM
        // no son deterministas, pero la forma del JSON sí debe serlo.
        $this->assertLlmJsonShape([
            'categoria' => 'string',
            'sentimiento' => 'string',
            'urgencia' => 'int',
        ], $result);

        $this->assertLlmValueIn(['acceso', 'facturacion', 'incidencia'], 'categoria', $result);
    }

    public function testLaSuiteNoTocaLaRed(): void
    {
        $platform = $this->recordLlm(
            new InMemoryPlatform('{"categoria":"acceso","sentimiento":"neutro","urgencia":3}'),
            cassette: 'acceso-basico',
            mode: Mode::Record,
        );

        $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => 'No puedo entrar en mi cuenta.'],
        ]);

        // Segunda pasada: ya existe la cassette, así que se sirve de disco.
        $replay = $this->recordLlm(
            new InMemoryPlatform(static fn () => throw new \LogicException('no debe llamarse')),
            cassette: 'acceso-basico',
            mode: Mode::Replay,
        );

        $result = $replay->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => 'No puedo entrar en mi cuenta.'],
        ]);

        $this->assertNoLiveLlmCalls();
        $this->assertResultCameFromCassette($result);
    }

    /**
     * Prompts con parámetros dinámicos: declaras qué es variable y el
     * matcher lo sustituye en ambos lados antes de comparar.
     */
    public function testPromptsConParametrosDinamicos(): void
    {
        $matcher = new PlaceholderMatcher([
            'order_id' => '/PED-\d+/',
            'importe' => '/\d+,\d{2} ?€/',
        ]);

        $grabar = $this->recordLlm(
            new InMemoryPlatform('{"estado":"revisado"}'),
            cassette: 'pedidos',
            mode: Mode::Record,
            matcher: $matcher,
        );

        $grabar->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Revisa pedidos.'],
            ['role' => 'user', 'content' => 'Revisa el pedido PED-4417 por 89,90 €'],
        ]);

        // Otro pedido, otro importe: casa igual porque son placeholders.
        $reproducir = $this->recordLlm(
            new InMemoryPlatform(static fn () => throw new \LogicException('no debe llamarse')),
            cassette: 'pedidos',
            mode: Mode::Replay,
            matcher: $matcher,
        );

        $result = $reproducir->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Revisa pedidos.'],
            ['role' => 'user', 'content' => 'Revisa el pedido PED-9902 por 12,50 €'],
        ]);

        $this->assertNoLiveLlmCalls();
        $this->assertLlmJsonShape(['estado' => 'string'], $result);
    }
}
