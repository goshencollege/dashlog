<?php

namespace App\Service;

use App\Entity\LogEntry;
use App\Repository\LogEntryRepository;
use App\Repository\LogObjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for the dashboard's browse/search: decides whether a
 * query can be answered entirely from the log_entry index (the common
 * case) or needs to also pull some results back from storage because
 * LogEntryPruneService has already deleted the relevant rows.
 *
 * Deliberately has no notion of a retention cutoff itself — it asks
 * LogObjectRepository which overlapping objects are no longer cached and
 * routes off that, so it stays correct regardless of scheduler timing or
 * later changes to the retention setting.
 */
class LogBrowseCoordinator
{
    public function __construct(
        private readonly LogEntryRepository $logEntryRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogArchiveSearchService $archiveSearchService,
        private readonly LoggerInterface $logger,
        // Safety cap on the DB side of a merge — the archive side is
        // naturally bounded by how many LogObjects overlap the query's
        // range, but the DB side here is unpaginated (needed so both
        // sources can be sorted and paginated together), so it gets its
        // own explicit limit.
        private readonly int $maxMergeDbResults = 10_000,
    ) {
    }

    /**
     * @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return array{results: LogEntry[], total: int, usingArchive: bool}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $uncached = $this->logObjectRepository->findUncachedOverlapping(
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['source'] ?? [],
        );

        if ($uncached === []) {
            $result = $this->logEntryRepository->search($filters, $page, $perPage);

            return $result + ['usingArchive' => false];
        }

        $archiveEntries = $this->archiveSearchService->searchObjects($uncached, $filters);
        $dbEntries = $this->logEntryRepository->findAllMatching($filters, $this->maxMergeDbResults);

        if (count($dbEntries) === $this->maxMergeDbResults) {
            $this->logger->warning('Archive-merged browse query hit the DB-side result cap of {cap}; results may be truncated.', [
                'cap' => $this->maxMergeDbResults,
            ]);
        }

        $merged = array_merge($dbEntries, $archiveEntries);
        usort($merged, static fn (LogEntry $a, LogEntry $b) => $b->getTimestamp() <=> $a->getTimestamp());

        $offset = max(0, $page - 1) * $perPage;

        return [
            'results' => array_slice($merged, $offset, $perPage),
            'total' => count($merged),
            'usingArchive' => true,
        ];
    }
}
