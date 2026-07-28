<?php

declare(strict_types=1);

/**
 * Integración con Pest.
 *
 * Se autocarga vía la sección "files" de composer.json: instalar el paquete
 * basta para tener las expectativas disponibles.
 *
 *   it('clasifica un ticket de acceso', function () {
 *       $platform = recordLlm(new GroqPlatform($key), cassette: 'tickets');
 *
 *       $result = $platform->invoke('llama-3.1-8b-instant', [...]);
 *
 *       expect($platform)->toHaveMadeNoLiveCalls();
 *       expect($result)->toBeLlmJson()
 *                      ->toMatchLlmShape(['categoria' => 'string', 'urgencia' => 'int']);
 *   });
 *
 * NOTA DE IMPLEMENTACIÓN (esto costó un bug real):
 * el registro NO puede protegerse comprobando si existe la FUNCIÓN global
 * expect(). Composer carga los ficheros de "files" en orden de dependencias
 * y este paquete no depende de Pest, así que se ejecuta ANTES de que las
 * funciones globales de Pest estén definidas: la guarda daría falso y las
 * expectativas no se registrarían jamás, en silencio.
 *
 * La solución es `class_exists()`, que dispara el autoloader y por tanto
 * funciona sea cual sea el orden. Ojo con la API: en Pest 3 `hasExtend()`
 * es estático pero `extend()` es de instancia, así que hace falta un
 * Expectation construido para registrar (es lo que `expect()` devuelve).
 */

namespace MikiBuilder\LlmVcr\Testing;

use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Contracts\Result;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;

if (!function_exists('MikiBuilder\LlmVcr\Testing\recordLlm')) {
    /**
     * Envuelve una plataforma con grabación/reproducción dentro de un test Pest.
     *
     * Las cassettes van a <directorio-del-test>/cassettes/, así que no hay
     * rutas que configurar.
     */
    function recordLlm(
        PlatformInterface $inner,
        ?string $cassette = null,
        ?Mode $mode = null,
        ?MatcherInterface $matcher = null,
        ?Redactor $redactor = null,
        ?string $cassetteDir = null,
    ): RecordingPlatform {
        $platform = new RecordingPlatform(
            inner: $inner,
            cassetteDir: $cassetteDir ?? PestSupport::cassetteDirForCurrentTest(),
            mode: $mode ?? Mode::fromEnv(default: Mode::Replay),
            matcher: $matcher,
            redactor: $redactor,
        );

        return $platform->useCassette($cassette ?? PestSupport::cassetteNameForCurrentTest());
    }
}

// class_exists() dispara el autoloader: funciona con cualquier orden de carga.
if (!class_exists(\Pest\Expectation::class)) {
    return;
}

if (\Pest\Expectation::hasExtend('toBeLlmJson')) {
    return; // ya registradas
}

// extend() es un método de instancia en Pest 3; este objeto es solo el
// vehículo para registrar. El valor que reciba es irrelevante.
$registry = new \Pest\Expectation(null);

$registry->extend('toBeLlmJson', function () {
    PestSupport::assertIsJson(PestSupport::resultFrom($this->value, 'toBeLlmJson'));

    return $this;
});

$registry->extend('toMatchLlmShape', function (array $shape) {
    PestSupport::assertShape(
        PestSupport::resultFrom($this->value, 'toMatchLlmShape'),
        $shape,
    );

    return $this;
});

$registry->extend('toComeFromCassette', function () {
    PestSupport::assertFromCassette(
        PestSupport::resultFrom($this->value, 'toComeFromCassette'),
    );

    return $this;
});

$registry->extend('toHaveMadeNoLiveCalls', function () {
    PestSupport::assertNoLiveCalls(
        PestSupport::platformFrom($this->value, 'toHaveMadeNoLiveCalls'),
    );

    return $this;
});

$registry->extend('toHaveReplayed', function (int $expected) {
    PestSupport::assertReplayed(
        PestSupport::platformFrom($this->value, 'toHaveReplayed'),
        $expected,
    );

    return $this;
});

$registry->extend('toHaveLlmValueIn', function (array $allowed, string $path) {
    PestSupport::assertValueIn(
        PestSupport::resultFrom($this->value, 'toHaveLlmValueIn'),
        $allowed,
        $path,
    );

    return $this;
});
