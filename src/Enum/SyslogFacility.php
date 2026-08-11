<?php

namespace App\Enum;

enum SyslogFacility: int
{
    case Kernel = 0;
    case User = 1;
    case Mail = 2;
    case Daemon = 3;
    case Auth = 4;
    case Syslog = 5;
    case Lpr = 6;
    case News = 7;
    case Uucp = 8;
    case Cron = 9;
    case AuthPriv = 10;
    case Ftp = 11;
    case Ntp = 12;
    case LogAudit = 13;
    case LogAlert = 14;
    case Clock = 15;
    case Local0 = 16;
    case Local1 = 17;
    case Local2 = 18;
    case Local3 = 19;
    case Local4 = 20;
    case Local5 = 21;
    case Local6 = 22;
    case Local7 = 23;

    public function label(): string
    {
        return match ($this) {
            self::Kernel => 'kernel',
            self::User => 'user',
            self::Mail => 'mail',
            self::Daemon => 'daemon',
            self::Auth => 'auth',
            self::Syslog => 'syslog',
            self::Lpr => 'lpr',
            self::News => 'news',
            self::Uucp => 'uucp',
            self::Cron => 'cron',
            self::AuthPriv => 'authpriv',
            self::Ftp => 'ftp',
            self::Ntp => 'ntp',
            self::LogAudit => 'logaudit',
            self::LogAlert => 'logalert',
            self::Clock => 'clock',
            self::Local0 => 'local0',
            self::Local1 => 'local1',
            self::Local2 => 'local2',
            self::Local3 => 'local3',
            self::Local4 => 'local4',
            self::Local5 => 'local5',
            self::Local6 => 'local6',
            self::Local7 => 'local7',
        };
    }
}
