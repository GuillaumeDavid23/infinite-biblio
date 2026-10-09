<?php

namespace App\Generation;

use App\Ai\Prompt\PromptLibrary;
use App\Ai\Prompt\StructuredOutputException;
use App\Ai\Prompt\StructuredOutputParser;
use App\Ai\Provider\GenerationRequest;
use App\Entity\Chapter;
use App\Entity\ConsistencyIssue;
use App\Entity\GenerationJob;
use App\Entity\MemorySnapshot;
use App\Entity\Novel;
use App\Enum\ChapterStatus;
use App\Enum\IssueSeverity;
use App\Enum\IssueType;
use App\Enum\NovelStatus;
use App\Enum\Step;
use App\Enum\VersionSource;
use App\Exception\UserFacingException;
use App\Repository\MemorySnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exécution des étapes de génération. Chaque étape conserve son résultat en base.
 */
final class GenerationSteps
{
    /**
     * @param array<string, int> $maxOutputTokens limites de sortie par étape
     */
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptLibrary $prompts,
        private readonly StructuredOutputParser $parser,
        private readonly ChapterService $chapters,
        private readonly MemorySnapshotRepository $memories,
        private readonly EntityManagerInterface $em,
        private readonly array $maxOutputTokens,
    ) {
    }

    public function run(GenerationJob $job): void
    {
        match ($job->getStep()) {
            Step::Plan => $this->plan($job),
            Step::ChapterWrite => $this->writeChapter($job),
            Step::ChapterReview => $this->review($job),
            Step::MemoryUpdate => $this->memory($job),
            Step::Correction => $this->correct($job),
        };
    }

    /** Remet l'état cohérent après un échec ou une interruption. */
    public function rollback(GenerationJob $job): void
    {
        if (Step::ChapterWrite === $job->getStep()) {
            $chapter = $job->getNovel()->getChapter((int) $job->getChapterNumber());
            if (null !== $chapter && ChapterStatus::Writing === $chapter->getStatus()) {
                $chapter->transitionTo(ChapterStatus::Planned);
            }
        }
    }

    public function maxOutputTokens(Step $step): int
    {
        return $this->maxOutputTokens[$step->value] ?? 8000;
    }

    private function plan(GenerationJob $job): void
    {
        $novel = $job->getNovel();
        $data = $this->structured($job, ['brief' => $novel->getBrief()], ['brief' => $novel->getBrief()]);

        $data['chapters'] = $this->normalizeChapters($data['chapters'] ?? [], $novel->getChapterCount());
        $novel->setPlan($data);
        if ('' !== trim((string) ($data['title'] ?? '')) && '' === trim((string) ($novel->getBrief()['title'] ?? ''))) {
            $novel->setTitle(mb_substr((string) $data['title'], 0, 200));
        }
        $novel->transitionTo(NovelStatus::PlanReady);
        $this->em->flush();
    }

    private function writeChapter(GenerationJob $job): void
    {
        $novel = $job->getNovel();
        $chapter = $this->chapter($job);
        if (null !== $chapter->getCurrentVersion()) {
            // Reprise : le chapitre a déjà été livré, on ne le régénère pas.
            return;
        }
        $chapter->transitionTo(ChapterStatus::Writing);
        $this->em->flush();

        $plan = $novel->getPlan() ?? [];
        $previous = $novel->getChapter($chapter->getNumber() - 1);
        $vars = [
            'brief' => $novel->getBrief(),
            'plan' => $plan,
            'chapter' => $chapter->getPlanEntry(),
            'nextChapter' => $novel->getChapter($chapter->getNumber() + 1)?->getPlanEntry(),
            'chapterCount' => $novel->getChapterCount(),
            'targetWords' => $novel->getTargetWordsPerChapter(),
            'memory' => $this->memories->findLatestBefore($novel, $chapter->getNumber())?->getData(),
            'previousExcerpt' => null !== $previous?->getCurrentVersion() ? self::tail($previous->getCurrentVersion()->getContent(), 250) : null,
        ];
        $request = new GenerationRequest(Step::ChapterWrite, $this->prompts->system(), $this->prompts->user(Step::ChapterWrite, $vars), $this->maxOutputTokens(Step::ChapterWrite), null, [
            'chapterNumber' => $chapter->getNumber(),
            'targetWords' => $novel->getTargetWordsPerChapter(),
        ]);
        [$call, $result] = $this->gateway->call($novel, Step::ChapterWrite, $chapter->getNumber(), $job->getId(), $job->getAttempts(), $this->prompts->version(), $request);

        $text = trim($result->text);
        if ('' === $text) {
            $this->gateway->markInvalid($call, 'Réponse vide.');
            throw new StepFailedException('Le modèle a renvoyé un chapitre vide.', true);
        }
        if (preg_match('/^#\s*(.+)$/m', $text, $m) && '' !== trim($m[1])) {
            $chapter->setTitle(mb_substr(trim($m[1]), 0, 255));
        }
        $this->chapters->addVersion($chapter, $text, VersionSource::Generation, $call->isSimulated(), null, $call);
        $this->em->flush();
    }

    private function review(GenerationJob $job): void
    {
        $novel = $job->getNovel();
        $chapter = $this->chapter($job);
        $version = $chapter->getCurrentVersion();
        if (null === $version || $chapter->isCurrentVersionReviewed()) {
            return;
        }

        $vars = [
            'brief' => $novel->getBrief(),
            'plan' => $novel->getPlan() ?? [],
            'chapter' => $chapter->getPlanEntry(),
            'memory' => $this->memories->findLatestBefore($novel, $chapter->getNumber())?->getData(),
            'text' => $version->getContent(),
        ];
        $data = $this->structured($job, $vars, ['chapterNumber' => $chapter->getNumber()]);

        $simulated = $this->gateway->provider()->isSimulated();
        foreach ($data['issues'] ?? [] as $issue) {
            $this->em->persist(new ConsistencyIssue(
                $chapter,
                $version->getNumber(),
                IssueType::tryFrom((string) ($issue['type'] ?? '')) ?? IssueType::Other,
                IssueSeverity::tryFrom((string) ($issue['severity'] ?? '')) ?? IssueSeverity::Warning,
                (string) ($issue['description'] ?? ''),
                '' !== ($issue['excerpt'] ?? '') ? (string) $issue['excerpt'] : null,
                '' !== ($issue['suggestion'] ?? '') ? (string) $issue['suggestion'] : null,
                ConsistencyIssue::ORIGIN_AI,
                $simulated,
            ));
        }

        // Contrôle local, gratuit : écart important à la longueur cible (simple information).
        $target = $novel->getTargetWordsPerChapter();
        if ($target > 0 && abs($version->getWordCount() - $target) > 0.4 * $target) {
            $this->em->persist(new ConsistencyIssue(
                $chapter,
                $version->getNumber(),
                IssueType::Length,
                IssueSeverity::Info,
                \sprintf('Le chapitre compte %d mots pour une cible d\'environ %d. La longueur est une cible : à juger selon la qualité du texte.', $version->getWordCount(), $target),
                null,
                null,
                ConsistencyIssue::ORIGIN_LOCAL,
                false,
            ));
        }

        $chapter->setReviewedVersionNumber($version->getNumber());
        $this->em->flush();
    }

    private function memory(GenerationJob $job): void
    {
        $novel = $job->getNovel();
        $chapter = $this->chapter($job);
        $version = $chapter->getCurrentVersion();
        if (null === $version || ChapterStatus::Accepted !== $chapter->getStatus()) {
            throw new StepFailedException('La mémoire ne se met à jour qu\'après acceptation du chapitre.', false);
        }
        if (null !== $this->memories->findValid($novel, $chapter->getNumber())) {
            return;
        }

        $previous = $this->memories->findLatestBefore($novel, $chapter->getNumber())?->getData();
        $vars = [
            'previousMemory' => $previous,
            'characters' => $novel->getPlan()['characters'] ?? [],
            'chapterNumber' => $chapter->getNumber(),
            'text' => $version->getContent(),
        ];
        $data = $this->structured($job, $vars, ['chapterNumber' => $chapter->getNumber(), 'previousMemory' => $previous]);

        $this->em->persist(new MemorySnapshot($novel, $chapter->getNumber(), $version->getNumber(), $data, $this->gateway->provider()->isSimulated()));
        $this->em->flush();
    }

    private function correct(GenerationJob $job): void
    {
        $novel = $job->getNovel();
        $chapter = $this->chapter($job);
        $payload = $job->getPayload();
        $version = $chapter->getCurrentVersion();
        if (null === $version) {
            throw new StepFailedException('Aucun texte à corriger.', false);
        }
        if ((int) ($payload['baseVersion'] ?? 0) !== $version->getNumber()) {
            // Reprise après interruption : la correction a déjà produit une nouvelle version.
            return;
        }
        $passage = (string) ($payload['passage'] ?? '');
        $reason = (string) ($payload['reason'] ?? '');

        $vars = [
            'chapterNumber' => $chapter->getNumber(),
            'reason' => $reason,
            'passage' => $passage,
            'text' => $version->getContent(),
            'memory' => $this->memories->findLatestBefore($novel, $chapter->getNumber())?->getData(),
        ];
        $data = $this->structured($job, $vars, ['passage' => $passage], $call);

        try {
            $newText = ChapterService::replacePassage($version->getContent(), $passage, (string) $data['revised_passage']);
        } catch (UserFacingException $e) {
            throw new StepFailedException($e->getMessage(), false);
        }
        $explanation = trim((string) ($data['explanation'] ?? ''));
        $this->chapters->addVersion($chapter, $newText, VersionSource::Correction, $call->isSimulated(), $reason.('' !== $explanation ? "\n\nNote de l'IA : ".$explanation : ''), $call);
        $this->em->flush();
    }

    /**
     * Appel avec sortie structurée + validation du schéma.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $simulationContext
     *
     * @return array<string, mixed>
     */
    private function structured(GenerationJob $job, array $vars, array $simulationContext, mixed &$call = null): array
    {
        $step = $job->getStep();
        $schema = $this->prompts->schema($step) ?? throw new \LogicException('Schéma manquant pour '.$step->value);
        $request = new GenerationRequest($step, $this->prompts->system(), $this->prompts->user($step, $vars), $this->maxOutputTokens($step), $schema, $simulationContext);
        [$call, $result] = $this->gateway->call($job->getNovel(), $step, $job->getChapterNumber(), $job->getId(), $job->getAttempts(), $this->prompts->version(), $request);

        try {
            return $this->parser->parse($result->text, $schema);
        } catch (StructuredOutputException $e) {
            $this->gateway->markInvalid($call, $e->getMessage());
            throw new StepFailedException($e->getMessage(), true, $e);
        }
    }

    private function chapter(GenerationJob $job): Chapter
    {
        return $job->getNovel()->getChapter((int) $job->getChapterNumber())
            ?? throw new StepFailedException('Chapitre '.$job->getChapterNumber().' introuvable.', false);
    }

    /**
     * Garantit exactement le nombre de chapitres demandé, numérotés de 1 à N.
     *
     * @param list<array<string, mixed>> $chapters
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeChapters(array $chapters, int $count): array
    {
        if (\count($chapters) < $count) {
            throw new StepFailedException(\sprintf('Le plan proposé contient %d chapitres au lieu de %d.', \count($chapters), $count), true);
        }
        $chapters = \array_slice(array_values($chapters), 0, $count);
        foreach ($chapters as $i => &$entry) {
            $entry['number'] = $i + 1;
        }

        return $chapters;
    }

    public static function tail(string $text, int $words): string
    {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return (\count($parts) > $words ? '… ' : '').implode(' ', \array_slice($parts, -$words));
    }

    public function syncChaptersFromPlan(Novel $novel): void
    {
        $entries = $novel->getPlan()['chapters'] ?? [];
        $numbers = [];
        foreach ($entries as $entry) {
            $n = (int) $entry['number'];
            $numbers[] = $n;
            $chapter = $novel->getChapter($n) ?? new Chapter($novel, $n, (string) ($entry['title'] ?? 'Chapitre '.$n));
            if (null === $chapter->getCurrentVersion()) {
                $chapter->setTitle((string) ($entry['title'] ?? 'Chapitre '.$n));
            }
            $this->em->persist($chapter);
        }
        foreach ($novel->getChapters()->toArray() as $chapter) {
            if (!\in_array($chapter->getNumber(), $numbers, true) && null === $chapter->getCurrentVersion()) {
                $novel->removeChapter($chapter);
            }
        }
    }
}
