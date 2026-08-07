<?php

namespace App\Twig;

use App\Repository\UserPreferenceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPreferenceRepository $prefRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_theme', $this->currentTheme(...)),
        ];
    }

    public function currentTheme(): string
    {
        $user = $this->security->getUser();
        if (!$user) {
            return 'purple';
        }

        $pref = $this->prefRepo->findByIdentifier($user->getUserIdentifier());
        return $pref?->getTheme() ?? 'purple';
    }
}
