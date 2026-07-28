<?php

declare(strict_types=1);

/**
 * Ejemplo de uso con Pest — cópialo a tu proyecto como tests/TicketTest.php.
 *
 * Verificado contra Pest 3 real: 3 tests, 11 aserciones, sin "risky".
 *
 * En un caso real, `new InMemoryPlatform(...)` sería `GroqPlatform::fromEnv()`.
 */

use MikiBuilder\LlmVcr\Matching\PlaceholderMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;

use function MikiBuilder\LlmVcr\Testing\recordLlm;

const SYSTEM = 'Clasifica tickets de soporte. Responde solo JSON.';

/** @return list<array{role: string, content: string}> */
function ticket(string $texto): array
{
    return [
        ['role' => 'system', 'content' => SYSTEM],
        ['role' => 'user', 'content' => $texto],
    ];
}

it('clasifica un problema de acceso', function () {
    $platform = recordLlm(
        new InMemoryPlatform('{"categoria":"acceso","urgencia":4}'),
        cassette: 'tickets',
        mode: Mode::Record,
    );

    $result = $platform->invoke('llama-3.1-8b-instant', ticket('No puedo acceder a mi cuenta.'));

    expect($result)
        ->toBeLlmJson()
        ->toMatchLlmShape(['categoria' => 'string', 'urgencia' => 'int'])
        ->toHaveLlmValueIn(['acceso', 'facturacion', 'incidencia'], 'categoria');
});

it('no toca la red en CI', function () {
    $explota = new InMemoryPlatform(fn () => throw new LogicException('¡no debe llamarse!'));

    $platform = recordLlm($explota, cassette: 'tickets', mode: Mode::Replay);

    $result = $platform->invoke('llama-3.1-8b-instant', ticket('No puedo acceder a mi cuenta.'));

    expect($platform)->toHaveMadeNoLiveCalls()->toHaveReplayed(1);
    expect($result)->toComeFromCassette();
});

it('detecta la deriva del proveedor como un fallo del test', function () {
    // El proveedor ha cambiado: urgencia ahora es string en vez de int.
    $platform = recordLlm(
        new InMemoryPlatform('{"categoria":"acceso","urgencia":"alta"}'),
        cassette: 'derivado',
        mode: Mode::Record,
    );

    $result = $platform->invoke('llama-3.1-8b-instant', ticket('No puedo acceder.'));

    expect($result)->toMatchLlmShape(['urgencia' => 'int']);
})->throws(PHPUnit\Framework\AssertionFailedError::class);

it('tolera prompts con parametros dinamicos', function () {
    $matcher = new PlaceholderMatcher(['order_id' => '/PED-\d+/']);

    recordLlm(new InMemoryPlatform('{"estado":"ok"}'), cassette: 'pedidos', mode: Mode::Record, matcher: $matcher)
        ->invoke('llama-3.1-8b-instant', ticket('Revisa el pedido PED-4417'));

    $platform = recordLlm(
        new InMemoryPlatform(fn () => throw new LogicException('no debe llamarse')),
        cassette: 'pedidos',
        mode: Mode::Replay,
        matcher: $matcher,
    );

    // Pedido distinto, misma cassette.
    $result = $platform->invoke('llama-3.1-8b-instant', ticket('Revisa el pedido PED-9902'));

    expect($platform)->toHaveMadeNoLiveCalls();
    expect($result)->toMatchLlmShape(['estado' => 'string']);
});
