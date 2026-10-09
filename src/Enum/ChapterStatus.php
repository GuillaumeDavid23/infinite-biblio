<?php

namespace App\Enum;

enum ChapterStatus: string
{
    case Planned = 'planned';
    case Writing = 'writing';
    case ToReview = 'to_review';
    case Accepted = 'accepted';
    case ToRecheck = 'to_recheck';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Pas encore écrit',
            self::Writing => 'Rédaction en cours',
            self::ToReview => 'À relire',
            self::Accepted => 'Accepté',
            self::ToRecheck => 'À revérifier',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::Writing],
            self::Writing => [self::ToReview, self::Planned],
            self::ToReview => [self::Accepted, self::Writing],
            self::Accepted => [self::ToReview, self::ToRecheck],
            self::ToRecheck => [self::ToReview, self::Accepted],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || \in_array($target, $this->allowedTransitions(), true);
    }
}
