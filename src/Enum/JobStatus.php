<?php

namespace App\Enum;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    public function isActive(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente',
            self::Running => 'En cours',
            self::Done => 'Terminée',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulée',
            self::Interrupted => 'Interrompue',
        };
    }
}
