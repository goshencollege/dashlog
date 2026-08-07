<?php

namespace App\Security;

use OneLogin\Saml2\Auth;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SamlAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly SamlSettings $samlSettings,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST')
            && $request->attributes->get('_route') === 'saml_acs'
            && $request->request->has('SAMLResponse');
    }

    public function authenticate(Request $request): Passport
    {
        // onelogin/php-saml uses $_SESSION internally; ensure it is started.
        $request->getSession()->start();

        $auth = new Auth($this->samlSettings->toArray());
        $auth->processResponse();

        $errors = $auth->getErrors();
        if (!empty($errors) || !$auth->isAuthenticated()) {
            $reason = $auth->getLastErrorReason() ?? implode(', ', $errors);
            $this->logger->error('SAML authentication failed', ['reason' => $reason, 'errors' => $errors]);
            throw new AuthenticationException('SAML error: ' . $reason);
        }

        $identifier = $auth->getNameId();
        $attributes = $auth->getAttributes();

        return new SelfValidatingPassport(
            new UserBadge($identifier, fn() => new SamlUser($identifier, $attributes))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $lifetime = $this->samlSettings->getSessionLifetimeSeconds();
        $session  = $request->getSession();
        $session->set('_session_lifetime', $lifetime);
        $session->set('_session_expires_at', time() + $lifetime);

        $targetPath = $this->getTargetPath($session, $firewallName);
        return new RedirectResponse($targetPath ?? $this->urlGenerator->generate('dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('danger', $exception->getMessage());
        return new RedirectResponse($this->urlGenerator->generate('saml_login'));
    }

    /** Called when an unauthenticated request hits a protected route. */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        if ($request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        }
        return new RedirectResponse($this->urlGenerator->generate('saml_login'));
    }
}
