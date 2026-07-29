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
    description: 'Detect whether the provider model changed compared to the recorded cassettes',
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
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Cassette directory')
            ->addOption('markdown', null, InputOption::VALUE_NONE, 'Markdown output, to paste into a PR')
            ->setHelp(<<<'TXT'
                Replays the recorded cassettes against the real provider and compares
                the current responses with the stored ones.

                Detects both content changes and <info>type</info> changes in the JSON,
                which are the ones that break your DTOs without you touching any code.

                Requires a service implementing PlatformInterface registered
                under the "llm_vcr.live_platform" alias.
                TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->livePlatform === null) {
            $io->error([
                'No live platform is configured.',
                'Register your LLM client as a service under the "llm_vcr.live_platform" alias.',
            ]);

            return Command::FAILURE;
        }

        /** @var string|null $dirOption */
        $dirOption = $input->getOption('dir');
        $dir = $dirOption ?? $this->factory->cassetteDir();

        $files = glob(rtrim($dir, '/') . '/*.json');

        if ($files === false || $files === []) {
            $io->warning(sprintf('No cassettes found in %s', $dir));

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
            $output->writeln('## Drift report — llm-vcr');
            $output->writeln('');
            $output->writeln('| Severity | Model | Similarity | Changes |');
            $output->writeln('|---|---|---|---|');
            foreach ($rows as $row) {
                $output->writeln(sprintf('| %s | `%s` | %s | %s |', ...$row));
            }

            return $worst;
        }

        $io->title('Drift report');
        $io->table(['Severity', 'Model', 'Similarity', 'Changes'], $rows);

        if ($worst === Command::SUCCESS) {
            $io->success(sprintf('No significant drift across %d interactions.', count($rows)));
        } else {
            $io->error('Drift detected. Review the prompts and re-record the affected cassettes.');
        }

        return $worst;
    }
}
