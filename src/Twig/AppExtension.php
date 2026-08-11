<?php

namespace App\Twig;

use App\Enum\SyslogFacility;
use App\Enum\SyslogSeverity;
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
            new TwigFunction('syslog_severity_label', $this->syslogSeverityLabel(...)),
            new TwigFunction('syslog_severity_badge_class', $this->syslogSeverityBadgeClass(...)),
            new TwigFunction('syslog_facility_label', $this->syslogFacilityLabel(...)),
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

    public function syslogSeverityLabel(?int $severity): string
    {
        return SyslogSeverity::tryFrom($severity ?? -1)?->label() ?? 'Unknown';
    }

    public function syslogSeverityBadgeClass(?int $severity): string
    {
        return SyslogSeverity::tryFrom($severity ?? -1)?->badgeClass() ?? 'bg-secondary-subtle text-secondary-emphasis border';
    }

    public function syslogFacilityLabel(?int $facility): string
    {
        return SyslogFacility::tryFrom($facility ?? -1)?->label() ?? 'unknown';
    }
}
