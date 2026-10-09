<?php

namespace App\Tests\Integration;

use App\Entity\MemorySnapshot;
use App\Enum\AiCallStatus;
use App\Enum\ChapterStatus;
use App\Enum\NovelStatus;
use App\Enum\Step;
use App\Export\BookContent;
use App\Export\EpubExporter;
use App\Export\MarkdownExporter;
use App\Repository\AiCallRepository;

final class GenerationFlowTest extends LabTestCase
{
    public function testCompleteSimulatedJourney(): void
    {
        $novel = $this->createNovel(autoAccept: true);

        $this->orchestrator->requestPlan($novel);
        $this->drain();
        self::assertSame(NovelStatus::PlanReady, $novel->getStatus());
        self::assertCount(3, $novel->getPlan()['chapters']);

        $this->orchestrator->validatePlan($novel);
        self::assertCount(3, $novel->getChapters());
        self::assertStringContainsString('Prêt à rédiger', $this->orchestrator->advance($novel), 'Rien ne part sans demande explicite.');

        $this->orchestrator->advance($novel, true);
        $this->drain();
        $this->refresh($novel);

        self::assertSame(NovelStatus::Completed, $novel->getStatus());
        foreach ($novel->getChapters() as $chapter) {
            self::assertSame(ChapterStatus::Accepted, $chapter->getStatus());
            self::assertTrue($chapter->getCurrentVersion()->isSimulated());
        }
        self::assertCount(3, $this->em->getRepository(MemorySnapshot::class)->findBy(['novel' => $novel, 'stale' => false]));

        // 1 plan + 3 × (rédaction, contrôle, mémoire)
        $calls = $this->calls($novel);
        self::assertCount(10, $calls);
        foreach ($calls as $call) {
            self::assertTrue($call->isSimulated());
            self::assertSame(AiCallStatus::Success, $call->getStatus());
            self::assertSame('claude-sonnet-5-5@2026-10-06', $call->getPricingRef());
            self::assertSame('v1', $call->getPromptVersion());
            self::assertGreaterThan(0, $call->getCostPico());
            self::assertLessThanOrEqual($call->getCeilingPico(), $call->getCostPico(), 'Le coût constaté ne dépasse jamais le plafond estimé.');
        }
        $repo = self::getContainer()->get(AiCallRepository::class);
        self::assertGreaterThan(0, $repo->sumCost($novel, true));
        self::assertSame(0, $repo->sumCost($novel, false), 'Aucune dépense réelle en simulation.');

        $book = BookContent::fromNovel($novel);
        self::assertCount(3, $book->chapters);
        self::assertStringContainsString('[SIMULATION]', (new MarkdownExporter())->export($book));
        $epub = (new EpubExporter())->export($book);
        self::assertGreaterThan(1000, filesize($epub));
        unlink($epub);
    }

    public function testManualAcceptanceGatesTheNextChapter(): void
    {
        $novel = $this->createNovel(autoAccept: false);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true);
        $this->drain();
        $this->refresh($novel);

        $ch1 = $novel->getChapter(1);
        self::assertSame(ChapterStatus::ToReview, $ch1->getStatus());
        self::assertTrue($ch1->isCurrentVersionReviewed());
        self::assertSame(ChapterStatus::Planned, $novel->getChapter(2)->getStatus(), 'Le chapitre 2 attend l\'acceptation du 1.');
        self::assertStringContainsString('attend votre relecture', $this->orchestrator->advance($novel, true));

        self::getContainer()->get(\App\Generation\ChapterService::class)->accept($ch1);
        $this->em->flush();
        $this->orchestrator->advance($novel, $novel->isAutoContinue());
        $this->drain();
        $this->refresh($novel);

        self::assertNotNull($this->em->getRepository(MemorySnapshot::class)->findOneBy(['novel' => $novel, 'afterChapter' => 1]));
        self::assertSame(ChapterStatus::ToReview, $novel->getChapter(2)->getStatus());
    }

    public function testChapterPromptUsesStructuredMemoryNotWholeNovel(): void
    {
        $novel = $this->createNovel(autoAccept: true);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true);
        $this->drain();

        $writes = array_values(array_filter($this->calls($novel), static fn ($c) => Step::ChapterWrite === $c->getStep()));
        self::assertCount(3, $writes);
        // L'entrée du chapitre 3 reste du même ordre que celle du chapitre 2 : on n'envoie pas tout le roman.
        self::assertLessThan($writes[1]->getInputTokens() * 1.6, $writes[2]->getInputTokens());
    }
}
