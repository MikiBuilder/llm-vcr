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
use MikiBuilder\LlmVcr\Redaction\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Tests del bundle.
 *
 * NOTA DE RENDIMIENTO: arrancar un kernel completo compila y escribe el
 * contenedor a disco. En Linux cuesta ~60 ms, pero en Windows el I/O es
 * mucho más caro y quince kernels convertían la suite en 25 segundos.
 *
 * Por eso casi todo se verifica sobre un ContainerBuilder, que ejercita
 * exactamente el mismo código del bundle (configuración + definición de
 * servicios) sin tocar el disco: 15 contenedores en 14 ms.
 *
 * Queda UN test con kernel real, para garantizar que el bundle también
 * funciona integrado de verdad en Symfony.
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

    // ── Camino rápido: sin kernel, sin disco ────────────────────────────

    /**
     * Ejecuta la extensión del bundle sobre un contenedor limpio.
     *
     * @param array<string, mixed> $llmVcrConfig
     */
    private function build(array $llmVcrConfig = []): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.debug', true);
        $builder->setParameter('kernel.project_dir', $this->tmpDir);
        $builder->setParameter('kernel.bundles', []);
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', $this->tmpDir);

        $extension = (new LlmVcrBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load(
            [$llmVcrConfig + ['cassette_dir' => $this->tmpDir . '/cassettes']],
            $builder,
        );

        return $builder;
    }

    /**
     * Instancia un servicio a partir de su definición, sin compilar.
     *
     * @param array<string, mixed> $config
     */
    private function instantiate(string $serviceId, array $config = []): object
    {
        $builder = $this->build($config);
        $definition = $builder->getDefinition($serviceId);

        /** @var class-string $class */
        $class = $definition->getClass();

        $args = array_map(
            static fn (mixed $arg): mixed => $arg instanceof \Symfony\Component\DependencyInjection\Reference
                ? null
                : $arg,
            array_values($definition->getArguments()),
        );

        return new $class(...$args);
    }

    #[Test]
    public function laFabricaSeDefineConLaConfiguracionDada(): void
    {
        $builder = $this->build(['mode' => 'replay']);

        $definition = $builder->getDefinition('llm_vcr.platform_factory');
        $args = $definition->getArguments();

        self::assertSame(PlatformFactory::class, $definition->getClass());
        self::assertStringEndsWith('/cassettes', (string) $args[0]);
        self::assertSame('replay', $args[1]);
    }

    #[Test]
    public function porDefectoElModoEsRecord(): void
    {
        self::assertSame('record', $this->build()->getDefinition('llm_vcr.platform_factory')->getArgument(1));
    }

    #[Test]
    public function laEstrategiaSemanticEsLaPorDefecto(): void
    {
        self::assertSame(
            SemanticMatcher::class,
            $this->build()->getDefinition('llm_vcr.matcher')->getClass(),
        );
    }

    #[Test]
    public function sePuedeElegirLaEstrategiaPlaceholder(): void
    {
        $matcher = $this->instantiate('llm_vcr.matcher', [
            'matcher' => ['strategy' => 'placeholder', 'placeholders' => ['order_id' => '/PED-\d+/']],
        ]);

        self::assertInstanceOf(PlaceholderMatcher::class, $matcher);
        self::assertSame(1.0, $matcher->similarity('pedido PED-1', 'pedido PED-2'));
    }

    #[Test]
    public function sePuedeElegirLaEstrategiaExacta(): void
    {
        self::assertSame(
            ExactMatcher::class,
            $this->build(['matcher' => ['strategy' => 'exact']])->getDefinition('llm_vcr.matcher')->getClass(),
        );
    }

    #[Test]
    public function elUmbralDelMatcherEsConfigurable(): void
    {
        $matcher = $this->instantiate('llm_vcr.matcher', [
            'matcher' => ['strategy' => 'semantic', 'threshold' => 0.95],
        ]);

        self::assertInstanceOf(MatcherInterface::class, $matcher);
        self::assertSame(0.95, $matcher->threshold());
    }

    #[Test]
    public function lasReglasDeRedaccionPersonalizadasLleganAlServicio(): void
    {
        $redactor = $this->instantiate('llm_vcr.redactor', [
            'redaction' => ['custom_rules' => ['/\bEXP-\d{4}\b/' => '<REDACTED:EXPEDIENTE>']],
        ]);

        self::assertInstanceOf(Redactor::class, $redactor);
        self::assertSame('Caso <REDACTED:EXPEDIENTE>', $redactor->redact('Caso EXP-2026'));
    }

    #[Test]
    public function laRedaccionDePiiSePuedeDesactivar(): void
    {
        $redactor = $this->instantiate('llm_vcr.redactor', ['redaction' => ['pii' => false]]);

        self::assertInstanceOf(Redactor::class, $redactor);
        self::assertStringContainsString('test@example.com', $redactor->redact('Correo test@example.com'));
        self::assertStringContainsString('<REDACTED:API_KEY>', $redactor->redact('sk-proj-AbCdEf0123456789XyZ'));
    }

    /**
     * El DataCollector se comprueba sobre la DEFINICIÓN: sin WebProfilerBundle
     * nadie consume el tag 'data_collector', y Symfony elimina los servicios
     * privados sin consumidores al compilar.
     */
    #[Test]
    public function elRecolectorDeDatosSeDefineCuandoElProfilerEstaActivo(): void
    {
        $definitions = $this->build(['profiler' => true])->getDefinitions();

        self::assertArrayHasKey('llm_vcr.data_collector', $definitions);
        self::assertArrayHasKey('data_collector', $definitions['llm_vcr.data_collector']->getTags());
    }

    #[Test]
    public function elRecolectorNoSeDefineSiSeDesactiva(): void
    {
        self::assertArrayNotHasKey('llm_vcr.data_collector', $this->build(['profiler' => false])->getDefinitions());
    }

    /**
     * Regresión: sin configurar 'profiler', el valor por defecto llega como el
     * string "%kernel.debug%" —Symfony no resuelve los parámetros dentro de
     * loadExtension()—, así que compararlo con true daba siempre falso y el
     * panel NO se registraba nunca en una aplicación real.
     *
     * Este bug no lo detectaron los tests anteriores porque todos pasaban
     * 'profiler' de forma explícita. Salió al instalar el bundle en un
     * proyecto Symfony de verdad.
     */
    #[Test]
    public function elRecolectorSeDefineConLaConfiguracionPorDefectoCuandoDebugEstaActivo(): void
    {
        $definitions = $this->build()->getDefinitions();

        self::assertArrayHasKey(
            'llm_vcr.data_collector',
            $definitions,
            'Con kernel.debug=true y sin configurar "profiler", el panel debe registrarse.',
        );
    }

    #[Test]
    public function elRecolectorNoSeDefineSiDebugEstaDesactivado(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter('kernel.project_dir', $this->tmpDir);
        $builder->setParameter('kernel.bundles', []);
        $builder->setParameter('kernel.environment', 'prod');
        $builder->setParameter('kernel.build_dir', $this->tmpDir);

        $extension = (new LlmVcrBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([['cassette_dir' => $this->tmpDir . '/cassettes']], $builder);

        self::assertArrayNotHasKey('llm_vcr.data_collector', $builder->getDefinitions());
    }

    #[Test]
    public function elParametroDelDirectorioQuedaDisponible(): void
    {
        self::assertTrue($this->build()->hasParameter('llm_vcr.cassette_dir'));
    }

    #[Test]
    public function seRegistranLosAliasParaAutowiring(): void
    {
        $builder = $this->build();

        self::assertTrue($builder->hasAlias(MatcherInterface::class));
        self::assertTrue($builder->hasAlias(Redactor::class));
        self::assertTrue($builder->hasAlias(PlatformFactory::class));
    }

    #[Test]
    public function laFabricaEsPublicaParaPoderInyectarla(): void
    {
        self::assertTrue($this->build()->getDefinition('llm_vcr.platform_factory')->isPublic());
    }

    // ── Comportamiento de la fábrica ────────────────────────────────────

    private function factory(string $mode = 'record'): PlatformFactory
    {
        return new PlatformFactory(
            $this->tmpDir . '/cassettes',
            $mode,
            new SemanticMatcher(),
            new Redactor(),
        );
    }

    /** @return list<array{role: string, content: string}> */
    private function mensajes(): array
    {
        return [
            ['role' => 'system', 'content' => 'Clasifica tickets.'],
            ['role' => 'user', 'content' => 'Hola'],
        ];
    }

    #[Test]
    public function laFabricaEnvuelveUnaPlataformaYGrabaEnElDirectorioConfigurado(): void
    {
        $factory = $this->factory();

        $result = $factory->wrap(new InMemoryPlatform('{"ok":true}'), cassette: 'demo')
            ->invoke('llama-3.1-8b-instant', $this->mensajes());

        self::assertSame('{"ok":true}', $result->text);
        self::assertFileExists($factory->cassetteDir() . '/demo.json');
    }

    #[Test]
    public function laRedaccionSeAplicaAlGrabar(): void
    {
        $factory = $this->factory();

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
    public function laFabricaAgregaLasMetricasDeTodasLasPlataformas(): void
    {
        $factory = $this->factory();

        // Primera pasada: graba (llamada real). Segunda: sirve de cassette.
        $factory->wrap(new InMemoryPlatform('{"a":1}'), cassette: 'm')->invoke('m1', $this->mensajes());
        $factory->wrap(new InMemoryPlatform('{"a":1}'), cassette: 'm')->invoke('m1', $this->mensajes());

        $stats = $factory->aggregatedStats();

        self::assertSame(1, $stats['live']);
        self::assertSame(1, $stats['replayed']);
        self::assertSame(2, $stats['platforms']);
        self::assertSame(0.5, $stats['hit_rate']);
    }

    #[Test]
    public function resetLimpiaLasMetricasEntrePeticiones(): void
    {
        $factory = $this->factory();
        $factory->wrap(new InMemoryPlatform('x'), cassette: 'r')->invoke('m1', $this->mensajes());

        $factory->reset();

        self::assertSame(0, $factory->aggregatedStats()['platforms']);
    }

    #[Test]
    public function elRecolectorExponeLosDatosParaLaPlantilla(): void
    {
        $collector = new LlmVcrDataCollector($this->factory('replay'));
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
        $factory = $this->factory('replay');

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

    // ── Integración real: un solo kernel ────────────────────────────────

    /**
     * El único test que arranca Symfony de verdad.
     *
     * Cubre lo que el ContainerBuilder no puede: que el bundle se registre,
     * que el contenedor compile sin errores y que la fábrica sea recuperable
     * ya construida. Si esto pasa, el bundle funciona en una app real.
     */
    #[Test]
    public function elBundleFuncionaEnUnKernelDeSymfonyReal(): void
    {
        $cassetteDir = $this->tmpDir . '/cassettes';
        $cacheDir = $this->tmpDir . '/cache';

        $kernel = new class('test', false, ['cassette_dir' => $cassetteDir, 'mode' => 'replay'], $cacheDir) extends Kernel {
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

        $factory = $kernel->getContainer()->get('llm_vcr.platform_factory');

        self::assertInstanceOf(PlatformFactory::class, $factory);
        self::assertSame(Mode::Replay, $factory->mode());
        self::assertSame($cassetteDir, $factory->cassetteDir());
    }

    #[Test]
    public function laConfiguracionRechazaUnaEstrategiaDesconocida(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->build(['matcher' => ['strategy' => 'inventada']]);
    }

    #[Test]
    public function laConfiguracionRechazaUnUmbralFueraDeRango(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->build(['matcher' => ['threshold' => 1.5]]);
    }
}
