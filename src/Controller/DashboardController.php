<?php

namespace App\Controller;

use App\Entity\LogEntry;
use App\Enum\SyslogFacility;
use App\Enum\SyslogSeverity;
use App\Repository\LogEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    private const MAX_LIVE_UPDATE_ENTRIES = 200;

    public function __construct(
        private readonly int $logBrowsePageSize,
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(Request $request, LogEntryRepository $repo): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        [$filters, $rawFilters] = $this->parseFilters($request);

        $result = $repo->search($filters, $page, $this->logBrowsePageSize);
        $totalPages = max(1, (int) ceil($result['total'] / $this->logBrowsePageSize));

        return $this->render('dashboard/index.html.twig', [
            'entries' => $result['results'],
            'total' => $result['total'],
            'page' => min($page, $totalPages),
            'totalPages' => $totalPages,
            'filters' => $rawFilters,
            'severities' => SyslogSeverity::cases(),
            'latestEntryId' => $result['results'] === [] ? 0 : $result['results'][0]->getId(),
        ]);
    }

    /**
     * Polled by the browse page's "Live" toggle — entries newer than
     * sinceId matching the current filters, for appending to the table
     * without a full page reload. Deliberately plain polling rather than
     * a push mechanism (SSE/WebSockets/Mercure): this app has no existing
     * real-time infrastructure, and polling on the same few-second cadence
     * the ingestion pipeline already flushes on is enough for a "tail -f"
     * style view without adding a new moving part to the stack.
     */
    #[Route('/logs/updates', name: 'log_updates', methods: ['GET'])]
    public function updates(Request $request, LogEntryRepository $repo): JsonResponse
    {
        [$filters] = $this->parseFilters($request);
        $sinceId = max(0, (int) $request->query->get('sinceId', 0));

        $entries = $repo->findNewerThan($sinceId, $filters, self::MAX_LIVE_UPDATE_ENTRIES);

        return $this->json([
            'entries' => array_map($this->serializeEntry(...), $entries),
            'lastId' => $entries === [] ? $sinceId : $entries[count($entries) - 1]->getId(),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function parseFilters(Request $request): array
    {
        $sourceParam = trim((string) $request->query->get('source', ''));
        $messageParam = trim((string) $request->query->get('q', ''));
        $severityParam = (string) $request->query->get('severity', '');
        $fromParam = (string) $request->query->get('from', '');
        $toParam = (string) $request->query->get('to', '');

        $filters = array_filter([
            'source' => $sourceParam,
            'severity' => $severityParam !== '' ? (int) $severityParam : null,
            'message' => $messageParam,
            'from' => $this->parseDateTime($fromParam),
            'to' => $this->parseDateTime($toParam),
        ], static fn ($value) => $value !== null && $value !== '');

        $rawFilters = [
            'source' => $sourceParam,
            'q' => $messageParam,
            'severity' => $severityParam,
            'from' => $fromParam,
            'to' => $toParam,
        ];

        return [$filters, $rawFilters];
    }

    private function serializeEntry(LogEntry $entry): array
    {
        $severity = SyslogSeverity::tryFrom($entry->getSeverity() ?? -1);
        $facility = SyslogFacility::tryFrom($entry->getFacility() ?? -1);

        return [
            'id' => $entry->getId(),
            'timestamp' => $entry->getTimestamp()->format('Y-m-d H:i:s'),
            'severityLabel' => $severity?->label() ?? 'Unknown',
            'severityBadgeClass' => $severity?->badgeClass() ?? 'bg-secondary-subtle text-secondary-emphasis border',
            'source' => $entry->getSource(),
            'appName' => $entry->getAppName(),
            'procId' => $entry->getProcId(),
            'facilityLabel' => $facility?->label() ?? 'unknown',
            'message' => $entry->getMessage(),
        ];
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
