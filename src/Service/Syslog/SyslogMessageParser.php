<?php

namespace App\Service\Syslog;

use App\Dto\IngestedLogLine;

/**
 * Parses a raw syslog datagram into an IngestedLogLine — RFC 5424 first
 * (modern, structured), then RFC 3164 (classic BSD), then a raw passthrough
 * for anything that matches neither so no message is ever dropped just
 * because it doesn't parse cleanly.
 */
class SyslogMessageParser
{
    // SD (structured data) is only recognized as "-" or bracket groups with no
    // nested brackets/escaped ']' — real RFC 5424 SD can be richer than that,
    // but this covers the common case without a full param-value parser.
    private const RFC5424_PATTERN = '/^<(?<pri>\d{1,3})>(?<version>\d)\s(?<timestamp>\S+)\s(?<hostname>\S+)\s(?<appname>\S+)\s(?<procid>\S+)\s(?<msgid>\S+)\s(?<sd>-|(?:\[[^\]]*\])+)\s?(?<message>.*)$/su';

    private const RFC3164_PATTERN = '/^<(?<pri>\d{1,3})>(?<timestamp>[A-Z][a-z]{2}\s+\d{1,2}\s\d{2}:\d{2}:\d{2})\s(?<hostname>\S+)\s(?<tag>[^:\s\[]+)(?:\[(?<procid>\d+)\])?:?\s*(?<message>.*)$/su';

    public function parse(string $raw, string $peerAddress, \DateTimeImmutable $receivedAt): IngestedLogLine
    {
        $raw = rtrim($raw, "\r\n");

        if (preg_match(self::RFC5424_PATTERN, $raw, $m) === 1) {
            return $this->fromRfc5424($m, $raw, $peerAddress, $receivedAt);
        }

        if (preg_match(self::RFC3164_PATTERN, $raw, $m) === 1) {
            return $this->fromRfc3164($m, $raw, $peerAddress, $receivedAt);
        }

        return new IngestedLogLine(
            source: $peerAddress,
            timestamp: $receivedAt,
            host: null,
            appName: null,
            procId: null,
            severity: null,
            facility: null,
            message: $raw,
            raw: $raw,
        );
    }

    /** @param array<string, string> $m */
    private function fromRfc5424(array $m, string $raw, string $peerAddress, \DateTimeImmutable $receivedAt): IngestedLogLine
    {
        [$facility, $severity] = $this->splitPriority((int) $m['pri']);
        $hostname = $m['hostname'] === '-' ? null : $m['hostname'];

        try {
            $timestamp = $m['timestamp'] === '-' ? $receivedAt : new \DateTimeImmutable($m['timestamp']);
        } catch (\Exception) {
            $timestamp = $receivedAt;
        }

        return new IngestedLogLine(
            source: $hostname ?? $peerAddress,
            timestamp: $timestamp,
            host: $hostname,
            appName: $m['appname'] === '-' ? null : $m['appname'],
            procId: $m['procid'] === '-' ? null : $m['procid'],
            severity: $severity,
            facility: $facility,
            message: $m['message'],
            raw: $raw,
        );
    }

    /** @param array<string, string> $m */
    private function fromRfc3164(array $m, string $raw, string $peerAddress, \DateTimeImmutable $receivedAt): IngestedLogLine
    {
        [$facility, $severity] = $this->splitPriority((int) $m['pri']);

        return new IngestedLogLine(
            source: $m['hostname'],
            timestamp: $this->parseRfc3164Timestamp($m['timestamp'], $receivedAt),
            host: $m['hostname'],
            appName: $m['tag'],
            procId: ($m['procid'] ?? '') !== '' ? $m['procid'] : null,
            severity: $severity,
            facility: $facility,
            message: $m['message'],
            raw: $raw,
        );
    }

    private function parseRfc3164Timestamp(string $value, \DateTimeImmutable $receivedAt): \DateTimeImmutable
    {
        // RFC 3164 timestamps carry no year or timezone — assume the receiving
        // year/zone, then roll back a year if that lands implausibly in the
        // future (e.g. a "Dec 31" message arriving just after midnight Jan 1st).
        $withYear = $receivedAt->format('Y') . ' ' . $value;
        $parsed = \DateTimeImmutable::createFromFormat('Y M j H:i:s', $withYear, $receivedAt->getTimezone());

        if ($parsed === false) {
            return $receivedAt;
        }

        if ($parsed->getTimestamp() - $receivedAt->getTimestamp() > 86400) {
            $parsed = $parsed->modify('-1 year');
        }

        return $parsed;
    }

    /** @return array{0: int, 1: int} */
    private function splitPriority(int $pri): array
    {
        return [intdiv($pri, 8), $pri % 8];
    }
}
