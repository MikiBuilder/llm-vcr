<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Bridge\Symfony;

use MikiBuilder\LlmVcr\Bridge\Symfony\DataCollector\LlmVcrDataCollector;
use MikiBuilder\LlmVcr\Contracts\MatcherInterface;
use MikiBuilder\LlmVcr\Matching\ExactMatcher;
use MikiBuilder\LlmVcr\Matching\PlaceholderMatcher;
use MikiBuilder\LlmVcr\Matching\SemanticMatcher;
use MikiBuilder\LlmVcr\Mode;
use MikiBuilder\LlmVcr\RecordingPlatform;
use MikiBuilder\LlmVcr\Redaction\Redactor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integración de llm-vcr con Symfony.
 *
 * Registra el matcher, el redactor y la fábrica de plataformas como servicios,
 * y añade un panel al Web Profiler con las métricas de grabación.
 *
 * Uso mínimo en config/packages/llm_vcr.yaml:
 *
 *     llm_vcr:
 *         cassette_dir: '%kernel.project_dir%/tests/cassettes'
 *
 * Y en config/packages/test/llm_vcr.yaml:
 *
 *     llm_vcr:
 *         mode: replay
 *
 * Extiende AbstractBundle (Symfony 6.1+), que permite declarar configuración
 * y servicios en una sola clase en lugar de repartirlos entre Extension,
 * Configuration y ficheros XML.
 */
final class LlmVcrBundle extends AbstractBundle
{
    protected string $extensionAlias = 'llm_vcr';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('cassette_dir')
                    ->defaultValue('%kernel.project_dir%/tests/cassettes')
                    ->info('Dónde se guardan las cassettes. Se commitean a git.')
                ->end()
                ->enumNode('mode')
                    ->values(array_map(static fn (Mode $m): string => $m->value, Mode::cases()))
                    ->defaultValue(Mode::Record->value)
                    ->info('record (dev) | replay (CI) | bypass (depurar) | refresh (regrabar)')
                ->end()
                ->arrayNode('matcher')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('strategy')
                            ->values(['semantic', 'placeholder', 'exact'])
                            ->defaultValue('semantic')
                            ->info('Cómo se decide si una petición coincide con una grabada.')
                        ->end()
                        ->floatNode('threshold')
                            ->defaultValue(0.82)
                            ->min(0.0)->max(1.0)
                            ->info('Solo para "semantic": umbral de similitud.')
                        ->end()
                        ->arrayNode('placeholders')
                            // normalizeKeys(false) es imprescindible: sin ello Symfony
                            // convierte los guiones bajos de las claves y destroza
                            // cualquier clave que no sea un identificador simple.
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                            ->info('Solo para "placeholder": nombre => patrón regex.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('pii')
                            ->defaultTrue()
                            ->info('Redactar emails, teléfonos, DNI, IBAN... Las credenciales SIEMPRE se redactan.')
                        ->end()
                        ->arrayNode('custom_rules')
                            // Las claves son expresiones regulares con barras y
                            // contrabarras. useAttributeAsKey() las normaliza y las
                            // rompe; normalizeKeys(false) las deja intactas.
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                            ->info('Reglas propias: patrón regex => texto de reemplazo.')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('profiler')
                    ->defaultValue('%kernel.debug%')
                    ->info('Recoger métricas para el panel del Web Profiler.')
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     cassette_dir: string,
     *     mode: string,
     *     matcher: array{strategy: string, threshold: float, placeholders: array<string, string>},
     *     redaction: array{pii: bool, custom_rules: array<string, string>},
     *     profiler: bool|string
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // ── Matcher ──────────────────────────────────────────────────────
        $matcherConfig = $config['matcher'];

        match ($matcherConfig['strategy']) {
            'semantic' => $services->set('llm_vcr.matcher', SemanticMatcher::class)
                ->args([$matcherConfig['threshold']]),
            'placeholder' => $services->set('llm_vcr.matcher', PlaceholderMatcher::class)
                ->args([$matcherConfig['placeholders']]),
            'exact' => $services->set('llm_vcr.matcher', ExactMatcher::class),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown matcher strategy: "%s".',
                $matcherConfig['strategy'],
            )),
        };

        $services->alias(MatcherInterface::class, 'llm_vcr.matcher');

        // ── Redactor ─────────────────────────────────────────────────────
        $redaction = $config['redaction'];

        $services->set('llm_vcr.redactor', Redactor::class)
            ->args([$redaction['pii'], $redaction['custom_rules']]);

        $services->alias(Redactor::class, 'llm_vcr.redactor');

        // ── Fábrica de plataformas ───────────────────────────────────────
        $services->set('llm_vcr.platform_factory', PlatformFactory::class)
            ->args([
                $config['cassette_dir'],
                $config['mode'],
                new Reference('llm_vcr.matcher'),
                new Reference('llm_vcr.redactor'),
            ])
            ->public();

        $services->alias(PlatformFactory::class, 'llm_vcr.platform_factory');

        // ── Panel del Profiler ───────────────────────────────────────────
        // OJO: el valor por defecto es el marcador de parámetro sin resolver,
        // es decir el string "%kernel.debug%", no un booleano. Symfony solo lo
        // resuelve al inyectarlo en un servicio, no dentro de loadExtension().
        // Por eso se resuelve aquí a mano contra el parámetro del contenedor.
        $profiler = $config['profiler'];

        if (is_string($profiler)) {
            $profiler = $builder->hasParameter('kernel.debug')
                ? (bool) $builder->getParameter('kernel.debug')
                : false;
        }

        if ($profiler) {
            $services->set('llm_vcr.data_collector', LlmVcrDataCollector::class)
                ->args([new Reference('llm_vcr.platform_factory')])
                ->tag('data_collector', [
                    'id' => 'llm_vcr',
                    'template' => '@LlmVcr/Collector/llm_vcr.html.twig',
                    'priority' => 250,
                ]);
        }

        // Parámetros útiles para quien quiera inspeccionarlos.
        $builder->setParameter('llm_vcr.cassette_dir', $config['cassette_dir']);
        $builder->setParameter('llm_vcr.mode', $config['mode']);
    }

    /**
     * Registra el directorio de plantillas del bundle en Twig.
     *
     * Sin esto, '@LlmVcr/...' no resolvería y el panel no se renderizaría.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$this->isTwigAvailable($builder)) {
            return;
        }

        $container->extension('twig', [
            'paths' => [$this->getPath() . '/templates' => 'LlmVcr'],
        ]);
    }

    private function isTwigAvailable(ContainerBuilder $builder): bool
    {
        /** @var array<string, mixed> $bundles */
        $bundles = $builder->hasParameter('kernel.bundles')
            ? (array) $builder->getParameter('kernel.bundles')
            : [];

        return isset($bundles['TwigBundle']);
    }

    public function getPath(): string
    {
        // El bundle vive en src/Bridge/Symfony, no en la raíz del paquete.
        return \dirname(__DIR__, 3) . '/src/Bridge/Symfony';
    }
}
