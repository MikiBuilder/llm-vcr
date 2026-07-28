<?php

declare(strict_types=1);

namespace App\Controller;

use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo de llm-vcr en el Web Profiler.
 *
 * Copia este fichero a src/Controller/HomeController.php de tu proyecto
 * Symfony, arranca el servidor y abre la página: verás el panel en la barra
 * de depuración.
 *
 * En un proyecto real, InMemoryPlatform sería tu cliente LLM de verdad
 * (GroqPlatform::fromEnv(), por ejemplo). Aquí se simula para que la demo
 * no gaste cuota.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(PlatformFactory $vcr): Response
    {
        $mensajes = [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte.'],
            ['role' => 'user',   'content' => 'No puedo acceder a mi cuenta'],
        ];

        /*
         * IMPORTANTE: se reutiliza la MISMA instancia en las cuatro llamadas.
         *
         * Antes se creaba un InMemoryPlatform distinto en cada iteración, y el
         * de las reproducciones devolvía 'x'. Daba igual porque la cassette ya
         * existía y nunca se le llegaba a preguntar... pero es confuso de leer
         * y se rompería en cuanto la cassette no casara.
         *
         * simulatedLatencyMs imita lo que tarda un LLM real (Groq ronda los
         * 200-450 ms). Sin esto, "Latencia evitada" saldría 0 ms y la métrica
         * más vistosa del panel no se vería.
         */
        $llm = new InMemoryPlatform(
            '{"categoria":"acceso","urgencia":4}',
            simulatedLatencyMs: 320.0,
        );

        // 1 grabación real + 3 reproducciones = 75 % de hit rate.
        for ($i = 0; $i < 4; ++$i) {
            $vcr->wrap($llm, cassette: 'web')->invoke('llama-3.1-8b-instant', $mensajes);
        }

        return new Response(<<<'HTML'
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="utf-8">
                <title>Demo llm-vcr</title>
                <style>
                    body { font-family: system-ui, sans-serif; max-width: 40rem;
                           margin: 4rem auto; padding: 0 1.5rem; line-height: 1.6;
                           color: #1a1a1a; }
                    code { background: #f4f4f5; padding: .15em .4em; border-radius: 4px;
                           font-size: .9em; }
                    .hint { color: #52525b; }
                </style>
            </head>
            <body>
                <h1>Demo de llm-vcr</h1>
                <p>Esta página ha invocado a un modelo <strong>cuatro veces</strong>:
                   la primera grabó la respuesta y las otras tres la sirvieron desde
                   la cassette en disco.</p>
                <p class="hint">Mira la barra de depuración, abajo. Verás
                   <code>3 cassette / 1 API</code>. Pincha en el icono para abrir
                   el panel completo.</p>
            </body>
            </html>
            HTML);
    }
}
