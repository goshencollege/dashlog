<?php

namespace App\Controller;

use App\Service\HealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/health')]
#[IsGranted('ROLE_ADMIN')]
class HealthController extends AbstractController
{
    #[Route('', name: 'health_index', methods: ['GET'])]
    public function index(HealthCheckService $healthCheckService): Response
    {
        return $this->render('health/index.html.twig', [
            'health' => $healthCheckService->check(),
        ]);
    }
}
