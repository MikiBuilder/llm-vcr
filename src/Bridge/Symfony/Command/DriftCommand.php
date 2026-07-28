<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Bridge\Symfony\Command;

use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use MikiBuilder\LlmVcr\Cassette\Cassette;
use MikiBuilder\LlmVcr\Contracts\PlatformInterface;
use MikiBuilder\LlmVcr\Drift\DriftDetector;
use MikiBuilder\LlmVcr\Drift\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comprueba si el proveedor ha cambiado el comportamiento del modelo.
 *
 *     bin/console llm-vcr:drift
 *
 * Pensado para un cron nocturno: consume cuota real del proveedor.
 * Devuelve código 1 si detecta deriva ALTA o CRÍTICA, para romper el build.
 */
#[AsCommand(
    name: 'llm-vcr:drift',
    description: 'Detecta si el modelo del proveedor ha cambiado respecto a las cassettes grabadas',
)]
final class DriftCommand extends Command
{
    public function __construct(
        private readonly PlatformFactory $factory,
        private readonly ?PlatformInterface $livePlatform = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directorio de cassettes')
            ->addOption('markdown', null, InputOption::VALUE_NONE, 'Salida en Markdown, para pegar en una PR')
            ->setHelp(<<<'TXT'
                Reproduce las cassettes grabadas contra el proveedor real y compara
                las respuestas actuales con las guardadas.

                Detecta tanto cambios de contenido como cambios de <info>tipo</info> en el JSON,
                que son los que rompen tus DTOs sin que hayas tocado el código.

                Requiere que un servicio implemente PlatformInterface y esté
                registrado como "llm_vcr.live_platform".
                TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->livePlatform === null) {
            $io->error([
                'No hay ninguna plataforma real configurada.',
                'Registra tu cliente LLM como servicio con el alias "llm_vcr.live_platform".',
            ]);

            return Command::FAILURE;
        }

        /** @var string|null $dirOption */
        $dirOption = $input->getOption('dir');
        $dir = $dirOption ?? $this->factory->cassetteDir();

        $files = glob(rtrim($dir, '/') . '/*.json');

        if ($files === false || $files === []) {
            $io->warning(sprintf('No hay cassettes en %s', $dir));

            return Command::SUCCESS;
        }

        $detector = new DriftDetector($this->livePlatform);
        $markdown = (bool) $input->getOption('markdown');

        $rows = [];
        $worst = Command::SUCCESS;

        foreach ($files as $file) {
            $cassette = Cassette::load(basename($file, '.json'), $file);

            foreach ($detector->analyze($cassette) as $report) {
                $severity = $report->severity();
                $worst = max($worst, $severity->exitCode());

                $rows[] = [
                    $severity->emoji() . ' ' . $severity->value,
                    $report->model,
                    sprintf('%.2f', $report->similarity),
                    $report->summary(),
                ];
            }
        }

        if ($markdown) {
            $output->writeln('## Informe de deriva — llm-vcr');
            $output->writeln('');
            $output->writeln('| Severidad | Modelo | Similitud | Cambios |');
            $output->writeln('|---|---|---|---|');
            foreach ($rows as $row) {
                $output->writeln(sprintf('| %s | `%s` | %s | %s |', ...$row));
            }

            return $worst;
        }

        $io->title('Informe de deriva');
        $io->table(['Severidad', 'Modelo', 'Similitud', 'Cambios'], $rows);

        if ($worst === Command::SUCCESS) {
            $io->success(sprintf('Sin deriva significativa en %d interacciones.', count($rows)));
        } else {
            $io->error('Deriva detectada. Revisa los prompts y regraba las cassettes afectadas.');
        }

        return $worst;
    }
}
