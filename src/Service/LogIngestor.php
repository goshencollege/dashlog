<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogObject;
use Psr\Log\LoggerInterface;

/**
 * Buffers ingested log lines in memory, grouped by (source, time window),
 * and flushes closed windows to storage via LogBatchWriter. This is the
 * single funnel every ingestion source — syslog today, HTTP later — feeds
 * through, which is what keeps adding a new transport cheap: it only needs
 * to produce IngestedLogLine values and call ingest().
 *
 * Two separate flush paths, matching LogBatchWriter's split:
 *
 *  - flushForVisibility() records newly-arrived lines as LogEntry rows
 *    (browsable immediately) without waiting for their window to close.
 *  - flushExpiredWindows() closes out windows once they're done, building
 *    the actual storage object from everything the window received.
 *
 * The buffer is in-process only — a crash loses whatever window hasn't
 * closed yet, and a failed flush just leaves a window buffered for the next
 * attempt rather than dropping it. Durable buffering across restarts/backend
 * outages is the write-ahead spool phase; this is a deliberately simpler
 * stand-in until that lands.
 */
class LogIngestor
{
    /** @var array<string, array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable, logObject: LogObject|null, lines: IngestedLogLine[], pendingEntryLines: IngestedLogLine[]}>> */
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
            'logObject' => null,
            'lines' => [],
            'pendingEntryLines' => [],
        ];
        $bucket['lines'][] = $line;
        $bucket['pendingEntryLines'][] = $line;
    }

    /**
     * Records whatever has arrived since the last call as LogEntry rows,
     * so it's immediately browsable/searchable without waiting for its
     * window to close. Meant to be called on a short, fixed interval (see
     * SyslogListenCommand) — not per-line, to keep the write rate sane.
     */
    public function flushForVisibility(): void
    {
        foreach ($this->buffers as $source => &$windows) {
            foreach ($windows as &$bucket) {
                if ($bucket['pendingEntryLines'] === []) {
                    continue;
                }

                try {
                    $bucket['logObject'] = $this->batchWriter->recordEntries(
                        $source,
                        $bucket['start'],
                        $bucket['end'],
                        $bucket['pendingEntryLines'],
                        $bucket['logObject'],
                    );
                    $bucket['pendingEntryLines'] = [];
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to record log entries for visibility, will retry next tick.', [
                        'source' => $source,
                        'windowStart' => $bucket['start']->format(\DateTimeInterface::ATOM),
                        'exception' => $e,
                    ]);
                }
            }
        }
        unset($windows, $bucket);
    }

    public function flushExpiredWindows(\DateTimeImmutable $now, int $graceSeconds = 30): void
    {
        foreach ($this->buffers as $source => &$windows) {
            foreach ($windows as $windowKey => &$bucket) {
                if ($now < $bucket['end']->modify("+{$graceSeconds} seconds")) {
                    continue;
                }

                try {
                    if ($bucket['pendingEntryLines'] !== []) {
                        $bucket['logObject'] = $this->batchWriter->recordEntries(
                            $source,
                            $bucket['start'],
                            $bucket['end'],
                            $bucket['pendingEntryLines'],
                            $bucket['logObject'],
                        );
                        $bucket['pendingEntryLines'] = [];
                    }

                    $this->batchWriter->finalize($source, $bucket['start'], $bucket['end'], $bucket['lines'], $bucket['logObject']);
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
        unset($windows, $bucket);
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
