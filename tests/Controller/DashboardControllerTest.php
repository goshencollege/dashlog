<?php

namespace App\Tests\Controller;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\SamlProvider;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Security\SamlUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private LogObject $logObject;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SamlProvider')->execute();

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

    private function loginAsUser(): KernelBrowser
    {
        $provider = new SamlProvider();
        $provider->setName('Test Provider');
        $provider->setIsActive(true);
        $provider->setSpEntityId('https://localhost/saml/metadata');
        $provider->setSpAcsUrl('https://localhost/saml/acs');
        $provider->setSpSloUrl('https://localhost/saml/logout');
        $provider->setSpCert('test-cert');
        $provider->setSpPrivateKey('test-key');
        $provider->setIdpEntityId('https://idp.example.test/metadata');
        $provider->setIdpSsoUrl('https://idp.example.test/sso');
        $provider->setIdpCert('test-cert');
        $provider->setRoleAttribute('groups');
        $this->em->persist($provider);
        $this->em->flush();

        $this->client->loginUser(new SamlUser('user@example.test', ['groups' => []], ['ROLE_USER']));

        return $this->client;
    }

    private function makeEntry(string $source, ?int $severity, string $message, \DateTimeImmutable $timestamp): LogEntry
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

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/saml/login');
    }

    public function testEmptyStateIsShownWithNoEntries(): void
    {
        $client = $this->loginAsUser();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No log entries match these filters.');
    }

    public function testListsEntriesMostRecentFirst(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 6, 'first message', new \DateTimeImmutable('2026-08-11T10:00:00+00:00'));
        $this->makeEntry('web-01', 3, 'second message', new \DateTimeImmutable('2026-08-11T11:00:00+00:00'));

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('second message', $rows->eq(0)->text());
        self::assertStringContainsString('first message', $rows->eq(1)->text());
    }

    public function testFilterBySourceNarrowsResults(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 5, 'from web', new \DateTimeImmutable());
        $this->makeEntry('db-01', 5, 'from db', new \DateTimeImmutable());

        $crawler = $client->request('GET', '/', ['source' => 'web']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'from web');
        self::assertSelectorTextNotContains('body', 'from db');
        self::assertSelectorTextContains('body', '1 matching entry');
    }

    public function testFilterBySeverityNarrowsResults(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 3, 'error message', new \DateTimeImmutable());
        $this->makeEntry('web-01', 6, 'info message', new \DateTimeImmutable());

        $crawler = $client->request('GET', '/', ['severity' => '3']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'error message');
        self::assertSelectorTextNotContains('body', 'info message');
    }

    public function testNoPaginationControlsWhenEverythingFitsOnOnePage(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 5, 'only line', new \DateTimeImmutable());

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.card-footer'));
    }

    public function testLiveToggleOnlyAppearsOnPageOne(): void
    {
        $client = $this->loginAsUser();
        for ($i = 0; $i < 51; $i++) {
            $this->makeEntry('web-01', 5, "line {$i}", new \DateTimeImmutable(sprintf('2026-08-11T00:%02d:00+00:00', $i % 60)));
        }

        $crawler = $client->request('GET', '/');
        self::assertCount(1, $crawler->filter('#live-toggle'));

        $crawler = $client->request('GET', '/', ['page' => 2]);
        self::assertCount(0, $crawler->filter('#live-toggle'));
    }

    public function testUpdatesEndpointRequiresAuth(): void
    {
        $this->client->request('GET', '/logs/updates');

        self::assertResponseRedirects('/saml/login');
    }

    public function testUpdatesReturnsOnlyEntriesNewerThanSinceId(): void
    {
        $client = $this->loginAsUser();
        $old = $this->makeEntry('web-01', 5, 'old', new \DateTimeImmutable());
        $new = $this->makeEntry('web-01', 5, 'new', new \DateTimeImmutable());

        $client->request('GET', '/logs/updates', ['sinceId' => $old->getId()]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $payload['entries']);
        self::assertSame($new->getId(), $payload['entries'][0]['id']);
        self::assertSame('new', $payload['entries'][0]['message']);
        self::assertSame($new->getId(), $payload['lastId']);
    }

    public function testUpdatesResponseShapeIncludesDisplayFields(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 3, 'error line', new \DateTimeImmutable());

        $client->request('GET', '/logs/updates');

        $payload = json_decode($client->getResponse()->getContent(), true);
        $entry = $payload['entries'][0];
        self::assertSame('Error', $entry['severityLabel']);
        self::assertArrayHasKey('severityBadgeClass', $entry);
        self::assertSame('web-01', $entry['source']);
        self::assertArrayHasKey('timestamp', $entry);
        self::assertArrayHasKey('facilityLabel', $entry);
    }

    public function testUpdatesAppliesFilters(): void
    {
        $client = $this->loginAsUser();
        $this->makeEntry('web-01', 5, 'from web', new \DateTimeImmutable());
        $this->makeEntry('db-01', 5, 'from db', new \DateTimeImmutable());

        $client->request('GET', '/logs/updates', ['source' => 'web']);

        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $payload['entries']);
        self::assertSame('from web', $payload['entries'][0]['message']);
    }

    public function testUpdatesWithNothingNewReturnsEmptyAndUnchangedLastId(): void
    {
        $client = $this->loginAsUser();
        $entry = $this->makeEntry('web-01', 5, 'only one', new \DateTimeImmutable());

        $client->request('GET', '/logs/updates', ['sinceId' => $entry->getId()]);

        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $payload['entries']);
        self::assertSame($entry->getId(), $payload['lastId']);
    }

    public function testPaginationControlsAppearAndNavigateAcrossPages(): void
    {
        $client = $this->loginAsUser();
        // Default page size is 50 (LOG_BROWSE_PAGE_SIZE) — 51 rows forces a second page.
        for ($i = 0; $i < 51; $i++) {
            $entry = new LogEntry();
            $entry->setLogObject($this->logObject);
            $entry->setSource('web-01');
            $entry->setTimestamp(new \DateTimeImmutable(sprintf('2026-08-11T00:%02d:00+00:00', $i % 60)));
            $entry->setSeverity(5);
            $entry->setFacility(4);
            $entry->setMessage("line {$i}");
            $this->em->persist($entry);
        }
        $this->em->flush();

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Page 1 of 2');
        self::assertCount(50, $crawler->filter('tbody tr'));

        $crawler = $client->request('GET', '/', ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Page 2 of 2');
        self::assertCount(1, $crawler->filter('tbody tr'));
    }
}
