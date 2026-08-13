<?php

namespace App\Tests\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\HealthCheckService;
use App\Service\SpoolProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class HealthCheckServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private HealthCheckService $healthCheckService;
    private StorageBackend $spool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->healthCheckService = self::getContainer()->get(HealthCheckService::class);

        $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");
        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->spool = self::getContainer()->get(SpoolProvider::class)->getSpool();
    }

    public function testHealthyStateReportsNoIssues(): void
    {
        $this->makeLogEntry('web-01', 'all good');

        $health = $this->healthCheckService->check();

        self::assertTrue($health['hasActiveBackend']);
        self::assertSame(0, $health['spoolBacklogCount']);
        self::assertSame([], $health['catalogErrors']);
        self::assertSame(0, $health['failedMessageCount']);
        self::assertNotNull($health['lastLogEntry']);
    }

    public function testNoActiveBackendIsReported(): void
    {
        $this->makeBackend('Inactive Backend', isActive: false);

        $health = $this->healthCheckService->check();

        self::assertFalse($health['hasActiveBackend']);
    }

    public function testSpoolBacklogIsReportedOldestFirst(): void
    {
        $older = $this->makeSpoolObject('web-01', 'staged');
        $newer = $this->makeSpoolObject('web-02', 'staged');

        $health = $this->healthCheckService->check();

        self::assertSame(2, $health['spoolBacklogCount']);
        self::assertSame($older->getId(), $health['spoolObjects'][0]['object']->getId());
        self::assertSame($newer->getId(), $health['spoolObjects'][1]['object']->getId());
    }

    public function testFreshStagedObjectIsNotFlaggedAsStale(): void
    {
        $this->makeSpoolObject('web-01', 'staged');

        $health = $this->healthCheckService->check();

        self::assertSame(1, $health['spoolBacklogCount']);
        self::assertFalse($health['hasStaleSpoolBacklog']);
    }

    public function testStagedObjectSittingForSeveralDrainCyclesIsFlaggedAsStale(): void
    {
        $object = $this->makeSpoolObject('web-01', 'staged');
        $this->ageUpdatedAt($object, new \DateTimeImmutable('-1 hour'));

        $health = $this->healthCheckService->check();

        self::assertTrue($health['hasStaleSpoolBacklog']);
        self::assertSame(1, $health['staleSpoolBacklogCount']);
    }

    public function testFreshlyOpenedPendingObjectIsIncludedButNotFlaggedAsStale(): void
    {
        // makeSpoolObject's windowEnd defaults to "now" — a window that
        // just opened, same as real ingestion traffic between window
        // start and close.
        $this->makeSpoolObject('web-01', 'pending');

        $health = $this->healthCheckService->check();

        self::assertSame(1, $health['spoolBacklogCount']);
        self::assertFalse($health['hasStaleSpoolBacklog']);
    }

    public function testPendingObjectPastItsWindowByALongMarginIsFlaggedAsStale(): void
    {
        $object = $this->makeSpoolObject('web-01', 'pending');
        // Its window closed 2 hours ago and nothing ever finalized it —
        // this is exactly the "listener crashed mid-window" scenario
        // OrphanedLogObjectFinalizer eventually reclaims.
        $object->setWindowEnd(new \DateTimeImmutable('-2 hours'));
        $this->em->flush();

        $health = $this->healthCheckService->check();

        self::assertTrue($health['hasStaleSpoolBacklog']);
        self::assertSame(1, $health['staleSpoolBacklogCount']);
    }

    /**
     * Bypasses AuditListener's preUpdate (which would otherwise reset
     * updatedAt back to "now" on any ORM-tracked change) to simulate an
     * object that's genuinely been sitting untouched since $at.
     */
    private function ageUpdatedAt(LogObject $object, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE log_object SET updated_at = ? WHERE id = ?',
            [$at->format('Y-m-d H:i:s'), $object->getId()],
        );
        $this->em->clear();
    }

    public function testCatalogErrorsAreReported(): void
    {
        $backend = $this->makeBackend('Real Backend', isActive: true);
        $errored = new LogObject();
        $errored->setStorageBackend($backend);
        $errored->setObjectKey('web-01/2026/08/11/14-15.log.gz');
        $errored->setSource('web-01');
        $errored->setWindowStart(new \DateTimeImmutable());
        $errored->setWindowEnd(new \DateTimeImmutable());
        $errored->setSizeBytes(1);
        $errored->setEntryCount(1);
        $errored->setStatus('error');
        $errored->setLastError('checksum mismatch');
        $this->em->persist($errored);
        $this->em->flush();

        $health = $this->healthCheckService->check();

        self::assertCount(1, $health['catalogErrors']);
        self::assertSame('checksum mismatch', $health['catalogErrors'][0]->getLastError());
    }

    public function testFailedMessageCountIsReported(): void
    {
        $this->connection->insert('messenger_messages', [
            'body' => '{}',
            'headers' => '{}',
            'queue_name' => 'failed',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'available_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $health = $this->healthCheckService->check();

        self::assertSame(1, $health['failedMessageCount']);
    }

    private function makeBackend(string $name, bool $isActive): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName($name);
        $backend->setType(StorageBackendType::Local);
        $backend->setPath(sys_get_temp_dir());
        $backend->setIsActive($isActive);
        $this->em->persist($backend);
        $this->em->flush();

        return $backend;
    }

    private function makeSpoolObject(string $source, string $status): LogObject
    {
        $object = new LogObject();
        $object->setStorageBackend($this->spool);
        $object->setObjectKey("{$source}/2026/08/11/14-15.log.gz");
        $object->setSource($source);
        $object->setWindowStart(new \DateTimeImmutable());
        $object->setWindowEnd(new \DateTimeImmutable());
        $object->setSizeBytes(1);
        $object->setEntryCount(1);
        $object->setStatus($status);
        $this->em->persist($object);
        $this->em->flush();

        return $object;
    }

    private function makeLogEntry(string $source, string $message): LogEntry
    {
        $backend = $this->makeBackend('Backend For Entry ' . uniqid(), isActive: true);
        $logObject = new LogObject();
        $logObject->setStorageBackend($backend);
        $logObject->setObjectKey("{$source}/2026/08/11/14-15.log.gz");
        $logObject->setSource($source);
        $logObject->setWindowStart(new \DateTimeImmutable());
        $logObject->setWindowEnd(new \DateTimeImmutable());
        $logObject->setSizeBytes(1);
        $logObject->setEntryCount(1);
        $logObject->setStatus('staged');
        $this->em->persist($logObject);

        $entry = new LogEntry();
        $entry->setLogObject($logObject);
        $entry->setSource($source);
        $entry->setTimestamp(new \DateTimeImmutable());
        $entry->setMessage($message);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
