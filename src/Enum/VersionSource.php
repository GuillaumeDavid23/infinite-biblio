<?php

namespace App\Enum;

enum VersionSource: string
{
    case Generation = 'generation';
    case Correction = 'correction';
    case ManualEdit = 'manual_edit';
    case Restore = 'restore';

    public function label(): string
    {
        return match ($this) {
            self::Generation => 'Génération',
            self::Correction => 'Correction IA',
            self::ManualEdit => 'Modification manuelle',
            self::Restore => 'Restauration',
        };
    }
}
