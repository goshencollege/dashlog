<?php

namespace App\Controller;

use App\Entity\SamlProvider;
use App\Form\SamlProviderType;
use App\Repository\SamlProviderRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/saml-providers')]
class SamlProviderController extends AbstractController
{
    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    #[Route('', name: 'saml_provider_index', methods: ['GET'])]
    public function index(SamlProviderRepository $repo): Response
    {
        return $this->render('saml_provider/index.html.twig', [
            'providers' => $repo->findAll(),
        ]);
    }

    #[Route('/new', name: 'saml_provider_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $provider = new SamlProvider();
        $form     = $this->createForm(SamlProviderType::class, $provider, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawKey = (string) $form->get('spPrivateKey')->getData();
            $provider->setSpPrivateKey($this->encryption->encrypt(trim($rawKey)));

            $em->persist($provider);
            $em->flush();

            $this->addFlash('success', "SAML provider \"{$provider->getName()}\" created.");
            return $this->redirectToRoute('saml_provider_index');
        }

        return $this->render('saml_provider/edit.html.twig', [
            'form'     => $form,
            'provider' => $provider,
            'is_new'   => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'saml_provider_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SamlProvider $provider, EntityManagerInterface $em): Response
    {
        $existingKey = $provider->getSpPrivateKey();

        $form = $this->createForm(SamlProviderType::class, $provider, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawKey = trim((string) $form->get('spPrivateKey')->getData());

            if ($rawKey !== '') {
                $provider->setSpPrivateKey($this->encryption->encrypt($rawKey));
            } else {
                $provider->setSpPrivateKey($existingKey);
            }

            $provider->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();

            $this->addFlash('success', "SAML provider \"{$provider->getName()}\" updated.");
            return $this->redirectToRoute('saml_provider_index');
        }

        return $this->render('saml_provider/edit.html.twig', [
            'form'     => $form,
            'provider' => $provider,
            'is_new'   => false,
        ]);
    }

    #[Route('/{id}/activate', name: 'saml_provider_activate', methods: ['POST'])]
    public function activate(Request $request, SamlProvider $provider, SamlProviderRepository $repo, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('activate' . $provider->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('saml_provider_index');
        }

        foreach ($repo->findAll() as $p) {
            $p->setIsActive(false);
        }
        $provider->setIsActive(true);
        $em->flush();

        $this->addFlash('success', "\"{$provider->getName()}\" is now the active SAML provider.");
        return $this->redirectToRoute('saml_provider_index');
    }

    #[Route('/{id}/delete', name: 'saml_provider_delete', methods: ['POST'])]
    public function delete(Request $request, SamlProvider $provider, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $provider->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('saml_provider_index');
        }

        if ($provider->isActive()) {
            $this->addFlash('danger', 'Cannot delete the active SAML provider. Activate a different provider first.');
            return $this->redirectToRoute('saml_provider_index');
        }

        $name = $provider->getName();
        $em->remove($provider);
        $em->flush();

        $this->addFlash('success', "SAML provider \"{$name}\" deleted.");
        return $this->redirectToRoute('saml_provider_index');
    }
}
