<?php

namespace App\Tests\Controller;

use App\Entity\SamlProvider;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Security\SamlUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageBackendControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SamlProvider')->execute();
    }

    /**
     * Logs in as an admin. Roles are recomputed from the active SamlProvider's
     * roleAttribute on every request (SamlUserProvider::refreshUser), so a
     * matching provider + attribute must exist for ROLE_ADMIN to stick.
     */
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

        // Must match exactly what SamlUserProvider::refreshUser() will recompute on the
        // very next request (resolveRoles() always appends ROLE_USER) — ContextListener
        // deauthenticates the session if the role set changes between requests.
        $this->client->loginUser(new SamlUser('admin@example.test', ['groups' => ['ROLE_ADMIN']], ['ROLE_ADMIN', 'ROLE_USER']));

        return $this->client;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/storage-backends');

        self::assertResponseRedirects('/saml/login');
    }

    public function testIndexIsReachableByAdmin(): void
    {
        $client = $this->loginAsAdmin();
        $client->request('GET', '/storage-backends');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Storage Backends');
    }

    public function testCreateEditToggleAndDeleteLocalBackend(): void
    {
        $client = $this->loginAsAdmin();
        $tmpDir = sys_get_temp_dir() . '/dashlog-test-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);

        // Create
        $crawler = $client->request('GET', '/storage-backends/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create Backend')->form([
            'storage_backend[name]' => 'Test Local Backend',
            'storage_backend[type]' => 'local',
            'storage_backend[path]' => $tmpDir,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/storage-backends');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Test Local Backend');

        $backend = $this->em->getRepository(StorageBackend::class)->findOneBy(['name' => 'Test Local Backend']);
        self::assertNotNull($backend);
        self::assertSame(StorageBackendType::Local, $backend->getType());
        self::assertFalse($backend->isActive());

        // Test Connection — real Flysystem local adapter round trip against $tmpDir.
        // The button isn't a <form>, so its CSRF token is scraped from the data attribute.
        $indexCrawler = $client->getCrawler();
        $testToken    = $indexCrawler->filter('.quick-action-btn')->attr('data-csrf-token');
        $activateForm = $indexCrawler->selectButton('Activate')->form();

        // The UI only renders "Delete" while inactive, so grab its token now — CSRF
        // tokens are keyed by session + token id, not by current entity state, so this
        // stays valid later when we deliberately try to delete the now-active backend.
        $deleteTokenWhileInactive = $indexCrawler->selectButton('Delete')->form()->get('_token')->getValue();

        $client->request(
            'POST',
            "/storage-backends/{$backend->getId()}/test",
            server: ['HTTP_X-CSRF-Token' => $testToken]
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('error', $payload, (string) json_encode($payload));

        $this->em->refresh($backend);
        self::assertSame('ok', $backend->getLastCheckStatus());
        self::assertNotNull($backend->getLastCheckedAt());

        // Toggle active — submitting the real <form> carries its own valid CSRF token.
        $client->submit($activateForm);
        self::assertResponseRedirects('/storage-backends');
        $this->em->refresh($backend);
        self::assertTrue($backend->isActive());

        // Deleting while active is blocked (defense-in-depth; the UI itself hides
        // the Delete button once a backend is active).
        $client->request('POST', "/storage-backends/{$backend->getId()}/delete", [
            '_token' => $deleteTokenWhileInactive,
        ]);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Cannot delete an active storage backend');
        self::assertNotNull($this->em->getRepository(StorageBackend::class)->find($backend->getId()));

        // Deactivate then delete succeeds
        $client->submit($client->getCrawler()->selectButton('Deactivate')->form());
        $client->followRedirect();
        $client->submit($client->getCrawler()->selectButton('Delete')->form());
        self::assertResponseRedirects('/storage-backends');

        $this->em->clear();
        self::assertNull($this->em->getRepository(StorageBackend::class)->find($backend->getId()));

        rmdir($tmpDir);
    }

    public function testCifsBackendRequiresPasswordOnCreateAndKeepsItOnEdit(): void
    {
        $client = $this->loginAsAdmin();

        $crawler = $client->request('GET', '/storage-backends/new');
        $form = $crawler->selectButton('Create Backend')->form([
            'storage_backend[name]'         => 'Test CIFS Backend',
            'storage_backend[type]'         => 'cifs',
            'storage_backend[cifsHost]'     => 'fileserver.example.invalid',
            'storage_backend[cifsShare]'    => 'logs',
            'storage_backend[cifsUsername]' => 'svc-dashlog',
            // cifsPassword intentionally left blank
        ]);
        $client->submit($form);

        // Missing required password re-renders the form with an error (Symfony returns
        // 422 for an invalid form submission), not a redirect.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'A password is required for CIFS backends.');
        self::assertNull($this->em->getRepository(StorageBackend::class)->findOneBy(['name' => 'Test CIFS Backend']));

        // Resubmit with a password, from the freshly re-rendered form.
        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Create Backend')->form([
            'storage_backend[name]'         => 'Test CIFS Backend',
            'storage_backend[type]'         => 'cifs',
            'storage_backend[cifsHost]'     => 'fileserver.example.invalid',
            'storage_backend[cifsShare]'    => 'logs',
            'storage_backend[cifsUsername]' => 'svc-dashlog',
            'storage_backend[cifsPassword]' => 'correct-horse-battery-staple',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/storage-backends');

        $backend = $this->em->getRepository(StorageBackend::class)->findOneBy(['name' => 'Test CIFS Backend']);
        self::assertNotNull($backend);
        self::assertNotNull($backend->getCifsPassword());
        self::assertStringStartsWith('enc:', $backend->getCifsPassword());
        self::assertStringNotContainsString('correct-horse-battery-staple', $backend->getCifsPassword());

        $storedEncryptedPassword = $backend->getCifsPassword();

        // Editing without touching the password field must preserve the existing encrypted value.
        $crawler = $client->request('GET', "/storage-backends/{$backend->getId()}/edit");
        $form = $crawler->selectButton('Save Changes')->form([
            'storage_backend[cifsRemotePath]' => 'archive',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/storage-backends');

        $this->em->refresh($backend);
        self::assertSame($storedEncryptedPassword, $backend->getCifsPassword());
        self::assertSame('archive', $backend->getCifsRemotePath());
    }
}
