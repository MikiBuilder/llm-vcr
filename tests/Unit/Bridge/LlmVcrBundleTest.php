<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit\Bridge;

use MikiBuilder\LlmVcr\Bridge\Symfony\DataCollector\LlmVcrDataCollector;
use MikiBuilder\LlmVcr\Bridge\Symfony\LlmVcrBundle;
use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Matching\ExactMatcher;
use MikiBuilder\LlmVcr\Matching\PlaceholderMatcher;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Estos tests arrancan un kernel de Symfony DE VERDAD y compilan el
 * contenedor. No son mocks: si la configuración del bundle estuviera mal,
 * fallarían igual que en una aplicación real.
 */
#[CoversClass(LlmVcrBundle::class)]
#[CoversClass(PlatformFactory::class)]
#[Group('symfony')]
final class LlmVcrBundleTest extends TestCase
{
    private string $tmpDir;

    /** @var list<Kernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/llm-vcr-bundle-' . bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        /*
         * Al arrancar, Symfony registra un manejador de excepciones que no
         * retira al apagarse. Si se deja puesto, PHPUnit marca el test como
         * "risky". Se restaura uno por cada kernel arrancado: exactamente lo
         * que hace KernelTestCase::ensureKernelShutdown().
         */
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            restore_exception_handler();
        }
        $this->kernels = [];

        self::rmrf($this->tmpDir);
    }

    private static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    /**
     * @param array<string, mixed> $llmVcrConfig
     */
    private function bootKernel(array $llmVcrConfig = []): Kernel
    {
        $cassetteDir = $this->tmpDir . '/cassettes';
        $cacheDir = $this->tmpDir . '/cache';
        $config = $llmVcrConfig + ['cassette_dir' => $cassetteDir];

        $kernel = new class('test', false, $config, $cacheDir) extends Kernel {
            use MicroKernelTrait;

            /** @param array<string, mixed> $llmVcrConfig */
            public function __construct(
                string $env,
                bool $debug,
                private readonly array $llmVcrConfig,
                private readonly string $tmpCacheDir,
            ) {
                parent::__construct($env, $debug);
            }

            public function registerBundles(): iterable
            {
                return [new FrameworkBundle(), new LlmVcrBundle()];
            }

            protected function configureContainer(ContainerConfigurator $c): void
            {
                $c->extension('framework', [
                    'secret' => 'test',
                    'test' => true,
                    'http_method_override' => false,
                    // log=false evita que Symfony instale su ErrorHandler,
                    // que PHPUnit detectaría como handler sin restaurar.
                    'php_errors' => ['log' => false],
                ]);
                $c->extension('llm_vcr', $this->llmVcrConfig);
            }

            public function getCacheDir(): string
            {
                return $this->tmpCacheDir;
            }

            public function getLogDir(): string
            {
                return $this->tmpCacheDir . '/log';
            }

            public function getProjectDir(): string
            {
                return $this->tmpCacheDir;
            }
        };

        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * Contenedor especial de tests: da acceso a los servicios privados.
     *
     * En producción llm_vcr.matcher es privado (correcto: se inyecta, no se
     * saca del contenedor). framework.test=true publica este contenedor
     * justamente para poder inspeccionarlos desde los tests.
     */
    private function testContainer(Kernel $kernel): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        /** @var \Symfony\Component\DependencyInjection\ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Compila el contenedor sin las pasadas de optimización, para poder
     * inspeccionar las definiciones tal y como las declara el bundle.
     *
     * @param array<string, mixed> $llmVcrConfig
     *
     * @return array<string, \Symfony\Component\DependencyInjection\Definition>
     */
    private function compiledDefinitions(array $llmVcrConfig = []): array
    {
        $builder = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $builder->setParameter('kernel.debug', true);
        $builder->setParameter('kernel.project_dir', $this->tmpDir);
        $builder->setParameter('kernel.bundles', []);
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', $this->tmpDir);

        $bundle = new LlmVcrBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([$llmVcrConfig + ['cassette_dir' => $this->tmpDir . '/cassettes']], $builder);

        return $builder->getDefinitions();
    }

    #[Test]
    public function elBundleArrancaYCompilaElContenedor(): void
    {
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->getContainer()->has('llm_vcr.platform_factory'));
    }

    #[Test]
    public function laFabricaEsInyectableYUsaLaConfiguracion(): void
    {
        $kernel = $this->bootKernel(['mode' => 'replay']);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');

        self::assertInstanceOf(PlatformFactory::class, $factory);
        self::assertSame(Mode::Replay, $factory->mode());
        self::assertStringEndsWith('/cassettes', $factory->cassetteDir());
    }

    #[Test]
    public function porDefectoElModoEsRecord(): void
    {
        $kernel = $this->bootKernel();

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        self::assertSame(Mode::Record, $factory->mode());
    }

    #[Test]
    public function laEstrategiaSemanticEsLaPorDefecto(): void
    {
        $kernel = $this->bootKernel();

        $matcher = $this->testContainer($kernel)->get('llm_vcr.matcher');

        self::assertInstanceOf(SemanticMatcher::class, $matcher);
    }

    #[Test]
    public function sePuedeElegirLaEstrategiaPlaceholder(): void
    {
        $kernel = $this->bootKernel([
            'matcher' => [
                'strategy' => 'placeholder',
                'placeholders' => ['order_id' => '/PED-\d+/'],
            ],
        ]);

        $matcher = $this->testContainer($kernel)->get('llm_vcr.matcher');

        self::assertInstanceOf(PlaceholderMatcher::class, $matcher);
        self::assertSame(1.0, $matcher->similarity('pedido PED-1', 'pedido PED-2'));
    }

    #[Test]
    public function sePuedeElegirLaEstrategiaExacta(): void
    {
        $kernel = $this->bootKernel(['matcher' => ['strategy' => 'exact']]);

        self::assertInstanceOf(ExactMatcher::class, $this->testContainer($kernel)->get('llm_vcr.matcher'));
    }

    #[Test]
    public function elUmbralDelMatcherEsConfigurable(): void
    {
        $kernel = $this->bootKernel(['matcher' => ['strategy' => 'semantic', 'threshold' => 0.95]]);

        $matcher = $this->testContainer($kernel)->get('llm_vcr.matcher');

        self::assertInstanceOf(MatcherInterface::class, $matcher);
        self::assertSame(0.95, $matcher->threshold());
    }

    #[Test]
    public function laFabricaEnvuelveUnaPlataformaYGrabaEnElDirectorioConfigurado(): void
    {
        $kernel = $this->bootKernel(['mode' => 'record']);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        $platform = $factory->wrap(new InMemoryPlatform('{"ok":true}'), cassette: 'demo');

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets.'],
            ['role' => 'user', 'content' => 'Hola'],
        ]);

        self::assertSame('{"ok":true}', $result->text);
        self::assertFileExists($factory->cassetteDir() . '/demo.json');
    }

    #[Test]
    public function laRedaccionSeAplicaSegunLaConfiguracion(): void
    {
        $kernel = $this->bootKernel(['redaction' => ['pii' => true]]);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        $factory->wrap(new InMemoryPlatform('ok'), cassette: 'pii')
            ->invoke('llama-3.1-8b-instant', [
                ['role' => 'system', 'content' => 'Test.'],
                ['role' => 'user', 'content' => 'Mi email es alguien@ejemplo.es'],
            ]);

        $contenido = (string) file_get_contents($factory->cassetteDir() . '/pii.json');

        self::assertStringNotContainsString('alguien@ejemplo.es', $contenido);
        self::assertStringContainsString('<REDACTED:EMAIL>', $contenido);
    }

    #[Test]
    public function lasReglasDeRedaccionPersonalizadasLleganAlServicio(): void
    {
        $kernel = $this->bootKernel([
            'redaction' => ['custom_rules' => ['/\bEXP-\d{4}\b/' => '<REDACTED:EXPEDIENTE>']],
        ]);

        $redactor = $this->testContainer($kernel)->get('llm_vcr.redactor');
        self::assertInstanceOf(\MikiBuilder\LlmVcr\Redaction\Redactor::class, $redactor);

        self::assertSame(
            'Caso <REDACTED:EXPEDIENTE>',
            $redactor->redact('Caso EXP-2026'),
        );
    }

    /**
     * El DataCollector se comprueba sobre la DEFINICIÓN del contenedor, no
     * sobre el servicio instanciado: sin WebProfilerBundle nadie consume el
     * tag 'data_collector', y Symfony elimina los servicios privados sin
     * consumidores al compilar. En una aplicación real con el profiler
     * activo el tag lo mantiene vivo.
     */
    #[Test]
    public function elRecolectorDeDatosSeDefineCuandoElProfilerEstaActivo(): void
    {
        $definitions = $this->compiledDefinitions(['profiler' => true]);

        self::assertArrayHasKey('llm_vcr.data_collector', $definitions);
        self::assertArrayHasKey('data_collector', $definitions['llm_vcr.data_collector']->getTags());
    }

    #[Test]
    public function elRecolectorNoSeDefineSiSeDesactiva(): void
    {
        self::assertArrayNotHasKey('llm_vcr.data_collector', $this->compiledDefinitions(['profiler' => false]));
    }

    #[Test]
    public function elRecolectorAgregaLasMetricasDeTodasLasPlataformas(): void
    {
        $kernel = $this->bootKernel(['mode' => 'record', 'profiler' => true]);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        $mensajes = [
            ['role' => 'system', 'content' => 'Clasifica.'],
            ['role' => 'user', 'content' => 'Hola'],
        ];

        // Primera pasada: graba (llamada real).
        $factory->wrap(new InMemoryPlatform('{"a":1}'), cassette: 'm')->invoke('m1', $mensajes);
        // Segunda: ya existe, se sirve de cassette.
        $factory->wrap(new InMemoryPlatform('{"a":1}'), cassette: 'm')->invoke('m1', $mensajes);

        $stats = $factory->aggregatedStats();

        self::assertSame(1, $stats['live']);
        self::assertSame(1, $stats['replayed']);
        self::assertSame(2, $stats['platforms']);
        self::assertSame(0.5, $stats['hit_rate']);
    }

    #[Test]
    public function elRecolectorExponeLosDatosParaLaPlantilla(): void
    {
        $kernel = $this->bootKernel(['mode' => 'replay', 'profiler' => true]);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        $collector = new LlmVcrDataCollector($factory);
        $collector->collect(
            new \Symfony\Component\HttpFoundation\Request(),
            new \Symfony\Component\HttpFoundation\Response(),
        );

        self::assertSame('replay', $collector->getMode());
        self::assertSame(0, $collector->getTotal());
        self::assertSame('default', $collector->getStatusColor());
    }

    #[Test]
    public function elBadgeSePoneEnRojoSiSeTocaLaRedEnModoReplay(): void
    {
        $kernel = $this->bootKernel(['mode' => 'replay', 'profiler' => true]);

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');
        self::assertInstanceOf(PlatformFactory::class, $factory);

        // En bypass se fuerza una llamada real aunque el modo global sea replay.
        $factory->wrap(new InMemoryPlatform('x'), cassette: 'r', mode: Mode::Bypass)
            ->invoke('m1', [['role' => 'user', 'content' => 'hola']]);

        $collector = new LlmVcrDataCollector($factory);
        $collector->collect(
            new \Symfony\Component\HttpFoundation\Request(),
            new \Symfony\Component\HttpFoundation\Response(),
        );

        self::assertSame(1, $collector->getLive());
        self::assertSame('red', $collector->getStatusColor());
    }

    #[Test]
    public function elParametroDelDirectorioQuedaDisponible(): void
    {
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->getContainer()->hasParameter('llm_vcr.cassette_dir'));
    }
}
