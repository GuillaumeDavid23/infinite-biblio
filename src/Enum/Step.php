<?php

namespace App\Enum;

/**
 * Étapes de génération. Chaque étape correspond à un appel IA distinct, journalisé.
 */
enum Step: string
{
    case Plan = 'plan';
    case ChapterWrite = 'chapter_write';
    case ChapterReview = 'chapter_review';
    case MemoryUpdate = 'memory_update';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Proposition d\'histoire et plan',
            self::ChapterWrite => 'Rédaction de chapitre',
            self::ChapterReview => 'Contrôle de cohérence',
            self::MemoryUpdate => 'Mise à jour de la mémoire narrative',
            self::Correction => 'Correction ciblée',
        };
    }

    /** Nom du gabarit de consignes associé (dossier prompts/). */
    public function promptName(): string
    {
        return match ($this) {
            self::Plan => 'plan',
            self::ChapterWrite => 'chapter',
            self::ChapterReview => 'review',
            self::MemoryUpdate => 'memory',
            self::Correction => 'correction',
        };
    }
}
