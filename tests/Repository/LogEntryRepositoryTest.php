<?php

namespace App\Tests\Repository;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Repository\LogEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogEntryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LogEntryRepository $repo;
    private LogObject $logObject;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(LogEntryRepository::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $backend = new StorageBackend();
        $backend->setName('Test Backend');
        $backend->setType(StorageBackendType::Local);
        $backend->setPath('/tmp/unused-in-this-test');
        $this->em->persist($backend);

        $this->logObject = new LogObject();
        $this->logObject->setStorageBackend($backend);
        $this->logObject->setObjectKey('web-01/2026/08/11/00-00.log.gz');
        $this->logObject->setSource('web-01');
        $this->logObject->setWindowStart(new \DateTimeImmutable('2026-08-11T00:00:00+00:00'));
        $this->logObject->setWindowEnd(new \DateTimeImmutable('2026-08-11T00:15:00+00:00'));
        $this->logObject->setSizeBytes(1);
        $this->logObject->setEntryCount(1);
        $this->logObject->setStatus('stored');
        $this->em->persist($this->logObject);
        $this->em->flush();
    }

    public function testSearchWithNoFiltersReturnsAllOrderedByTimestampDescending(): void
    {
        $this->makeEntry('web-01', 5, 'first', new \DateTimeImmutable('2026-08-11T10:00:00+00:00'));
        $this->makeEntry('web-01', 5, 'second', new \DateTimeImmutable('2026-08-11T11:00:00+00:00'));

        $result = $this->repo->search([], 1, 50);

        self::assertSame(2, $result['total']);
        self::assertSame('second', $result['results'][0]->getMessage());
        self::assertSame('first', $result['results'][1]->getMessage());
    }

    public function testFilterBySourceIsPartialMatch(): void
    {
        $this->makeEntry('web-01', 5, 'a', new \DateTimeImmutable());
        $this->makeEntry('db-01', 5, 'b', new \DateTimeImmutable());

        $result = $this->repo->search(['source' => 'web'], 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('web-01', $result['results'][0]->getSource());
    }

    public function testFilterBySeverityIsExactMatch(): void
    {
        $this->makeEntry('web-01', 3, 'error line', new \DateTimeImmutable());
        $this->makeEntry('web-01', 6, 'info line', new \DateTimeImmutable());

        $result = $this->repo->search(['severity' => 3], 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('error line', $result['results'][0]->getMessage());
    }

    public function testFilterBySeverityZeroIsNotTreatedAsEmpty(): void
    {
        $this->makeEntry('web-01', 0, 'emergency', new \DateTimeImmutable());
        $this->makeEntry('web-01', 6, 'info', new \DateTimeImmutable());

        $result = $this->repo->search(['severity' => 0], 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('emergency', $result['results'][0]->getMessage());
    }

    public function testFilterByMessageSubstring(): void
    {
        $this->makeEntry('web-01', 5, 'connection refused', new \DateTimeImmutable());
        $this->makeEntry('web-01', 5, 'all good', new \DateTimeImmutable());

        $result = $this->repo->search(['message' => 'refused'], 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('connection refused', $result['results'][0]->getMessage());
    }

    public function testFilterByTimeRange(): void
    {
        $this->makeEntry('web-01', 5, 'too early', new \DateTimeImmutable('2026-08-11T08:00:00+00:00'));
        $this->makeEntry('web-01', 5, 'in range', new \DateTimeImmutable('2026-08-11T10:00:00+00:00'));
        $this->makeEntry('web-01', 5, 'too late', new \DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        $result = $this->repo->search([
            'from' => new \DateTimeImmutable('2026-08-11T09:00:00+00:00'),
            'to' => new \DateTimeImmutable('2026-08-11T11:00:00+00:00'),
        ], 1, 50);

        self::assertSame(1, $result['total']);
        self::assertSame('in range', $result['results'][0]->getMessage());
    }

    public function testPaginationSplitsResultsAcrossPages(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeEntry('web-01', 5, "line {$i}", new \DateTimeImmutable("2026-08-11T10:0{$i}:00+00:00"));
        }

        $page1 = $this->repo->search([], 1, 2);
        $page2 = $this->repo->search([], 2, 2);
        $page3 = $this->repo->search([], 3, 2);

        self::assertSame(5, $page1['total']);
        self::assertCount(2, $page1['results']);
        self::assertCount(2, $page2['results']);
        self::assertCount(1, $page3['results']);
        self::assertSame('line 4', $page1['results'][0]->getMessage());
    }

    public function testFindNewerThanReturnsOnlyEntriesAfterCursorOrderedAscending(): void
    {
        $first = $this->makeEntry('web-01', 5, 'first', new \DateTimeImmutable());
        $second = $this->makeEntry('web-01', 5, 'second', new \DateTimeImmutable());
        $third = $this->makeEntry('web-01', 5, 'third', new \DateTimeImmutable());

        $result = $this->repo->findNewerThan($first->getId(), []);

        self::assertCount(2, $result);
        self::assertSame($second->getId(), $result[0]->getId());
        self::assertSame($third->getId(), $result[1]->getId());
    }

    public function testFindNewerThanWithZeroCursorReturnsEverything(): void
    {
        $this->makeEntry('web-01', 5, 'one', new \DateTimeImmutable());
        $this->makeEntry('web-01', 5, 'two', new \DateTimeImmutable());

        self::assertCount(2, $this->repo->findNewerThan(0, []));
    }

    public function testFindNewerThanAppliesTheSameFiltersAsSearch(): void
    {
        $matching = $this->makeEntry('web-01', 3, 'error line', new \DateTimeImmutable());
        $this->makeEntry('web-01', 6, 'info line', new \DateTimeImmutable());

        $result = $this->repo->findNewerThan(0, ['severity' => 3]);

        self::assertCount(1, $result);
        self::assertSame($matching->getId(), $result[0]->getId());
    }

    public function testFindNewerThanRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeEntry('web-01', 5, "line {$i}", new \DateTimeImmutable());
        }

        $result = $this->repo->findNewerThan(0, [], limit: 2);

        self::assertCount(2, $result);
    }

    private function makeEntry(string $source, int $severity, string $message, \DateTimeImmutable $timestamp): LogEntry
    {
        $entry = new LogEntry();
        $entry->setLogObject($this->logObject);
        $entry->setSource($source);
        $entry->setTimestamp($timestamp);
        $entry->setSeverity($severity);
        $entry->setFacility(4);
        $entry->setMessage($message);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
