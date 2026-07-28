<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit;

use MikiBuilder\LlmVcr\Redaction\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase
{
    /**
     * Test de seguridad: cada uno de estos secretos, si se escapa a una
     * cassette commiteada, es un incidente real.
     */
    #[Test]
    #[DataProvider('secretos')]
    public function redactaSecretos(string $entrada, string $noDebeAparecer, string $marcador): void
    {
        $resultado = (new Redactor())->redact($entrada);

        self::assertStringNotContainsString($noDebeAparecer, $resultado);
        self::assertStringContainsString($marcador, $resultado);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function secretos(): iterable
    {
        yield 'api key openai' => [
            'Usa la clave sk-proj-AbCdEf0123456789XyZwQrSt para autenticar',
            'sk-proj-AbCdEf0123456789XyZwQrSt',
            '<REDACTED:API_KEY>',
        ];

        yield 'groq key' => [
            'export GROQ_API_KEY=gsk_aB3dEf5hIj7kLm9nOp1qRs3tUv5w',
            'gsk_aB3dEf5hIj7kLm9nOp1qRs3tUv5w',
            '<REDACTED:GROQ_KEY>',
        ];

        yield 'jwt' => [
            'Authorization eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9abcdef',
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            '<REDACTED:JWT>',
        ];

        yield 'github token' => [
            'token ghp_1234567890abcdefghijklmnopqrstuvwx',
            'ghp_1234567890abcdefghijklmnopqrstuvwx',
            '<REDACTED:GITHUB_TOKEN>',
        ];

        yield 'aws key' => [
            'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE',
            'AKIAIOSFODNN7EXAMPLE',
            '<REDACTED:AWS_KEY>',
        ];

        yield 'email' => [
            'Mi correo es usuario@example.com para contacto',
            'usuario@example.com',
            '<REDACTED:EMAIL>',
        ];

        yield 'telefono espanol' => [
            'Llamame al 611223344 por la manana',
            '611223344',
            '<REDACTED:PHONE>',
        ];

        yield 'dni' => [
            'El DNI del titular es 12345678Z',
            '12345678Z',
            '<REDACTED:DNI>',
        ];

        yield 'iban' => [
            'Transferencia a ES9121000418450200051332',
            'ES9121000418450200051332',
            '<REDACTED:IBAN>',
        ];
    }

    #[Test]
    public function elModoSoloCredencialesDejaPasarElPii(): void
    {
        $redactor = Redactor::credentialsOnly();

        $resultado = $redactor->redact('Contacto: test@example.com con clave sk-proj-AbCdEf0123456789XyZ');

        self::assertStringContainsString('test@example.com', $resultado);
        self::assertStringContainsString('<REDACTED:API_KEY>', $resultado);
    }

    #[Test]
    public function redactaDentroDeLosMensajes(): void
    {
        $redactor = new Redactor();

        $mensajes = $redactor->redactMessages([
            ['role' => 'system', 'content' => 'Eres un asistente'],
            ['role' => 'user', 'content' => 'Mi email es alguien@dominio.es'],
        ]);

        self::assertSame('Eres un asistente', $mensajes[0]['content']);
        self::assertStringNotContainsString('alguien@dominio.es', $mensajes[1]['content']);
    }

    #[Test]
    public function aceptaReglasPersonalizadas(): void
    {
        $redactor = new Redactor(customRules: ['/\bEXP-\d{4}\b/' => '<REDACTED:EXPEDIENTE>']);

        self::assertSame(
            'Expediente <REDACTED:EXPEDIENTE> archivado',
            $redactor->redact('Expediente EXP-2026 archivado'),
        );
    }

    #[Test]
    public function textoSinSecretosNoSeAltera(): void
    {
        $limpio = 'Clasifica este ticket de soporte segun su urgencia';

        self::assertSame($limpio, (new Redactor())->redact($limpio));
    }
}
