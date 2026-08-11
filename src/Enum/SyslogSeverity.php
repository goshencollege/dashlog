<?php

namespace App\Enum;

enum SyslogSeverity: int
{
    case Emergency = 0;
    case Alert = 1;
    case Critical = 2;
    case Error = 3;
    case Warning = 4;
    case Notice = 5;
    case Informational = 6;
    case Debug = 7;

    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Emergency',
            self::Alert => 'Alert',
            self::Critical => 'Critical',
            self::Error => 'Error',
            self::Warning => 'Warning',
            self::Notice => 'Notice',
            self::Informational => 'Informational',
            self::Debug => 'Debug',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Emergency, self::Alert, self::Critical, self::Error => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
            self::Warning => 'badge-orange-subtle',
            self::Notice => 'bg-info-subtle text-info-emphasis border border-info-subtle',
            self::Informational => 'bg-secondary-subtle text-secondary-emphasis border',
            self::Debug => 'badge-purple-subtle',
        };
    }
}
