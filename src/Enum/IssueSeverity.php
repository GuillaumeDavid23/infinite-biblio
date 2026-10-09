<?php

namespace App\Enum;

enum IssueSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Remarque',
            self::Warning => 'À vérifier',
            self::Critical => 'Bloquant',
        };
    }
}
