<?php

namespace App\Tests\Service\Syslog;

use App\Service\Syslog\SyslogMessageParser;
use PHPUnit\Framework\TestCase;

class SyslogMessageParserTest extends TestCase
{
    private SyslogMessageParser $parser;
    private \DateTimeImmutable $receivedAt;

    protected function setUp(): void
    {
        $this->parser = new SyslogMessageParser();
        $this->receivedAt = new \DateTimeImmutable('2026-08-11T14:23:00+00:00');
    }

    public function testParsesRfc5424Message(): void
    {
        $raw = '<34>1 2026-08-11T14:22:59.003Z web-01 sshd 1234 ID47 - Failed password for root';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('web-01', $line->source);
        self::assertSame('web-01', $line->host);
        self::assertSame('sshd', $line->appName);
        self::assertSame('1234', $line->procId);
        self::assertSame(4, $line->facility);
        self::assertSame(2, $line->severity);
        self::assertSame('Failed password for root', $line->message);
        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:22:59.003Z'), $line->timestamp);
        self::assertSame($raw, $line->raw);
    }

    public function testRfc5424WithNilHostnameFallsBackToPeerAddressAsSource(): void
    {
        $raw = '<34>1 2026-08-11T14:22:59.003Z - sshd 1234 ID47 - Failed password for root';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('10.0.0.5', $line->source);
        self::assertNull($line->host);
    }

    public function testRfc5424WithNilTimestampFallsBackToReceivedAt(): void
    {
        $raw = '<34>1 - web-01 sshd 1234 ID47 - Failed password for root';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertEquals($this->receivedAt, $line->timestamp);
    }

    public function testParsesRfc3164Message(): void
    {
        $raw = '<13>Aug 11 14:22:59 web-01 sshd[1234]: Failed password for root';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('web-01', $line->source);
        self::assertSame('web-01', $line->host);
        self::assertSame('sshd', $line->appName);
        self::assertSame('1234', $line->procId);
        self::assertSame(1, $line->facility);
        self::assertSame(5, $line->severity);
        self::assertSame('Failed password for root', $line->message);
        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:22:59+00:00'), $line->timestamp);
    }

    public function testRfc3164WithoutProcessIdParsesTagOnly(): void
    {
        $raw = '<13>Aug 11 14:22:59 web-01 sshd: Connection closed';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('sshd', $line->appName);
        self::assertNull($line->procId);
        self::assertSame('Connection closed', $line->message);
    }

    public function testRfc3164TimestampRollsBackAYearWhenImplausiblyFuture(): void
    {
        $receivedAt = new \DateTimeImmutable('2027-01-01T00:05:00+00:00');
        $raw = '<13>Dec 31 23:50:00 web-01 sshd[1]: shutting down';

        $line = $this->parser->parse($raw, '10.0.0.5', $receivedAt);

        self::assertEquals(new \DateTimeImmutable('2026-12-31T23:50:00+00:00'), $line->timestamp);
    }

    public function testUnparsableMessageFallsBackToRawPassthrough(): void
    {
        $raw = 'this is not a syslog-framed message at all';

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('10.0.0.5', $line->source);
        self::assertNull($line->host);
        self::assertNull($line->severity);
        self::assertNull($line->facility);
        self::assertSame($raw, $line->message);
        self::assertEquals($this->receivedAt, $line->timestamp);
    }

    public function testStripsTrailingNewlineFromRawMessage(): void
    {
        $raw = "<13>Aug 11 14:22:59 web-01 sshd[1234]: Failed password for root\r\n";

        $line = $this->parser->parse($raw, '10.0.0.5', $this->receivedAt);

        self::assertSame('Failed password for root', $line->message);
        self::assertStringEndsNotWith("\r\n", $line->raw);
    }
}
