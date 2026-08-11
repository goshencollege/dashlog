<?php

namespace App\Controller;

use App\Enum\SyslogSeverity;
use App\Repository\LogEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly int $logBrowsePageSize,
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(Request $request, LogEntryRepository $repo): Response
    {
        $sourceParam = trim((string) $request->query->get('source', ''));
        $messageParam = trim((string) $request->query->get('q', ''));
        $severityParam = (string) $request->query->get('severity', '');
        $fromParam = (string) $request->query->get('from', '');
        $toParam = (string) $request->query->get('to', '');
        $page = max(1, (int) $request->query->get('page', 1));

        $filters = array_filter([
            'source' => $sourceParam,
            'severity' => $severityParam !== '' ? (int) $severityParam : null,
            'message' => $messageParam,
            'from' => $this->parseDateTime($fromParam),
            'to' => $this->parseDateTime($toParam),
        ], static fn ($value) => $value !== null && $value !== '');

        $result = $repo->search($filters, $page, $this->logBrowsePageSize);
        $totalPages = max(1, (int) ceil($result['total'] / $this->logBrowsePageSize));

        return $this->render('dashboard/index.html.twig', [
            'entries' => $result['results'],
            'total' => $result['total'],
            'page' => min($page, $totalPages),
            'totalPages' => $totalPages,
            'filters' => [
                'source' => $sourceParam,
                'q' => $messageParam,
                'severity' => $severityParam,
                'from' => $fromParam,
                'to' => $toParam,
            ],
            'severities' => SyslogSeverity::cases(),
        ]);
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
