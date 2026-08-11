<?php

namespace App\Dto;

/**
 * A single parsed log line, independent of how it arrived. Every ingestion
 * source (syslog today, HTTP later) normalizes into this shape before
 * handing off to LogIngestor — that shared boundary is what lets a new
 * transport be added without touching batching/storage/indexing.
 */
final class IngestedLogLine
{
    public function __construct(
        public readonly string $source,
        public readonly \DateTimeImmutable $timestamp,
        public readonly ?string $host,
        public readonly ?string $appName,
        public readonly ?string $procId,
        public readonly ?int $severity,
        public readonly ?int $facility,
        public readonly string $message,
        public readonly string $raw,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'host' => $this->host,
            'appName' => $this->appName,
            'procId' => $this->procId,
            'severity' => $this->severity,
            'facility' => $this->facility,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
