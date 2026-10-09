<?php

namespace App\Enum;

/**
 * Cycle de vie d'un projet de roman.
 */
enum NovelStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case PlanReady = 'plan_ready';
    case Writing = 'writing';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Demande enregistrée',
            self::Planning => 'Plan en préparation',
            self::PlanReady => 'Plan à valider',
            self::Writing => 'Rédaction en cours',
            self::Paused => 'En pause',
            self::Completed => 'Terminé',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Planning],
            self::Planning => [self::PlanReady, self::Paused],
            self::PlanReady => [self::Planning, self::Writing, self::Paused],
            self::Writing => [self::Paused, self::Completed],
            self::Paused => [self::Draft, self::Planning, self::PlanReady, self::Writing, self::Completed],
            self::Completed => [self::Writing, self::Paused],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || \in_array($target, $this->allowedTransitions(), true);
    }
}
