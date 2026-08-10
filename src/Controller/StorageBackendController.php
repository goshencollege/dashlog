<?php

namespace App\Controller;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Form\StorageBackendType as StorageBackendFormType;
use App\Repository\StorageBackendRepository;
use App\Service\EncryptionService;
use App\Service\StorageBackendFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/storage-backends')]
#[IsGranted('ROLE_ADMIN')]
class StorageBackendController extends AbstractController
{
    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly StorageBackendFactory $storageBackendFactory,
    ) {}

    #[Route('', name: 'storage_backend_index', methods: ['GET'])]
    public function index(StorageBackendRepository $repo): Response
    {
        return $this->render('storage_backend/index.html.twig', [
            'backends' => $repo->findAll(),
        ]);
    }

    #[Route('/new', name: 'storage_backend_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $backend = new StorageBackend();
        $form    = $this->createForm(StorageBackendFormType::class, $backend, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyCredentials($form, $backend, isNew: true);

            if ($form->isValid()) {
                $em->persist($backend);
                $em->flush();

                $this->addFlash('success', "Storage backend \"{$backend->getName()}\" created.");
                return $this->redirectToRoute('storage_backend_index');
            }
        }

        return $this->render('storage_backend/edit.html.twig', [
            'form'    => $form,
            'backend' => $backend,
            'is_new'  => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'storage_backend_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, StorageBackend $backend, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(StorageBackendFormType::class, $backend, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyCredentials($form, $backend, isNew: false);

            if ($form->isValid()) {
                $backend->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();

                $this->addFlash('success', "Storage backend \"{$backend->getName()}\" updated.");
                return $this->redirectToRoute('storage_backend_index');
            }
        }

        return $this->render('storage_backend/edit.html.twig', [
            'form'    => $form,
            'backend' => $backend,
            'is_new'  => false,
        ]);
    }

    #[Route('/{id}/toggle', name: 'storage_backend_toggle', methods: ['POST'])]
    public function toggle(Request $request, StorageBackend $backend, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle' . $backend->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('storage_backend_index');
        }

        $backend->setIsActive(!$backend->isActive());
        $em->flush();

        $status = $backend->isActive() ? 'active' : 'inactive';
        $this->addFlash('success', "\"{$backend->getName()}\" is now {$status}.");
        return $this->redirectToRoute('storage_backend_index');
    }

    #[Route('/{id}/delete', name: 'storage_backend_delete', methods: ['POST'])]
    public function delete(Request $request, StorageBackend $backend, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $backend->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('storage_backend_index');
        }

        if ($backend->isActive()) {
            $this->addFlash('danger', 'Cannot delete an active storage backend. Deactivate it first.');
            return $this->redirectToRoute('storage_backend_index');
        }

        $name = $backend->getName();
        $em->remove($backend);
        $em->flush();

        $this->addFlash('success', "Storage backend \"{$name}\" deleted.");
        return $this->redirectToRoute('storage_backend_index');
    }

    #[Route('/{id}/test', name: 'storage_backend_test', methods: ['POST'])]
    public function test(Request $request, StorageBackend $backend, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('test' . $backend->getId(), $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], 400);
        }

        $probe   = '.dashlog-test-' . bin2hex(random_bytes(8));
        $content = 'DashLog connectivity test';

        try {
            $filesystem = $this->storageBackendFactory->createFilesystem($backend);
            $filesystem->write($probe, $content);
            $readBack = $filesystem->read($probe);
            $filesystem->delete($probe);

            if ($readBack !== $content) {
                throw new \RuntimeException('Round-tripped content did not match what was written.');
            }

            $backend->setLastCheckStatus('ok');
            $backend->setLastCheckMessage('Write/read/delete succeeded.');
        } catch (\Throwable $e) {
            $backend->setLastCheckStatus('error');
            $backend->setLastCheckMessage($e->getMessage());
        }

        $backend->setLastCheckedAt(new \DateTimeImmutable());
        $em->flush();

        if ($backend->getLastCheckStatus() !== 'ok') {
            return $this->json(['error' => $backend->getLastCheckMessage()]);
        }

        return $this->json(['message' => $backend->getLastCheckMessage()]);
    }

    private function applyCredentials(FormInterface $form, StorageBackend $backend, bool $isNew): void
    {
        $cifsPassword = trim((string) $form->get('cifsPassword')->getData());
        $s3SecretKey  = trim((string) $form->get('s3SecretAccessKey')->getData());

        if ($cifsPassword !== '') {
            $backend->setCifsPassword($this->encryption->encrypt($cifsPassword));
        } elseif ($backend->getType() === StorageBackendType::Cifs && ($isNew || $backend->getCifsPassword() === null)) {
            $form->get('cifsPassword')->addError(new FormError('A password is required for CIFS backends.'));
        }

        if ($s3SecretKey !== '') {
            $backend->setS3SecretAccessKey($this->encryption->encrypt($s3SecretKey));
        } elseif ($backend->getType() === StorageBackendType::S3 && ($isNew || $backend->getS3SecretAccessKey() === null)) {
            $form->get('s3SecretAccessKey')->addError(new FormError('A secret access key is required for S3 backends.'));
        }
    }
}
