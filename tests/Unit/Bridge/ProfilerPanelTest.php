<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit\Bridge;

use MikiBuilder\LlmVcr\Bridge\Symfony\DataCollector\LlmVcrDataCollector;
use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Renderiza la plantilla del panel DE VERDAD y comprueba el HTML resultante.
 *
 * Una plantilla Twig rota no la detecta ningún test de PHP: falla en tiempo
 * de ejecución, en el navegador, cuando ya es tarde. Estos tests la compilan
 * y la renderizan con datos reales del recolector.
 */
#[CoversClass(LlmVcrDataCollector::class)]
#[Group('symfony')]
final class ProfilerPanelTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/llm-vcr-panel-' . bin2hex(random_bytes(5));
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

    private function twig(): Environment
    {
        // El layout real del Web Profiler no está disponible fuera de una
        // aplicación Symfony, así que se sustituye por un esqueleto con los
        // mismos bloques. Lo que se valida es NUESTRA plantilla.
        $stub = '{% block toolbar %}{% endblock %}'
            . '{% block menu %}{% endblock %}'
            . '{% block panel %}{% endblock %}';

        return new Environment(new ChainLoader([
            new FilesystemLoader(\dirname(__DIR__, 3) . '/src/Bridge/Symfony/templates'),
            new ArrayLoader([
                '@WebProfiler/Profiler/layout.html.twig' => $stub,
                '@WebProfiler/Profiler/toolbar_item.html.twig' => '<div class="sf-toolbar-item"></div>',
            ]),
        ]));
    }

    private function factoryConActividad(string $mode = 'replay', int $reproducciones = 3): PlatformFactory
    {
        $factory = new PlatformFactory($this->tmpDir, $mode, new SemanticMatcher(), new Redactor());

        $mensajes = [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte.'],
            ['role' => 'user', 'content' => 'No puedo acceder a mi cuenta'],
        ];

        $factory->wrap(new InMemoryPlatform('{"categoria":"acceso"}'), cassette: 't', mode: Mode::Record)
            ->invoke('llama-3.1-8b-instant', $mensajes);

        for ($i = 0; $i < $reproducciones; ++$i) {
            $factory->wrap(new InMemoryPlatform('x'), cassette: 't')
                ->invoke('llama-3.1-8b-instant', $mensajes);
        }

        return $factory;
    }

    private function collector(PlatformFactory $factory): LlmVcrDataCollector
    {
        $collector = new LlmVcrDataCollector($factory);
        $collector->collect(new Request(), new Response());

        return $collector;
    }

    #[Test]
    public function laPlantillaTieneSintaxisValida(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Bridge/Symfony/templates/Collector/llm_vcr.html.twig';
        $source = file_get_contents($path);

        self::assertIsString($source);

        $twig = $this->twig();

        // parse() lanza SyntaxError si la plantilla está rota; que devuelva
        // un nodo raíz es la prueba de que compiló.
        $ast = $twig->parse($twig->tokenize(new \Twig\Source($source, 'llm_vcr.html.twig')));

        self::assertInstanceOf(\Twig\Node\ModuleNode::class, $ast);
    }

    #[Test]
    public function elPanelSeRenderizaConLasMetricas(): void
    {
        $collector = $this->collector($this->factoryConActividad());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $collector]);

        self::assertStringContainsString('Hit rate', $html);
        self::assertStringContainsString('Tokens saved', $html);
        self::assertStringContainsString('Latency avoided', $html);
        self::assertStringContainsString('Cassettes on disk', $html);
    }

    #[Test]
    public function elPanelMuestraElHitRateCorrecto(): void
    {
        // 1 grabación real + 3 reproducciones = 75 %
        $collector = $this->collector($this->factoryConActividad(reproducciones: 3));

        self::assertSame(75, $collector->getHitRatePercent());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $collector]);

        self::assertStringContainsString('75', $html);
    }

    #[Test]
    public function elPanelAvisaSiSeTocaLaRedEnModoReplay(): void
    {
        $factory = new PlatformFactory($this->tmpDir, 'replay', new SemanticMatcher(), new Redactor());
        $factory->wrap(new InMemoryPlatform('x'), cassette: 'b', mode: Mode::Bypass)
            ->invoke('llama-3.1-8b-instant', [['role' => 'user', 'content' => 'hola']]);

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $this->collector($factory)]);

        self::assertStringContainsString('live calls were made in', $html);
    }

    /**
     * Un "0 ms" junto a varias reproducciones parece un fallo del panel.
     * En realidad la latencia se lee de la cassette: si se grabó sin
     * latencia, no hay tiempo que mostrar. El panel debe explicarlo.
     */
    #[Test]
    public function elPanelExplicaPorQueLaLatenciaEsCeroAlReproducir(): void
    {
        $collector = $this->collector($this->factoryConActividad());

        self::assertSame(0.0, $collector->getLatencySavedMs());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $collector]);

        self::assertStringContainsString('read from the cassette', $html);
    }

    #[Test]
    public function elPanelNoMuestraLaNotaSiHayLatenciaQueEnsenar(): void
    {
        $factory = new PlatformFactory($this->tmpDir, 'record', new SemanticMatcher(), new Redactor());
        $llm = new InMemoryPlatform('{"ok":true}', simulatedLatencyMs: 320.0);

        $mensajes = [
            ['role' => 'system', 'content' => 'Clasifica.'],
            ['role' => 'user', 'content' => 'hola'],
        ];

        // Se graba con latencia y luego se reproduce: ya hay tiempo ahorrado.
        $factory->wrap($llm, cassette: 'lat')->invoke('m1', $mensajes);
        $factory->wrap($llm, cassette: 'lat')->invoke('m1', $mensajes);

        $collector = $this->collector($factory);

        self::assertSame(320.0, $collector->getLatencySavedMs());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $collector]);

        self::assertStringNotContainsString('read from the cassette', $html);
    }

    #[Test]
    public function elPanelIndicaCuandoNoHuboInvocaciones(): void
    {
        $factory = new PlatformFactory($this->tmpDir, 'record', new SemanticMatcher(), new Redactor());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('panel', ['collector' => $this->collector($factory)]);

        self::assertStringContainsString('did not invoke any model', $html);
    }

    #[Test]
    public function laBarraDeDepuracionSeRenderiza(): void
    {
        $collector = $this->collector($this->factoryConActividad());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('toolbar', ['collector' => $collector]);

        self::assertNotSame('', trim($html));
    }

    #[Test]
    public function elMenuLateralMuestraElNombreYElContador(): void
    {
        $collector = $this->collector($this->factoryConActividad());

        $html = $this->twig()
            ->load('Collector/llm_vcr.html.twig')
            ->renderBlock('menu', ['collector' => $collector]);

        self::assertStringContainsString('llm-vcr', $html);
        self::assertStringContainsString('<span>4</span>', $html);
    }
}
