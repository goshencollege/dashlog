<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use Psr\Log\LoggerInterface;

/**
 * Buffers ingested log lines in memory, grouped by (source, time window),
 * and flushes closed windows to storage via LogBatchWriter. This is the
 * single funnel every ingestion source — syslog today, HTTP later — feeds
 * through, which is what keeps adding a new transport cheap: it only needs
 * to produce IngestedLogLine values and call ingest().
 *
 * The buffer is in-process only — a crash loses whatever window hasn't
 * closed yet, and a failed flush just leaves a window buffered for the next
 * attempt rather than dropping it. Durable buffering across restarts/backend
 * outages is the write-ahead spool phase; this is a deliberately simpler
 * stand-in until that lands.
 */
class LogIngestor
{
    /** @var array<string, array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable, lines: IngestedLogLine[]}>> */
    private array $buffers = [];

    public function __construct(
        private readonly BatchWindowCalculator $windowCalculator,
        private readonly LogBatchWriter $batchWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ingest(IngestedLogLine $line): void
    {
        $windowStart = $this->windowCalculator->windowStartFor($line->timestamp);
        $windowKey = $windowStart->format('YmdHi');

        $bucket = &$this->buffers[$line->source][$windowKey];
        $bucket ??= [
            'start' => $windowStart,
            'end' => $this->windowCalculator->windowEndFor($windowStart),
            'lines' => [],
        ];
        $bucket['lines'][] = $line;
    }

    public function flushExpiredWindows(\DateTimeImmutable $now, int $graceSeconds = 30): void
    {
        foreach ($this->buffers as $source => $windows) {
            foreach ($windows as $windowKey => $bucket) {
                if ($now < $bucket['end']->modify("+{$graceSeconds} seconds")) {
                    continue;
                }

                try {
                    $this->batchWriter->write($source, $bucket['start'], $bucket['end'], $bucket['lines']);
                    unset($this->buffers[$source][$windowKey]);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to flush log batch, will retry next tick.', [
                        'source' => $source,
                        'windowStart' => $bucket['start']->format(\DateTimeInterface::ATOM),
                        'exception' => $e,
                    ]);
                }
            }

            if ($this->buffers[$source] === []) {
                unset($this->buffers[$source]);
            }
        }
    }

    public function flushAll(\DateTimeImmutable $now): void
    {
        // Force every currently-open window closed too, not just expired
        // ones — used on graceful shutdown so nothing sits buffered
        // indefinitely once the process stops receiving new traffic.
        foreach ($this->buffers as &$windows) {
            foreach ($windows as &$bucket) {
                $bucket['end'] = $now;
            }
        }
        unset($windows, $bucket);

        $this->flushExpiredWindows($now, graceSeconds: 0);
    }
}
