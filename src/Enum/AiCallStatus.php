<?php

namespace App\Enum;

enum AiCallStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Error = 'error';
    case Interrupted = 'interrupted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cours',
            self::Success => 'Réussi',
            self::Error => 'Erreur',
            self::Interrupted => 'Interrompu',
        };
    }
}
