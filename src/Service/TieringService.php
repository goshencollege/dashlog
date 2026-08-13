<?php

namespace App\Service;

use App\Repository\LogObjectRepository;
use App\Repository\StorageBackendRepository;
use Psr\Log\LoggerInterface;

/**
 * Age-based auto-tiering sweep: for each active backend with a maxAgeDays
 * set, migrates any LogObject older than that to the next active backend by
 * tier rank. The coldest active backend has no "next" and is left alone
 * regardless of its maxAgeDays (there's nowhere colder to send it).
 */
class TieringService
{
    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogObjectMigrationService $migrationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(\DateTimeImmutable $now): void
    {
        $backends = $this->storageBackendRepository->findActiveOrderedByTier();

        for ($i = 0; $i < count($backends) - 1; $i++) {
            $current = $backends[$i];
            $next = $backends[$i + 1];

            if ($current->getMaxAgeDays() === null) {
                continue;
            }

            $cutoff = $now->modify("-{$current->getMaxAgeDays()} days");

            foreach ($this->logObjectRepository->findEligibleForTiering($current, $cutoff) as $logObject) {
                try {
                    $this->migrationService->migrate($logObject, $next);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to tier log object {logObjectId} from {from} to {to}, will retry next sweep: {error}', [
                        'logObjectId' => $logObject->getId(),
                        'from' => $current->getName(),
                        'to' => $next->getName(),
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }
        }
    }
}
