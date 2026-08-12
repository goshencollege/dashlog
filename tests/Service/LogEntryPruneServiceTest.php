<?php

namespace App\Tests\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\LogEntryPruneService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogEntryPruneServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->backend = new StorageBackend();
        $this->backend->setName('Test Backend');
        $this->backend->setType(StorageBackendType::Local);
        $this->backend->setPath('/tmp/unused-in-this-test');
        $this->em->persist($this->backend);
        $this->em->flush();
    }

    public function testPrunesEntriesAndFlipsCacheFlagForOldStoredObjects(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $object = $this->makeObject('stored', $now->modify('-40 days'));
        $entry = $this->makeEntry($object, $now->modify('-40 days'));
        $objectId = $object->getId();
        $entryId = $entry->getId();

        $deleted = $this->prune(retentionDays: 30)->run($now);
        $this->em->clear();

        self::assertSame(1, $deleted);
        self::assertNull($this->em->getRepository(LogEntry::class)->find($entryId));
        self::assertFalse($this->em->getRepository(LogObject::class)->find($objectId)->isEntriesCached());
    }

    public function testLeavesRecentObjectsUntouched(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $object = $this->makeObject('stored', $now->modify('-1 day'));
        $entry = $this->makeEntry($object, $now->modify('-1 day'));
        $objectId = $object->getId();
        $entryId = $entry->getId();

        $deleted = $this->prune(retentionDays: 30)->run($now);
        $this->em->clear();

        self::assertSame(0, $deleted);
        self::assertNotNull($this->em->getRepository(LogEntry::class)->find($entryId));
        self::assertTrue($this->em->getRepository(LogObject::class)->find($objectId)->isEntriesCached());
    }

    public function testLeavesPendingOrStagedObjectsAloneEvenIfOld(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $pending = $this->makeObject('pending', $now->modify('-40 days'));
        $pendingEntry = $this->makeEntry($pending, $now->modify('-40 days'));
        $staged = $this->makeObject('staged', $now->modify('-41 days'));
        $stagedEntry = $this->makeEntry($staged, $now->modify('-41 days'));
        $pendingId = $pending->getId();
        $stagedId = $staged->getId();
        $pendingEntryId = $pendingEntry->getId();
        $stagedEntryId = $stagedEntry->getId();

        $deleted = $this->prune(retentionDays: 30)->run($now);
        $this->em->clear();

        self::assertSame(0, $deleted);
        self::assertNotNull($this->em->getRepository(LogEntry::class)->find($pendingEntryId));
        self::assertNotNull($this->em->getRepository(LogEntry::class)->find($stagedEntryId));
        self::assertTrue($this->em->getRepository(LogObject::class)->find($pendingId)->isEntriesCached());
        self::assertTrue($this->em->getRepository(LogObject::class)->find($stagedId)->isEntriesCached());
    }

    public function testIsNoOpWhenRetentionIsDisabled(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $object = $this->makeObject('stored', $now->modify('-400 days'));
        $entry = $this->makeEntry($object, $now->modify('-400 days'));
        $entryId = $entry->getId();

        $deleted = $this->prune(retentionDays: 0)->run($now);
        $this->em->clear();

        self::assertSame(0, $deleted);
        self::assertNotNull($this->em->getRepository(LogEntry::class)->find($entryId));
    }

    private function prune(int $retentionDays): LogEntryPruneService
    {
        return new LogEntryPruneService($this->em, $retentionDays, new NullLogger());
    }

    private function makeObject(string $status, \DateTimeImmutable $windowEnd): LogObject
    {
        $object = new LogObject();
        $object->setStorageBackend($this->backend);
        $object->setObjectKey('web-01/' . $windowEnd->format('Y/m/d/H-i') . '.log.gz');
        $object->setSource('web-01');
        $object->setWindowStart($windowEnd->modify('-15 minutes'));
        $object->setWindowEnd($windowEnd);
        $object->setSizeBytes(1);
        $object->setEntryCount(1);
        $object->setStatus($status);
        $this->em->persist($object);
        $this->em->flush();

        return $object;
    }

    private function makeEntry(LogObject $object, \DateTimeImmutable $timestamp): LogEntry
    {
        $entry = new LogEntry();
        $entry->setLogObject($object);
        $entry->setSource($object->getSource());
        $entry->setTimestamp($timestamp);
        $entry->setSeverity(5);
        $entry->setMessage('test message');
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
