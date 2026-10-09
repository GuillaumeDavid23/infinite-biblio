<?php

namespace App\Tests\Integration;

use App\Entity\ChapterVersion;
use App\Entity\GenerationJob;
use App\Entity\MemorySnapshot;
use App\Enum\AiCallStatus;
use App\Enum\ChapterStatus;
use App\Enum\JobStatus;
use App\Enum\NovelStatus;
use App\Enum\PauseReason;
use App\Enum\Step;
use App\Enum\VersionSource;
use App\Exception\UserFacingException;
use App\Generation\ChapterService;
use App\Generation\JobRunner;
use App\Generation\JobScheduler;

final class ReliabilityTest extends LabTestCase
{
    public function testDoubleCommandIsRefused(): void
    {
        $novel = $this->createNovel();
        $scheduler = self::getContainer()->get(JobScheduler::class);
        $scheduler->schedule($novel, Step::Plan);

        $this->expectException(UserFacingException::class);
        $scheduler->schedule($novel, Step::Plan);
    }

    public function testDuplicateMessageIsExecutedOnce(): void
    {
        $novel = $this->createNovel();
        $this->orchestrator->requestPlan($novel);
        $job = $this->em->getRepository(GenerationJob::class)->findOneBy(['novel' => $novel]);

        $runner = self::getContainer()->get(JobRunner::class);
        $runner->run($job->getId());
        $runner->run($job->getId()); // message reçu une seconde fois

        self::assertCount(1, $this->calls($novel));
        $this->drain();
        self::assertCount(1, $this->calls($novel));
    }

    public function testInvalidStructuredResponseIsRetriedOnceAndBothCallsAreCounted(): void
    {
        $novel = $this->createNovel();
        $this->simulator()->queueFailure('invalid_json');
        $this->orchestrator->requestPlan($novel);
        $this->drain();

        self::assertSame(NovelStatus::PlanReady, $novel->getStatus());
        $calls = $this->calls($novel);
        self::assertCount(2, $calls);
        self::assertSame(AiCallStatus::Error, $calls[0]->getStatus());
        self::assertStringContainsString('JSON invalide', $calls[0]->getErrorMessage());
        self::assertGreaterThan(0, $calls[0]->getCostPico(), 'Un appel inexploitable reste facturé et compté.');
        self::assertSame(2, $calls[1]->getAttempt());
    }

    public function testRetriesAreLimitedThenNovelPausesWithError(): void
    {
        $novel = $this->createNovel();
        $this->simulator()->queueFailure('network');
        $this->simulator()->queueFailure('network');
        $this->orchestrator->requestPlan($novel);
        $this->drain();

        self::assertSame(NovelStatus::Paused, $novel->getStatus());
        self::assertSame(PauseReason::Error, $novel->getPauseReason());
        $job = $this->em->getRepository(GenerationJob::class)->findOneBy(['novel' => $novel]);
        self::assertSame(JobStatus::Failed, $job->getStatus());
        self::assertSame(2, $job->getAttempts());
        foreach ($this->calls($novel) as $call) {
            self::assertNull($call->getCostPico(), 'Sans consommation renvoyée, aucun coût n\'est inventé.');
        }

        $this->orchestrator->resume($novel);
        $this->drain();
        self::assertSame(NovelStatus::PlanReady, $novel->getStatus());
    }

    public function testRefusalIsNotRetried(): void
    {
        $novel = $this->createNovel();
        $this->simulator()->queueFailure('refusal');
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        self::assertSame(NovelStatus::Paused, $novel->getStatus());
        self::assertCount(1, $this->calls($novel));
    }

    public function testBudgetPausesBeforeAnyCallAndResumesAfterIncrease(): void
    {
        $novel = $this->createNovel(budget: '0.01');
        $this->orchestrator->requestPlan($novel);
        $this->drain();

        self::assertSame(NovelStatus::Paused, $novel->getStatus());
        self::assertSame(PauseReason::Budget, $novel->getPauseReason());
        self::assertCount(0, $this->calls($novel), 'Aucun appel envoyé si le plafond dépasse le budget.');

        $novel->setBudgetPico(\App\Ai\Pricing\Money::fromDollars('5'));
        $this->em->flush();
        $this->orchestrator->resume($novel);
        $this->drain();
        self::assertSame(NovelStatus::PlanReady, $novel->getStatus());
    }

    public function testBudgetReachedMidNovelStopsNewSteps(): void
    {
        $novel = $this->createNovel(budget: '5.00');
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);

        // Budget juste au-dessus de ce qui est déjà consommé : la prochaine étape ne passe pas.
        $spent = array_sum(array_map(static fn ($c) => $c->getCostPico(), $this->calls($novel)));
        $novel->setBudgetPico($spent + 1000);
        $this->em->flush();
        $this->orchestrator->advance($novel, true);
        $this->drain();
        $this->refresh($novel);

        self::assertSame(PauseReason::Budget, $novel->getPauseReason());
        self::assertSame(ChapterStatus::Planned, $novel->getChapter(1)->getStatus());
        self::assertCount(1, $this->calls($novel));
    }

    public function testResumeAfterCrashDoesNotRegenerateDeliveredChapters(): void
    {
        $novel = $this->createNovel(autoAccept: true, autoContinue: false);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true); // chapitre 1 : rédaction + contrôle + acceptation + mémoire
        $this->drain();
        $this->refresh($novel);
        self::assertSame(ChapterStatus::Accepted, $novel->getChapter(1)->getStatus());
        $callsBefore = \count($this->calls($novel));

        // Le chapitre 2 est pris par le processus, qui s'arrête brutalement pendant l'appel.
        $job = self::getContainer()->get(JobScheduler::class)->schedule($novel, Step::ChapterWrite, 2);
        $transport = self::getContainer()->get('messenger.transport.async');
        foreach ($transport->get() as $envelope) {
            $transport->ack($envelope); // message perdu avec le processus
        }
        $this->em->getRepository(GenerationJob::class)->claim($job->getId());
        $novel->getChapter(2)->transitionTo(ChapterStatus::Writing);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE generation_job SET updated_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [$job->getId()]);
        $this->em->clear();
        $novel = $this->em->find(\App\Entity\Novel::class, $novel->getId());

        $message = $this->orchestrator->resume($novel);
        self::assertStringContainsString('interrompue', $message);
        $this->drain();
        $this->refresh($novel);

        self::assertSame(JobStatus::Interrupted, $this->em->find(GenerationJob::class, $job->getId())->getStatus());
        self::assertCount(1, $novel->getChapter(1)->getVersions(), 'Le chapitre 1 livré n\'est pas régénéré.');
        $writesCh1 = array_filter($this->calls($novel), static fn ($c) => Step::ChapterWrite === $c->getStep() && 1 === $c->getChapterNumber());
        self::assertCount(1, $writesCh1);
        self::assertSame(ChapterStatus::Accepted, $novel->getChapter(2)->getStatus());
        self::assertGreaterThan($callsBefore, \count($this->calls($novel)));
    }

    public function testPauseRequestStopsAfterCurrentStep(): void
    {
        $novel = $this->createNovel(autoAccept: true);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true);
        $this->orchestrator->requestPause($novel); // pendant la rédaction du chapitre 1
        $this->drain();
        $this->refresh($novel);

        self::assertSame(NovelStatus::Paused, $novel->getStatus());
        self::assertSame(PauseReason::User, $novel->getPauseReason());
        self::assertNotNull($novel->getChapter(1)->getCurrentVersion(), 'L\'étape en cours est allée à son terme.');
        self::assertFalse($novel->getChapter(1)->isCurrentVersionReviewed(), 'Aucune nouvelle étape après l\'interruption.');

        $this->orchestrator->resume($novel);
        $this->drain();
        $this->refresh($novel);
        self::assertSame(NovelStatus::Completed, $novel->getStatus());
    }

    public function testCorrectionKeepsPreviousVersionAndFlagsLaterChapters(): void
    {
        $novel = $this->createNovel(autoAccept: true);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true);
        $this->drain();
        $this->refresh($novel);
        self::assertSame(NovelStatus::Completed, $novel->getStatus());

        $ch1 = $novel->getChapter(1);
        $original = $ch1->getCurrentVersion()->getContent();
        $passage = 'Paragraphe 2 du chapitre 1.';
        $this->orchestrator->requestCorrection($ch1, $passage, 'Rendre la scène plus vivante.');
        $this->drain();
        $this->refresh($novel);

        self::assertCount(2, $ch1->getVersions());
        self::assertSame($original, $ch1->getVersion(1)->getContent(), 'La version précédente est conservée intacte.');
        self::assertSame(VersionSource::Correction, $ch1->getCurrentVersion()->getSource());
        self::assertStringContainsString('Rendre la scène plus vivante.', $ch1->getCurrentVersion()->getReason());
        self::assertStringContainsString('Passage corrigé', $ch1->getCurrentVersion()->getContent());
        self::assertTrue($ch1->isCurrentVersionReviewed(), 'La nouvelle version est recontrôlée automatiquement.');
        self::assertSame(ChapterStatus::ToRecheck, $novel->getChapter(2)->getStatus());
        self::assertSame(ChapterStatus::ToRecheck, $novel->getChapter(3)->getStatus());
        self::assertNotNull($this->em->getRepository(MemorySnapshot::class)->findOneBy(['novel' => $novel, 'afterChapter' => 1, 'stale' => true]));
    }

    public function testManualEditAndRestoreNeverOverwrite(): void
    {
        $novel = $this->createNovel(autoAccept: false);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $this->orchestrator->advance($novel, true);
        $this->drain();
        $this->refresh($novel);

        $service = self::getContainer()->get(ChapterService::class);
        $ch1 = $novel->getChapter(1);
        $service->addVersion($ch1, 'Texte réécrit à la main.', VersionSource::ManualEdit, false, 'Essai');
        $service->restore($ch1, 1);
        $this->em->flush();

        self::assertCount(3, $ch1->getVersions());
        self::assertSame($ch1->getVersion(1)->getContent(), $ch1->getCurrentVersion()->getContent());
        self::assertSame(VersionSource::Restore, $ch1->getCurrentVersion()->getSource());
        self::assertSame(3, $this->em->getRepository(ChapterVersion::class)->count(['chapter' => $ch1]));
    }

    public function testAcceptanceRespectsChapterOrder(): void
    {
        $novel = $this->createNovel(autoAccept: false);
        $this->orchestrator->requestPlan($novel);
        $this->drain();
        $this->orchestrator->validatePlan($novel);
        $ch2 = $novel->getChapter(2);
        $service = self::getContainer()->get(ChapterService::class);
        $ch2->transitionTo(ChapterStatus::Writing);
        $service->addVersion($ch2, 'Texte.', VersionSource::ManualEdit, false);

        $this->expectException(UserFacingException::class);
        $service->accept($ch2);
    }
}
