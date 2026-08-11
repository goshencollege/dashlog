<?php

namespace App\Command;

use App\Repository\LogObjectRepository;
use App\Repository\StorageBackendRepository;
use App\Service\LogObjectMigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:log-objects:migrate',
    description: 'Migrate all log objects from one storage backend to another',
)]
class LogObjectMigrateCommand extends Command
{
    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogObjectMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source storage backend ID')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Destination storage backend ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be migrated without doing it')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromId = $input->getOption('from');
        $toId = $input->getOption('to');
        if ($fromId === null || $toId === null) {
            $io->error('Both --from and --to are required.');

            return Command::INVALID;
        }

        $source = $this->storageBackendRepository->find((int) $fromId);
        $destination = $this->storageBackendRepository->find((int) $toId);

        if ($source === null) {
            $io->error("No storage backend found with id {$fromId}.");

            return Command::FAILURE;
        }
        if ($destination === null) {
            $io->error("No storage backend found with id {$toId}.");

            return Command::FAILURE;
        }
        if ($source->getId() === $destination->getId()) {
            $io->error('--from and --to must be different backends.');

            return Command::FAILURE;
        }

        $objects = $this->logObjectRepository->findMovableOnBackend($source);
        if ($objects === []) {
            $io->success("Nothing to migrate — no log objects found on \"{$source->getName()}\".");

            return Command::SUCCESS;
        }

        $io->text(sprintf(
            'Migrating %d log object(s) from "%s" to "%s"%s.',
            count($objects),
            $source->getName(),
            $destination->getName(),
            $input->getOption('dry-run') ? ' (dry run)' : '',
        ));

        if ($input->getOption('dry-run')) {
            foreach ($objects as $logObject) {
                $io->writeln(" - {$logObject->getObjectKey()}");
            }

            return Command::SUCCESS;
        }

        $progressBar = new ProgressBar($output, count($objects));
        $progressBar->start();

        $failures = 0;
        foreach ($objects as $logObject) {
            try {
                $this->migrationService->migrate($logObject, $destination);
            } catch (\Throwable $e) {
                $failures++;
                $io->newLine();
                $io->error(sprintf('Failed to migrate "%s": %s', $logObject->getObjectKey(), $e->getMessage()));
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $io->newLine(2);

        if ($failures > 0) {
            $io->warning("{$failures} object(s) failed to migrate and remain on \"{$source->getName()}\" (safe to retry by running this command again).");

            return Command::FAILURE;
        }

        $io->success("Migrated all log objects to \"{$destination->getName()}\".");

        return Command::SUCCESS;
    }
}
