<?php

namespace App\DataFixtures;

use App\Entity\SamlProvider;
use App\Service\EncryptionService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

class SamlProviderFixtures extends Fixture
{
    public function __construct(private readonly EncryptionService $encryption) {}

    public function load(ObjectManager $manager): void
    {
        $local = $this->loadLocalConfig();

        $providers = $local['saml_providers'] ?? [
            [
                'name'          => 'Example Organization (dev)',
                'is_active'     => true,
                'sp_entity_id'  => 'https://localhost/saml/metadata',
                'sp_acs_url'    => 'https://localhost/saml/acs',
                'sp_slo_url'    => 'https://localhost/saml/logout',
                'idp_entity_id' => 'https://idp.example.com/saml2/idp/metadata.php',
                'idp_sso_url'   => 'https://idp.example.com/saml2/idp/SSOService.php',
                'idp_cert'      => 'PLACEHOLDER_REPLACE_WITH_REAL_IDP_CERT',
            ],
        ];

        foreach ($providers as $data) {
            ['cert' => $spCert, 'key' => $spKey] = $this->generateCertificate();

            $provider = new SamlProvider();
            $provider->setName($data['name']);
            $provider->setIsActive($data['is_active'] ?? false);
            $provider->setSpEntityId($data['sp_entity_id']);
            $provider->setSpAcsUrl($data['sp_acs_url']);
            $provider->setSpSloUrl($data['sp_slo_url']);
            $provider->setSpCert($spCert);
            $provider->setSpPrivateKey($this->encryption->encrypt($spKey));
            $provider->setIdpEntityId($data['idp_entity_id']);
            $provider->setIdpSsoUrl($data['idp_sso_url']);
            $provider->setIdpCert($data['idp_cert']);

            $manager->persist($provider);
        }

        $manager->flush();
    }

    private function generateCertificate(): array
    {
        $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $privKey = openssl_pkey_new($config);
        $csr     = openssl_csr_new(['commonName' => 'DashLog Dev SP'], $privKey, $config);
        $x509    = openssl_csr_sign($csr, null, $privKey, 3650, $config);

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $strip = static fn(string $pem): string => preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $pem);

        return ['cert' => $strip($certPem), 'key' => $strip($keyPem)];
    }

    private function loadLocalConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures.local.yaml';
        return file_exists($path) ? Yaml::parseFile($path) : [];
    }
}
