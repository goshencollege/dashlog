<?php

namespace App\Security;

use App\Repository\SamlProviderRepository;
use App\Service\EncryptionService;

class SamlSettings
{
    public function __construct(
        private readonly SamlProviderRepository $repository,
        private readonly EncryptionService $encryption,
    ) {}

    public function getSessionLifetimeSeconds(): int
    {
        $provider = $this->repository->findActive();
        return ($provider?->getSessionLifetimeMinutes() ?? 30) * 60;
    }

    public function toArray(): array
    {
        $provider = $this->repository->findActive();

        if ($provider === null) {
            throw new \RuntimeException(
                'No active SAML provider is configured. '
                . 'Add one under Other Settings → SAML Providers, '
                . 'or run bin/console app:saml:import-metadata to restore from a metadata file.'
            );
        }

        $spAcsUrl = $provider->getSpAcsUrl();
        $parsed   = parse_url($spAcsUrl);
        $baseUrl  = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }

        return [
            'strict'  => true,
            'debug'   => false,
            'baseurl' => $baseUrl,
            'sp' => [
                'entityId' => $provider->getSpEntityId(),
                'assertionConsumerService' => [
                    'url'     => $spAcsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url'     => $provider->getSpSloUrl(),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert'    => $provider->getSpCert(),
                'privateKey'  => $this->encryption->decrypt($provider->getSpPrivateKey()),
            ],
            'idp' => [
                'entityId' => $provider->getIdpEntityId(),
                'singleSignOnService' => [
                    'url'     => $provider->getIdpSsoUrl(),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $provider->getIdpCert(),
            ],
        ];
    }
}
