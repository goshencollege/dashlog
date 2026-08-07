<?php

namespace App\Command;

use App\Entity\SamlProvider;
use App\Repository\SamlProviderRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'app:saml:import-metadata',
    description: 'Import IdP SAML metadata from an XML file or URL and create or update a SAML provider record.',
)]
class SamlImportMetadataCommand extends Command
{
    public function __construct(
        private readonly SamlProviderRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly EncryptionService $encryption,
        private readonly RouterInterface $router,
        private readonly string $defaultUri,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the IdP metadata XML file, or a URL to fetch it from')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Provider name (prompted if omitted)')
            ->addOption('update-id', null, InputOption::VALUE_REQUIRED, 'ID of an existing provider to update instead of creating a new one')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Mark this provider as active after import')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SAML Metadata Import');

        // --- Parse the IdP metadata XML ---
        $source = $input->getArgument('file');

        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $io->text("Fetching metadata from <info>{$source}</info>…");
            $contents = @file_get_contents($source);
            if ($contents === false) {
                $io->error("Failed to fetch URL: {$source}");
                return Command::FAILURE;
            }
        } else {
            if (!is_file($source) || !is_readable($source)) {
                $io->error("Cannot read file: {$source}");
                return Command::FAILURE;
            }
            $contents = file_get_contents($source);
        }

        $xml = @simplexml_load_string($contents);
        if ($xml === false) {
            $io->error('Failed to parse XML. Check that the source is a valid SAML metadata document.');
            return Command::FAILURE;
        }

        $xml->registerXPathNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $xml->registerXPathNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $idpEntityId = (string) ($xml['entityID'] ?? '');
        if ($idpEntityId === '') {
            $io->error('Could not read entityID from the metadata root element.');
            return Command::FAILURE;
        }

        $ssoNodes = $xml->xpath(
            '//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"]/@Location'
        );
        $idpSsoUrl = $ssoNodes ? (string) $ssoNodes[0] : '';
        if ($idpSsoUrl === '') {
            $io->error('Could not find an HTTP-Redirect SingleSignOnService in the metadata.');
            return Command::FAILURE;
        }

        $certNodes = $xml->xpath(
            '//md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]//ds:X509Certificate'
        );
        if (!$certNodes) {
            $certNodes = $xml->xpath('//md:IDPSSODescriptor/md:KeyDescriptor//ds:X509Certificate');
        }
        $idpCert = $certNodes ? trim((string) $certNodes[0]) : '';
        if ($idpCert === '') {
            $io->error('Could not extract an X509Certificate from the IdP metadata.');
            return Command::FAILURE;
        }

        $io->section('Parsed from metadata');
        $io->definitionList(
            ['IdP Entity ID'    => $idpEntityId],
            ['IdP SSO URL'      => $idpSsoUrl],
            ['IdP Certificate'  => substr($idpCert, 0, 40) . '…'],
        );

        // --- Resolve the provider record ---
        $updateId = $input->getOption('update-id');
        $provider = null;

        if ($updateId !== null) {
            $provider = $this->repo->find((int) $updateId);
            if ($provider === null) {
                $io->error("No SAML provider found with ID {$updateId}.");
                return Command::FAILURE;
            }
            $io->note("Updating existing provider: {$provider->getName()} (ID {$provider->getId()})");
        }

        // --- Provider name ---
        $name = $input->getOption('name');
        if ($name === null) {
            $name = $io->ask('Provider name', $provider?->getName() ?? 'IdP');
        }

        // --- Build SP URL defaults from the router ---
        $this->configureRouterContext();
        $defaultEntityId = $this->router->generate('saml_metadata', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $defaultAcsUrl   = $this->router->generate('saml_acs',      [], UrlGeneratorInterface::ABSOLUTE_URL);
        $defaultSloUrl   = $this->router->generate('saml_logout',   [], UrlGeneratorInterface::ABSOLUTE_URL);

        // --- SP URLs ---
        $io->section('Service Provider (SP) configuration');

        $spEntityId = $io->ask('SP Entity ID',                      $provider?->getSpEntityId() ?? $defaultEntityId);
        $spAcsUrl   = $io->ask('SP Assertion Consumer Service URL', $provider?->getSpAcsUrl()   ?? $defaultAcsUrl);
        $spSloUrl   = $io->ask('SP Single Logout URL',              $provider?->getSpSloUrl()   ?? $defaultSloUrl);

        // --- SP certificate and private key ---
        $spCert      = $provider?->getSpCert() ?? '';
        $encryptedKey = null;
        $isNew        = $provider === null;

        if ($isNew) {
            $io->section('SP Certificate and Private Key');

            if ($io->confirm('Auto-generate a self-signed RSA-2048 certificate (valid 10 years)?', true)) {
                $io->text('Generating certificate…');
                ['cert' => $spCert, 'key' => $rawKey] = $this->generateSpCertificate();
                $encryptedKey = $this->encryption->encrypt($rawKey);
                $io->text(sprintf('<info>Certificate generated</info> (first 40 chars): %s…', substr($spCert, 0, 40)));
                $io->text('<info>Private key encrypted and ready for storage.</info>');
            } else {
                $io->text('Paste the SP public certificate (base64, no headers). Press Enter on an empty line when done:');
                $spCert = $this->readMultiline($io);

                $io->text('Paste the SP private key (base64, no headers). Press Enter on an empty line when done:');
                $rawKey = $this->readMultiline($io);
                if ($rawKey === '') {
                    $io->error('An SP private key is required.');
                    return Command::FAILURE;
                }
                $encryptedKey = $this->encryption->encrypt(trim($rawKey));
            }
        } else {
            // Updating — cert and key are optional
            $io->section('SP Certificate and Private Key (leave blank to keep existing)');

            $io->text('Paste a new SP public certificate, or press Enter to keep the existing one:');
            $newCert = $this->readMultiline($io);
            if ($newCert !== '') {
                $spCert = $newCert;
            } else {
                $io->text('<info>Keeping existing SP certificate.</info>');
            }

            $io->text('Paste a new SP private key, or press Enter to keep the existing one:');
            $rawKey = $this->readMultiline($io);
            if ($rawKey !== '') {
                $encryptedKey = $this->encryption->encrypt(trim($rawKey));
            } else {
                $io->text('<info>Keeping existing SP private key.</info>');
            }
        }

        // --- Validate required SP fields ---
        foreach (['SP Entity ID' => $spEntityId, 'SP ACS URL' => $spAcsUrl, 'SP SLO URL' => $spSloUrl, 'SP Certificate' => $spCert] as $label => $value) {
            if (trim($value) === '') {
                $io->error("{$label} is required.");
                return Command::FAILURE;
            }
        }

        // --- Persist ---
        if ($provider === null) {
            $provider = new SamlProvider();
            $this->em->persist($provider);
        }

        $provider->setName($name);
        $provider->setIdpEntityId($idpEntityId);
        $provider->setIdpSsoUrl($idpSsoUrl);
        $provider->setIdpCert($idpCert);
        $provider->setSpEntityId($spEntityId);
        $provider->setSpAcsUrl($spAcsUrl);
        $provider->setSpSloUrl($spSloUrl);
        $provider->setSpCert($spCert);
        $provider->setUpdatedAt(new \DateTimeImmutable());

        if ($encryptedKey !== null) {
            $provider->setSpPrivateKey($encryptedKey);
        }

        if ($input->getOption('activate')) {
            foreach ($this->repo->findAll() as $p) {
                $p->setIsActive(false);
            }
            $provider->setIsActive(true);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Provider "%s" (ID %d) %s.',
            $provider->getName(),
            $provider->getId(),
            $provider->isActive()
                ? 'saved and set as active'
                : 'saved (not yet active — use --activate or the web UI to enable)',
        ));

        return Command::SUCCESS;
    }

    /** Sets the router's request context from DEFAULT_URI so ABSOLUTE_URL generation works in CLI. */
    private function configureRouterContext(): void
    {
        $parsed  = parse_url($this->defaultUri);
        $context = $this->router->getContext();
        $context->setHost($parsed['host'] ?? 'localhost');
        $context->setScheme($parsed['scheme'] ?? 'https');
        if (isset($parsed['port'])) {
            if (($parsed['scheme'] ?? '') === 'https') {
                $context->setHttpsPort($parsed['port']);
            } else {
                $context->setHttpPort($parsed['port']);
            }
        }
    }

    /** Generates a self-signed RSA-2048 cert valid for 10 years. Returns base64 strings without PEM headers. */
    private function generateSpCertificate(): array
    {
        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privKey = openssl_pkey_new($config);
        $csr     = openssl_csr_new(['commonName' => 'DashLog SAML SP'], $privKey, $config);
        $x509    = openssl_csr_sign($csr, null, $privKey, 3650, $config);

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($privKey, $keyPem);

        $stripHeaders = static fn(string $pem): string =>
            preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $pem);

        return [
            'cert' => $stripHeaders($certPem),
            'key'  => $stripHeaders($keyPem),
        ];
    }

    /** Reads lines until the user submits an empty line. */
    private function readMultiline(SymfonyStyle $io): string
    {
        $lines = [];

        while (true) {
            $line = $io->ask('', null, static fn($v) => (string) ($v ?? ''));
            if ($line === '') {
                break;
            }
            $lines[] = $line;
        }

        return implode('', $lines);
    }
}
