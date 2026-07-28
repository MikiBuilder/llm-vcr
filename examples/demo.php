<?php

declare(strict_types=1);

/**
 * Demo completa SIN API key y SIN red.
 *
 *   php examples/demo.php
 *
 * Usa una plataforma simulada para enseñar los seis comportamientos clave
 * del paquete de forma reproducible.
 */

require __DIR__ . '/../vendor/autoload.php';

use MikiBuilder\LlmVcr\Cassette\Cassette;
use MikiBuilder\LlmVcr\Drift\DriftDetector;
use MikiBuilder\LlmVcr\Exception\CassetteNotFoundException;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;

$dir = sys_get_temp_dir() . '/llm-vcr-demo';
if (!is_dir($dir)) {
    mkdir($dir, 0o775, true);
}
foreach (glob($dir . '/*.json') ?: [] as $f) {
    unlink($f);
}

function titulo(string $t): void
{
    echo "\n\033[1;36m" . str_repeat('═', 72) . "\033[0m\n";
    echo "\033[1;36m  " . $t . "\033[0m\n";
    echo "\033[1;36m" . str_repeat('═', 72) . "\033[0m\n";
}

function fila(string $k, string $v, string $c = '0'): void
{
    printf("  %-36s \033[%sm%s\033[0m\n", $k, $c, $v);
}

$system = 'Clasifica tickets de soporte y responde en JSON.';
$model = 'llama-3.1-8b-instant';

$guion = [
    'no puedo acceder' => '{"categoria":"acceso","sentimiento":"frustrado","urgencia":4}',
    'factura' => '{"categoria":"facturacion","sentimiento":"neutro","urgencia":2}',
    'caido' => '{"categoria":"incidencia","sentimiento":"enfadado","urgencia":5}',
];

$tickets = [
    'Hola, no puedo acceder a mi cuenta desde ayer. Mi email es usuario@example.com y mi telefono 611223344.',
    'Necesito una copia de la factura de marzo, gracias.',
    'El servicio esta caido y estamos perdiendo ventas. Urgente.',
];

/** @param list<string> $tickets */
$correr = static function (RecordingPlatform $vcr, array $tickets, string $system, string $model): float {
    $t0 = microtime(true);
    foreach ($tickets as $ticket) {
        $r = $vcr->invoke($model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $ticket],
        ]);
        $d = $r->asStructured();
        fila(
            mb_substr($ticket, 0, 30) . '…',
            sprintf('%s / urgencia %s', $d['categoria'] ?? '?', $d['urgencia'] ?? '?'),
            $r->fromCassette ? '0;32' : '0;33',
        );
    }

    return (microtime(true) - $t0) * 1000;
};

// ── 1 ───────────────────────────────────────────────────────────────────
titulo('1 · MODO RECORD — primera ejecución, golpea la API');

$vcr = new RecordingPlatform(new InMemoryPlatform($guion, simulatedLatencyMs: 820.0), $dir, Mode::Record);
$tRecord = $correr($vcr, $tickets, $system, $model);
$s = $vcr->stats();
echo "\n";
fila('Llamadas a la API', (string) $s['live'], '1;31');
fila('Tiempo total', sprintf('%.0f ms', $tRecord), '1;31');

// ── 2 ───────────────────────────────────────────────────────────────────
titulo('2 · MODO REPLAY — lo que corre en CI: sin red, sin API key');

$explota = new InMemoryPlatform(static fn (): string => throw new LogicException('¡La red no debe tocarse!'));
$vcr2 = new RecordingPlatform($explota, $dir, Mode::Replay);
$tReplay = $correr($vcr2, $tickets, $system, $model);
$s2 = $vcr2->stats();
echo "\n";
fila('Llamadas a la API', (string) $s2['live'], '1;32');
fila('Hit rate', sprintf('%.0f%%', $s2['hit_rate'] * 100), '1;32');
fila('Tiempo total', sprintf('%.2f ms', $tReplay), '1;32');
fila('Aceleración', sprintf('%.0f× más rápido', $tRecord / max($tReplay, 0.001)), '1;32');
fila('Tokens no gastados', (string) $s2['tokens_saved'], '1;32');

// ── 3 ───────────────────────────────────────────────────────────────────
titulo('3 · MATCHING SEMÁNTICO — el prompt cambia, la cassette aguanta');

$matcher = new SemanticMatcher();
$variantes = [
    'Hola, no puedo acceder a mi cuenta desde ayer. Mi email es otro@example.com y mi telefono 611222333.',
    'Buenas, no puedo acceder a mi cuenta desde el 2026-07-26. Ticket 998877.',
    'Quiero cancelar mi suscripcion mensual ahora mismo.',
];

foreach ($variantes as $v) {
    $sim = $matcher->similarity($tickets[0], $v);
    $vcr3 = new RecordingPlatform(new InMemoryPlatform('x'), $dir, Mode::Replay);
    try {
        $r = $vcr3->invoke($model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $v],
        ]);
        fila(sprintf('similitud %.2f', $sim), 'HIT → ' . mb_substr($r->text, 0, 38) . '…', '1;32');
    } catch (CassetteNotFoundException) {
        fila(sprintf('similitud %.2f', $sim), 'MISS → llamaría a la API', '1;31');
    }
}
echo "\n  \033[0;37mUn hash exacto (php-vcr) fallaría en los tres casos.\033[0m\n";

// ── 4 ───────────────────────────────────────────────────────────────────
titulo('4 · REDACCIÓN — las cassettes se commitean a git');

$fichero = (glob($dir . '/*.json') ?: [null])[0];
if ($fichero !== null) {
    $contenido = (string) file_get_contents($fichero);
    foreach (['usuario@example.com' => 'email', '611223344' => 'teléfono'] as $secreto => $tipo) {
        $filtrado = str_contains($contenido, (string) $secreto);
        fila('¿Se filtra el ' . $tipo . '?', $filtrado ? 'SÍ — FALLO' : 'NO — redactado', $filtrado ? '1;31' : '1;32');
    }
    fila('Marcadores <REDACTED:>', (string) substr_count($contenido, '<REDACTED:'), '1;32');
}

$r = new Redactor();
fila('Clave API', $r->redact('sk-proj-AbCdEf0123456789XyZwQ'), '0;32');
fila('Clave Groq', $r->redact('gsk_aB3dEf5hIj7kLm9nOp1qRs3tUv5w'), '0;32');

// ── 5 ───────────────────────────────────────────────────────────────────
titulo('5 · DETECCIÓN DE DERIVA — el proveedor cambia el modelo');

$derivado = [
    'no puedo acceder' => '{"categoria":"acceso","sentimiento":"frustrado","urgencia":"alta","confianza":0.91}',
    'factura' => '{"categoria":"facturacion","sentimiento":"neutro","urgencia":2}',
    'caido' => '{"categoria":"incidencia_critica","sentimiento":"muy_enfadado","urgencia":5}',
];

$detector = new DriftDetector(new InMemoryPlatform($derivado));
$conDeriva = 0;
$total = 0;

foreach (glob($dir . '/*.json') ?: [] as $f) {
    foreach ($detector->analyze(Cassette::load(basename($f, '.json'), $f)) as $rep) {
        ++$total;
        if ($rep->drifted) {
            ++$conDeriva;
        }
        $color = match ($rep->severity()->value) {
            'CRITICA' => '1;31',
            'ALTA' => '0;31',
            'MEDIA' => '1;33',
            default => '0;32',
        };
        fila(
            sprintf('%s %s (sim %.2f)', $rep->severity()->emoji(), $rep->severity()->value, $rep->similarity),
            $rep->summary(),
            $color,
        );
    }
}
echo "\n";
fila('Analizadas', (string) $total, '1;37');
fila('Con deriva', (string) $conDeriva, $conDeriva > 0 ? '1;31' : '1;32');
echo "\n  \033[0;37mEl cambio de tipo urgencia int→string rompe tu DTO\n";
echo "  sin que hayas tocado una sola línea de código.\033[0m\n";

// ── 6 ───────────────────────────────────────────────────────────────────
titulo('6 · REPLAY ESTRICTO — un prompt nuevo que nadie grabó');

try {
    (new RecordingPlatform(new InMemoryPlatform('x'), $dir, Mode::Replay))
        ->invoke($model, [
            ['role' => 'system', 'content' => 'Prompt jamás grabado'],
            ['role' => 'user', 'content' => 'hola'],
        ]);
    fila('Resultado', 'no lanzó excepción — MAL', '1;31');
} catch (CassetteNotFoundException $e) {
    fila('Excepción', 'CI falla de forma explícita ✓', '1;32');
    echo "\n\033[0;37m" . preg_replace('/^/m', '  ', $e->getMessage()) . "\033[0m\n";
}

echo "\n\033[1;32m" . str_repeat('═', 72) . "\033[0m\n";
echo "\033[1;32m  Demo completada — sin red, sin API key, sin gastar un céntimo\033[0m\n";
echo "\033[1;32m" . str_repeat('═', 72) . "\033[0m\n\n";
