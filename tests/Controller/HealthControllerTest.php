<?php

namespace App\Tests\Controller;

use App\Entity\SamlProvider;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Security\SamlUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(Connection::class)->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");
        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SamlProvider')->execute();
    }

    private function loginAsAdmin(): KernelBrowser
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

        $this->client->loginUser(new SamlUser('admin@example.test', ['groups' => ['ROLE_ADMIN']], ['ROLE_ADMIN', 'ROLE_USER']));

        return $this->client;
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

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseRedirects('/saml/login');
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = $this->loginAsUser();
        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesHealthySnapshotWithNoIssues(): void
    {
        $client = $this->loginAsAdmin();

        $backend = new StorageBackend();
        $backend->setName('Real Backend');
        $backend->setType(StorageBackendType::Local);
        $backend->setPath(sys_get_temp_dir());
        $backend->setIsActive(true);
        $this->em->persist($backend);
        $this->em->flush();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'All systems healthy');
    }

    public function testAdminSeesWarningWhenNoActiveBackend(): void
    {
        $client = $this->loginAsAdmin();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Issues found');
        self::assertSelectorTextContains('body', 'No active storage backend is configured');
    }
}
