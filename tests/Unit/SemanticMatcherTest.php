<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Matching\ExactMatcher;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SemanticMatcher::class)]
final class SemanticMatcherTest extends TestCase
{
    #[Test]
    public function textoIdenticoDaSimilitudMaxima(): void
    {
        $matcher = new SemanticMatcher();
        $text = 'Clasifica este ticket de soporte urgente';

        self::assertSame(1.0, $matcher->similarity($text, $text));
    }

    #[Test]
    public function textosSinRelacionDanSimilitudBaja(): void
    {
        $matcher = new SemanticMatcher();

        $score = $matcher->similarity(
            'Clasifica este ticket de soporte',
            'Traduce esta receta de tortilla',
        );

        self::assertLessThan(0.3, $score);
    }

    /**
     * El caso que justifica todo el proyecto: el prompt cambia en datos
     * volátiles pero significa exactamente lo mismo.
     */
    #[Test]
    #[DataProvider('promptsVolatiles')]
    public function toleraRuidoVolatil(string $original, string $variante): void
    {
        $matcher = new SemanticMatcher();

        self::assertGreaterThanOrEqual(
            $matcher->threshold(),
            $matcher->similarity($original, $variante),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function promptsVolatiles(): iterable
    {
        yield 'fecha distinta' => [
            'Analiza el pedido creado el 2026-01-15 del cliente premium',
            'Analiza el pedido creado el 2026-07-22 del cliente premium',
        ];

        yield 'uuid distinto' => [
            'Procesa la sesion 550e8400-e29b-41d4-a716-446655440000 del usuario',
            'Procesa la sesion 6ba7b810-9dad-11d1-80b4-00c04fd430c8 del usuario',
        ];

        yield 'id numerico distinto' => [
            'Resume la incidencia numero 998877 abierta por soporte tecnico',
            'Resume la incidencia numero 112233 abierta por soporte tecnico',
        ];

        yield 'espaciado distinto' => [
            'Clasifica    este   ticket',
            'Clasifica este ticket',
        ];
    }

    #[Test]
    public function normalizaTokensVolatilesAMarcadores(): void
    {
        $matcher = new SemanticMatcher();

        $normalized = $matcher->normalize('Pedido 123456 del 2026-07-22 a las 14:30:00');

        self::assertStringContainsString('<id>', $normalized);
        self::assertStringContainsString('<date>', $normalized);
        self::assertStringContainsString('<time>', $normalized);
    }

    #[Test]
    public function laSimilitudEsSimetrica(): void
    {
        $matcher = new SemanticMatcher();
        $a = 'Clasifica este ticket de facturacion';
        $b = 'Clasifica el ticket sobre facturacion mensual';

        self::assertEqualsWithDelta(
            $matcher->similarity($a, $b),
            $matcher->similarity($b, $a),
            0.0001,
        );
    }

    #[Test]
    public function laSimilitudSiempreEstaEnRangoValido(): void
    {
        $matcher = new SemanticMatcher();

        foreach ([['', ''], ['algo', ''], ['a', 'b'], ['hola mundo', 'hola mundo cruel']] as [$a, $b]) {
            $score = $matcher->similarity($a, $b);
            self::assertGreaterThanOrEqual(0.0, $score);
            self::assertLessThanOrEqual(1.0, $score);
        }
    }

    #[Test]
    public function rechazaUmbralInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SemanticMatcher(threshold: 1.5);
    }

    #[Test]
    public function elMatcherExactoNoToleraCambios(): void
    {
        $matcher = new ExactMatcher();

        self::assertSame(1.0, $matcher->similarity('hola  mundo', 'hola mundo'));
        self::assertSame(0.0, $matcher->similarity('pedido 111', 'pedido 222'));
    }
}
