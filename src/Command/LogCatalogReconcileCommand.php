<?php

namespace App\Command;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Repository\LogObjectRepository;
use App\Repository\StorageBackendRepository;
use App\Service\BatchWindowCalculator;
use App\Service\KeyScheme;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the LogObject catalog by rescanning a backend's contents — the
 * "the database was lost, rediscover what's actually in storage" path.
 * Only upserts missing catalog rows; it does not rebuild the per-line
 * LogEntry search index (that's a separate, much more expensive pass that
 * requires reading and re-parsing every object's content — a future
 * --reindex-entries addition, not implemented here).
 */
#[AsCommand(
    name: 'app:log-catalog:reconcile',
    description: 'Rescan storage backends and rebuild missing LogObject catalog rows',
)]
class LogCatalogReconcileCommand extends Command
{
    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly StorageService $storageService,
        private readonly KeyScheme $keyScheme,
        private readonly BatchWindowCalculator $windowCalculator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Only reconcile this storage backend ID (default: all configured backends)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('backend') !== null) {
            $backend = $this->storageBackendRepository->find((int) $input->getOption('backend'));
            if ($backend === null) {
                $io->error("No storage backend found with id {$input->getOption('backend')}.");

                return Command::FAILURE;
            }
            $backends = [$backend];
        } else {
            $backends = $this->storageBackendRepository->findAll();
        }

        foreach ($backends as $backend) {
            $io->section("Reconciling \"{$backend->getName()}\"");

            $created = 0;
            $skipped = 0;
            $unparseable = 0;
            $incompleteMeta = 0;

            foreach ($this->storageService->list($backend) as $item) {
                $key = $item['key'];
                if (!str_ends_with($key, '.log.gz')) {
                    continue;
                }

                if ($this->logObjectRepository->findOneByBackendAndKey($backend, $key) !== null) {
                    $skipped++;
                    continue;
                }

                $parsed = $this->keyScheme->parse($key);
                if ($parsed === null) {
                    $io->writeln("<comment>Skipping non-conforming key: {$key}</comment>");
                    $unparseable++;
                    continue;
                }

                $meta = $this->readMeta($backend, $key);
                if ($meta === null) {
                    $incompleteMeta++;
                }

                $logObject = new LogObject();
                $logObject->setStorageBackend($backend);
                $logObject->setObjectKey($key);
                $logObject->setSource($parsed['source']);
                $logObject->setTierRank($backend->getTierRank());
                $logObject->setWindowStart($parsed['windowStart']);
                $logObject->setWindowEnd($meta['windowEnd'] ?? $this->windowCalculator->windowEndFor($parsed['windowStart']));
                $logObject->setSizeBytes($meta['sizeBytes'] ?? $item['size']);
                $logObject->setChecksumSha256($meta['checksumSha256'] ?? null);
                $logObject->setEntryCount($meta['entryCount'] ?? null);
                $logObject->setStatus('stored');
                $this->em->persist($logObject);
                $created++;
            }

            $this->em->flush();

            $io->text([
                "Created: {$created}",
                "Already cataloged: {$skipped}",
                "Non-conforming keys skipped: {$unparseable}",
                "Missing/unreadable meta.json (best-effort fallback used): {$incompleteMeta}",
            ]);
        }

        $io->success('Reconciliation complete.');

        return Command::SUCCESS;
    }

    /** @return array{windowEnd: \DateTimeImmutable, sizeBytes: int, checksumSha256: string, entryCount: int}|null */
    private function readMeta(StorageBackend $backend, string $key): ?array
    {
        try {
            $raw = $this->storageService->read($backend, $this->keyScheme->metaKeyFor($key));
            $meta = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);

            return [
                'windowEnd' => new \DateTimeImmutable($meta['windowEnd']),
                'sizeBytes' => (int) $meta['sizeBytes'],
                'checksumSha256' => (string) $meta['checksumSha256'],
                'entryCount' => (int) $meta['entryCount'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
