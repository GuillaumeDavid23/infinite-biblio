<?php

namespace App\Enum;

enum IssueType: string
{
    case Contradiction = 'contradiction';
    case Repetition = 'repetition';
    case PlanDeviation = 'plan_deviation';
    case PointOfView = 'point_of_view';
    case Continuity = 'continuity';
    case Length = 'length';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contradiction => 'Contradiction',
            self::Repetition => 'Répétition',
            self::PlanDeviation => 'Écart au plan',
            self::PointOfView => 'Point de vue / temps',
            self::Continuity => 'Continuité',
            self::Length => 'Longueur',
            self::Other => 'Autre',
        };
    }
}
