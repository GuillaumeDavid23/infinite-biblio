<?php

namespace App\Enum;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Resolved => 'Corrigée',
            self::Dismissed => 'Ignorée',
        };
    }
}
