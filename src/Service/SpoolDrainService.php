<?php

namespace App\Service;

use App\Repository\LogObjectRepository;
use App\Repository\StorageBackendRepository;
use Psr\Log\LoggerInterface;

/**
 * Moves everything currently sitting on the write-ahead spool to the
 * hottest active real backend, regardless of a LogObject's current status —
 * anything found on the spool by definition still needs to leave it,
 * whether it's freshly staged, stuck 'error' from a prior failed drain, or
 * even 'stored' (e.g. rediscovered there by reconciliation after a catalog
 * loss). Runs frequently so spool residency stays brief under normal
 * operation; only lingers if no real backend is currently reachable.
 */
class SpoolDrainService
{
    public function __construct(
        private readonly SpoolProvider $spoolProvider,
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogObjectMigrationService $migrationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(): void
    {
        $spool = $this->spoolProvider->getSpool();
        $staged = $this->logObjectRepository->findBy(['storageBackend' => $spool]);

        if ($staged === []) {
            return;
        }

        $target = $this->storageBackendRepository->findActiveOrderedByTier()[0] ?? null;
        if ($target === null) {
            $this->logger->error('Cannot drain the write-ahead spool: no active storage backend is configured.', [
                'stagedCount' => count($staged),
            ]);

            return;
        }

        foreach ($staged as $logObject) {
            try {
                $this->migrationService->migrate($logObject, $target);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to drain a log object from the spool, will retry next sweep.', [
                    'logObjectId' => $logObject->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
