<?php

namespace App\Controller;

use App\Security\SamlSettings;
use OneLogin\Saml2\Auth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SamlController extends AbstractController
{
    public function __construct(private readonly SamlSettings $samlSettings) {}

    /** Login page — shows the "Sign in" button and any error flash messages. */
    #[Route('/saml/login', name: 'saml_login')]
    public function login(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('saml/login.html.twig');
    }

    /** Initiates the SAML SSO flow by redirecting to the IdP. */
    #[Route('/saml/initiate', name: 'saml_initiate')]
    public function initiate(): Response
    {
        $auth = new Auth($this->samlSettings->toArray());
        $ssoUrl = $auth->login(returnTo: null, parameters: [], forceAuthn: false, isPassive: false, stay: true);

        return $this->redirect($ssoUrl);
    }

    /**
     * ACS endpoint — receives the IdP POST-back.
     * The SamlAuthenticator intercepts this before the action runs.
     */
    #[Route('/saml/acs', name: 'saml_acs', methods: ['POST'])]
    public function acs(): Response
    {
        throw new \LogicException('This route is handled by the SamlAuthenticator.');
    }

    /** Returns SP metadata XML for the IdP to import. */
    #[Route('/saml/metadata', name: 'saml_metadata')]
    public function metadata(): Response
    {
        $auth     = new Auth($this->samlSettings->toArray());
        $settings = $auth->getSettings();
        $metadata = $settings->getSPMetadata();

        $errors = $settings->validateMetadata($metadata);
        if (!empty($errors)) {
            throw new \RuntimeException('SP metadata invalid: ' . implode(', ', $errors));
        }

        return new Response($metadata, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
    }

    /** Logout is handled by the Symfony security firewall. */
    #[Route('/saml/logout', name: 'saml_logout')]
    public function logout(): never
    {
        throw new \LogicException('This route is handled by the security firewall.');
    }
}
