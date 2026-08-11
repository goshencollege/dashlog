<?php

namespace App\Tests\Service;

use App\Service\KeyScheme;
use PHPUnit\Framework\TestCase;

class KeySchemeTest extends TestCase
{
    private KeyScheme $keyScheme;

    protected function setUp(): void
    {
        $this->keyScheme = new KeyScheme();
    }

    public function testBuildProducesExpectedKey(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');

        self::assertSame(
            'web-01/2026/08/11/14-15.log.gz',
            $this->keyScheme->build('web-01', $windowStart),
        );
    }

    public function testBuildSlugifiesSource(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');

        self::assertSame(
            'my-host-example-com/2026/08/11/00-00.log.gz',
            $this->keyScheme->build('My_Host.Example.com', $windowStart),
        );
    }

    public function testBuildNormalizesNonUtcTimezonesToUtc(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T10:15:00-04:00');

        self::assertSame(
            'web-01/2026/08/11/14-15.log.gz',
            $this->keyScheme->build('web-01', $windowStart),
        );
    }

    public function testBuildRejectsSourceThatSlugifiesToEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->keyScheme->build('***', new \DateTimeImmutable());
    }

    public function testParseIsTheInverseOfBuild(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $key         = $this->keyScheme->build('web-01', $windowStart);

        $parsed = $this->keyScheme->parse($key);

        self::assertNotNull($parsed);
        self::assertSame('web-01', $parsed['source']);
        self::assertEquals($windowStart, $parsed['windowStart']);
    }

    public function testParseReturnsNullForNonConformingKeys(): void
    {
        self::assertNull($this->keyScheme->parse('not-a-log-object.txt'));
        self::assertNull($this->keyScheme->parse('web-01/2026/13/40/25-99.log.gz'));
        self::assertNull($this->keyScheme->parse('web-01/2026/08/11/14-15.meta.json'));
    }

    public function testMetaKeyForAppendsMetaJsonSuffix(): void
    {
        self::assertSame(
            'web-01/2026/08/11/14-15.meta.json',
            $this->keyScheme->metaKeyFor('web-01/2026/08/11/14-15.log.gz'),
        );
    }

    public function testMetaKeyForRejectsNonLogObjectKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->keyScheme->metaKeyFor('web-01/2026/08/11/14-15.txt');
    }
}
