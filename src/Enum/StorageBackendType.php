<?php

namespace App\Enum;

enum StorageBackendType: string
{
    case Local = 'local';
    case Cifs = 'cifs';
    case S3 = 's3';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local Directory',
            self::Cifs => 'CIFS / SMB Share',
            self::S3 => 'S3',
        };
    }
}
