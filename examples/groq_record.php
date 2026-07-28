<?php

declare(strict_types=1);

/**
 * Graba cassettes reales contra Groq (free tier, sin tarjeta).
 *
 *   1. Consigue tu clave gratis en https://console.groq.com/keys
 *   2. cp .env.example .env  y  rellena GROQ_API_KEY
 *   3. make record     (o:  php examples/groq_record.php)
 *
 * La primera vez llama a la API. La segunda ya se sirve de la cassette.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_env.php';

use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\GroqPlatform;
use MikiBuilder\LlmVcr\RecordingPlatform;

loadDotEnv(__DIR__ . '/../.env');

$apiKey = getenv('GROQ_API_KEY');
if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDERR, <<<TXT

    ERROR: falta GROQ_API_KEY.

      1. Entra en https://console.groq.com/keys (gratis, sin tarjeta)
      2. cp .env.example .env
      3. Pega tu clave en el fichero .env

    Si solo quieres ver cómo funciona sin registrarte:
      php examples/demo.php

    TXT);
    exit(1);
}

$model = getenv('LLM_VCR_MODEL') ?: 'llama-3.1-8b-instant';
$cassetteDir = __DIR__ . '/../cassettes';

$platform = new RecordingPlatform(
    inner: new GroqPlatform($apiKey),
    cassetteDir: $cassetteDir,
    mode: Mode::fromEnv(default: Mode::Record),
);

$system = 'Eres un clasificador de tickets de soporte. Responde SOLO con JSON válido, '
    . 'sin texto adicional, con esta forma exacta: '
    . '{"categoria": string, "sentimiento": string, "urgencia": number}. '
    . 'La urgencia es un entero del 1 al 5.';

$tickets = [
    'Hola, no puedo acceder a mi cuenta desde ayer y necesito entrar urgentemente.',
    'Buenos días, ¿me podrían enviar una copia de la factura del mes de marzo? Gracias.',
    'El servicio lleva caído dos horas y estamos perdiendo ventas. Esto es inaceptable.',
];

echo "\n\033[1;36m  Grabando cassettes contra Groq (" . $model . ")\033[0m\n";
echo "  " . str_repeat('─', 62) . "\n\n";

$inicio = microtime(true);

foreach ($tickets as $i => $ticket) {
    printf("  \033[0;37m%d. %s\033[0m\n", $i + 1, mb_substr($ticket, 0, 54) . '…');

    try {
        $result = $platform->invoke($model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $ticket],
        ], ['temperature' => 0.0]);
    } catch (\RuntimeException $e) {
        printf("     \033[1;31m✗ %s\033[0m\n\n", $e->getMessage());
        continue;
    }

    $data = $result->asStructured();
    $origen = $result->fromCassette ? "\033[1;32mcassette\033[0m" : "\033[1;33mAPI real\033[0m";

    if ($data === null) {
        printf("     respuesta no-JSON (%s): %s\n\n", $origen, mb_substr($result->text, 0, 60));
        continue;
    }

    printf(
        "     → %s | %s | urgencia %s   [%s, %d tokens]\n\n",
        $data['categoria'] ?? '?',
        $data['sentimiento'] ?? '?',
        $data['urgencia'] ?? '?',
        $origen,
        $result->totalTokens(),
    );
}

$stats = $platform->stats();
$total = (microtime(true) - $inicio) * 1000;

echo "  " . str_repeat('─', 62) . "\n";
printf("  Modo: %s | API real: %d | Cassette: %d | Hit rate: %.0f%%\n",
    $stats['mode'], $stats['live'], $stats['replayed'], $stats['hit_rate'] * 100);
printf("  Tiempo total: %.0f ms | Tokens ahorrados: %d\n\n", $total, $stats['tokens_saved']);

if ($stats['live'] > 0) {
    echo "  \033[1;32m✓ Cassettes grabadas en cassettes/\033[0m\n";
    echo "  \033[0;37m  Vuelve a ejecutarlo: ahora irá desde disco, sin tocar la red.\033[0m\n\n";
}
