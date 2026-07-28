<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Matching\PlaceholderMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceholderMatcher::class)]
final class PlaceholderMatcherTest extends TestCase
{
    #[Test]
    public function sustituyeLosParametrosDinamicosDeclarados(): void
    {
        $matcher = new PlaceholderMatcher(['order_id' => '/PED-\d+/']);

        self::assertSame(
            'revisa el pedido {{order_id}}',
            $matcher->normalize('revisa el pedido PED-4417'),
        );
    }

    #[Test]
    public function dosPromptsQueSoloDifierenEnLosParametrosCasanExactamente(): void
    {
        $matcher = new PlaceholderMatcher([
            'order_id' => '/PED-\d+/',
            'importe' => '/\d+,\d{2} ?€/',
        ]);

        self::assertSame(1.0, $matcher->similarity(
            'Revisa el pedido PED-4417 por 89,90 €',
            'Revisa el pedido PED-9902 por 12,50 €',
        ));
    }

    /**
     * Diferencia clave con el fuzzy: cero falsos positivos.
     */
    #[Test]
    public function noCasaSiCambiaAlgoNoDeclaradoComoParametro(): void
    {
        $matcher = new PlaceholderMatcher(['order_id' => '/PED-\d+/']);

        self::assertSame(0.0, $matcher->similarity(
            'Revisa el pedido PED-4417',
            'Cancela el pedido PED-4417',
        ));
    }

    #[Test]
    public function aceptaPatronesConYSinDelimitadores(): void
    {
        $conDelimitadores = new PlaceholderMatcher(['id' => '/\d+/'], withCommonDefaults: false);
        $sinDelimitadores = new PlaceholderMatcher(['id' => '\d+'], withCommonDefaults: false);

        self::assertSame(
            $conDelimitadores->normalize('pedido 4417'),
            $sinDelimitadores->normalize('pedido 4417'),
        );
    }

    #[Test]
    public function traeFechasYUuidsPorDefecto(): void
    {
        $matcher = new PlaceholderMatcher();

        $normalizado = $matcher->normalize(
            'Sesion 550e8400-e29b-41d4-a716-446655440000 del 2026-07-22 a las 14:30:00',
        );

        self::assertStringContainsString('{{uuid}}', $normalizado);
        self::assertStringContainsString('{{date}}', $normalizado);
        self::assertStringContainsString('{{time}}', $normalizado);
    }

    #[Test]
    public function losDefectosSePuedenDesactivar(): void
    {
        $matcher = new PlaceholderMatcher([], withCommonDefaults: false);

        self::assertSame('pedido del 2026-07-22', $matcher->normalize('pedido del 2026-07-22'));
    }

    #[Test]
    public function losPlaceholdersDelUsuarioTienenPrioridad(): void
    {
        $matcher = new PlaceholderMatcher(['fecha_pedido' => '/\d{4}-\d{2}-\d{2}/']);

        $normalizado = $matcher->normalize('entrega 2026-07-22');

        self::assertStringContainsString('{{fecha_pedido}}', $normalizado);
        self::assertStringNotContainsString('{{date}}', $normalizado);
    }

    #[Test]
    public function normalizaLosEspaciosSobrantes(): void
    {
        $matcher = new PlaceholderMatcher();

        self::assertSame('hola mundo', $matcher->normalize("hola   \n  mundo  "));
    }

    #[Test]
    public function elUmbralEsExacto(): void
    {
        self::assertSame(1.0, (new PlaceholderMatcher())->threshold());
    }

    #[Test]
    public function rechazaUnPatronInvalido(): void
    {
        $matcher = new PlaceholderMatcher(['malo' => '/[sin-cerrar/'], withCommonDefaults: false);

        $this->expectException(\InvalidArgumentException::class);

        @$matcher->normalize('cualquier cosa');
    }
}
