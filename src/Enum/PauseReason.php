<?php

namespace App\Enum;

enum PauseReason: string
{
    case User = 'user';
    case Budget = 'budget';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Interruption demandée',
            self::Budget => 'Budget atteint',
            self::Error => 'Erreur à examiner',
        };
    }
}
