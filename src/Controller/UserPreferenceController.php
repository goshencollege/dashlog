<?php

namespace App\Controller;

use App\Entity\UserPreference;
use App\Repository\UserPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class UserPreferenceController extends AbstractController
{
    #[Route('/api/preference/theme', name: 'api_preference_theme', methods: ['POST'])]
    public function setTheme(
        Request $request,
        UserPreferenceRepository $prefRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data  = json_decode($request->getContent(), true);
        $theme = $data['theme'] ?? '';
        if (!in_array($theme, ['light', 'dark', 'purple', 'green', 'rainbow'], true)) {
            return $this->json(['error' => 'Invalid theme'], 400);
        }

        $pref = $prefRepo->findByIdentifier($user->getUserIdentifier());
        if (!$pref) {
            $pref = new UserPreference($user->getUserIdentifier());
            $em->persist($pref);
        }
        $pref->setTheme($theme);
        $em->flush();

        return $this->json(['theme' => $theme]);
    }
}
