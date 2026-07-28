<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Symfony es una dependencia OPCIONAL.
 *
 * Quien instale llm-vcr para usarlo con PHPUnit o Pest a secas no debe
 * arrastrar medio framework. Estos tests blindan esa frontera: si alguien
 * importa una clase de Symfony fuera de src/Bridge/Symfony, fallan.
 */
final class OptionalDependencyTest extends TestCase
{
    private const NUCLEO = [
        'Cassette',
        'Contracts',
        'Drift',
        'Exception',
        'Matching',
        'Platform',
        'Redaction',
        'Testing',
    ];

    /** @return list<string> */
    private function ficherosDe(string $subdir): array
    {
        $base = \dirname(__DIR__, 3) . '/src/' . $subdir;

        if (!is_dir($base)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    #[Test]
    public function elNucleoNoDependeDeSymfony(): void
    {
        $infractores = [];

        foreach (self::NUCLEO as $subdir) {
            foreach ($this->ficherosDe($subdir) as $file) {
                $source = (string) file_get_contents($file);

                if (preg_match('/^use Symfony\\\\/m', $source) === 1) {
                    $infractores[] = str_replace(\dirname(__DIR__, 3) . '/', '', $file);
                }
            }
        }

        self::assertSame(
            [],
            $infractores,
            "Estos ficheros del núcleo importan Symfony. Muévelos a src/Bridge/Symfony:\n"
            . implode("\n", $infractores),
        );
    }

    #[Test]
    public function elNucleoTampocoDependeDePhpunitNiPest(): void
    {
        $infractores = [];

        foreach (self::NUCLEO as $subdir) {
            // src/Testing sí puede tocar PHPUnit: es su razón de ser.
            if ($subdir === 'Testing') {
                continue;
            }

            foreach ($this->ficherosDe($subdir) as $file) {
                $source = (string) file_get_contents($file);

                if (preg_match('/^use (PHPUnit|Pest)\\\\/m', $source) === 1) {
                    $infractores[] = str_replace(\dirname(__DIR__, 3) . '/', '', $file);
                }
            }
        }

        self::assertSame([], $infractores, implode("\n", $infractores));
    }

    #[Test]
    public function symfonyEstaDeclaradoComoOpcional(): void
    {
        /** @var array{require: array<string, string>, suggest: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach (array_keys($composer['require']) as $paquete) {
            self::assertStringStartsNotWith(
                'symfony/',
                $paquete,
                'Symfony no debe estar en "require": haría pesado el paquete para quien solo use PHPUnit.',
            );
        }

        self::assertArrayHasKey('symfony/framework-bundle', $composer['suggest']);
    }

    #[Test]
    public function elBundleViveAisladoEnSuPropioDirectorio(): void
    {
        $base = \dirname(__DIR__, 3) . '/src/Bridge/Symfony';

        self::assertDirectoryExists($base);
        self::assertFileExists($base . '/LlmVcrBundle.php');
        self::assertFileExists($base . '/PlatformFactory.php');
        self::assertFileExists($base . '/templates/Collector/llm_vcr.html.twig');
    }
}
