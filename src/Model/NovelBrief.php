<?php

namespace App\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de roman saisie par le lecteur. Tous les champs ont une valeur par défaut :
 * un premier essai ne demande que l'idée de départ.
 */
final class NovelBrief
{
    public const POINTS_OF_VIEW = [
        'Troisième personne, centrée sur le personnage principal' => 'troisième personne limitée',
        'Première personne (« je »)' => 'première personne',
        'Narrateur omniscient' => 'troisième personne omnisciente',
    ];
    public const TENSES = [
        'Passé (passé simple et imparfait)' => 'passé simple',
        'Présent' => 'présent',
    ];
    public const PACES = ['Lent et contemplatif' => 'lent', 'Équilibré' => 'équilibré', 'Rapide, beaucoup d\'action' => 'rapide'];
    public const AUDIENCES = ['Adulte' => 'adulte', 'Jeunes adultes' => 'jeunes adultes', 'Jeunesse (10-14 ans)' => 'jeunesse', 'Tout public' => 'tout public'];

    #[Assert\Length(max: 200)]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'Indiquez au moins un genre.')]
    #[Assert\Length(max: 100)]
    public string $genre = 'Aventure';

    #[Assert\Length(max: 100)]
    public ?string $subgenre = null;

    #[Assert\NotBlank(message: 'Décrivez l\'idée de départ en une ou deux phrases.')]
    #[Assert\Length(min: 10, max: 3000)]
    public string $premise = '';

    #[Assert\Length(max: 1000)]
    public ?string $setting = null;

    #[Assert\Length(max: 200)]
    public ?string $era = null;

    #[Assert\Length(max: 3000)]
    public ?string $characters = null;

    public string $pointOfView = 'troisième personne limitée';

    public string $tense = 'passé simple';

    #[Assert\Length(max: 200)]
    public ?string $mood = null;

    public string $pace = 'équilibré';

    public string $audience = 'adulte';

    #[Assert\Length(max: 1000)]
    public ?string $themes = null;

    #[Assert\Length(max: 1000)]
    public ?string $avoid = null;

    #[Assert\Range(min: 300, max: 5000)]
    public int $targetWordsPerChapter = 1000;

    #[Assert\Range(min: 1, max: 60)]
    public int $chapterCount = 3;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+([.,]\d{1,2})?$/', message: 'Montant en dollars, par exemple 1.50')]
    public string $budgetDollars = '1.00';

    public bool $autoContinue = true;

    public bool $autoAccept = false;

    public static function example(): self
    {
        $b = new self();
        $b->title = 'Le Phare des marées inverses';
        $b->genre = 'Fantastique';
        $b->subgenre = 'Mystère maritime';
        $b->premise = 'Sur une île bretonne, la mer se retire chaque nuit vers le large au lieu de monter. Lise, gardienne de phare remplaçante, découvre que le phare guide autre chose que des bateaux.';
        $b->setting = 'Une île minuscule au large du Finistère, un phare automatisé, un village de douze habitants.';
        $b->era = 'Hiver 1987';
        $b->characters = "Lise Kervran, 34 ans, ancienne cartographe, méthodique et têtue.\nYann, pêcheur taiseux qui connaît les marées par cœur.\nMme Le Gall, doyenne de l'île, qui en sait plus qu'elle ne le dit.";
        $b->mood = 'Mystérieuse, mélancolique, avec une touche d\'humour sec';
        $b->pace = 'équilibré';
        $b->audience = 'adulte';
        $b->themes = 'La solitude choisie, la transmission, les secrets de famille';
        $b->avoid = 'Violence graphique, romance appuyée';

        return $b;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'genre' => $this->genre,
            'subgenre' => $this->subgenre,
            'premise' => $this->premise,
            'setting' => $this->setting,
            'era' => $this->era,
            'characters' => $this->characters,
            'pointOfView' => $this->pointOfView,
            'tense' => $this->tense,
            'mood' => $this->mood,
            'pace' => $this->pace,
            'audience' => $this->audience,
            'themes' => $this->themes,
            'avoid' => $this->avoid,
            'targetWordsPerChapter' => $this->targetWordsPerChapter,
            'chapterCount' => $this->chapterCount,
        ];
    }
}
