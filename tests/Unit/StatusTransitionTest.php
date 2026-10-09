<?php

namespace App\Tests\Unit;

use App\Entity\Chapter;
use App\Entity\GenerationJob;
use App\Entity\Novel;
use App\Enum\ChapterStatus;
use App\Enum\JobStatus;
use App\Enum\NovelStatus;
use App\Enum\PauseReason;
use App\Enum\Step;
use App\Exception\InvalidTransitionException;
use PHPUnit\Framework\TestCase;

final class StatusTransitionTest extends TestCase
{
    public function testNominalNovelLifecycle(): void
    {
        $novel = new Novel('Test');
        foreach ([NovelStatus::Planning, NovelStatus::PlanReady, NovelStatus::Writing, NovelStatus::Completed] as $status) {
            $novel->transitionTo($status);
            self::assertSame($status, $novel->getStatus());
        }
    }

    public function testCannotWriteBeforePlan(): void
    {
        $this->expectException(InvalidTransitionException::class);
        (new Novel('Test'))->transitionTo(NovelStatus::Writing);
    }

    public function testPauseKeepsReasonAndResumeClearsIt(): void
    {
        $novel = new Novel('Test');
        $novel->transitionTo(NovelStatus::Planning);
        $novel->setPauseRequested(true);
        $novel->pause(PauseReason::Budget, 'Budget atteint');
        self::assertSame(PauseReason::Budget, $novel->getPauseReason());
        self::assertFalse($novel->isPauseRequested());
        $novel->transitionTo(NovelStatus::Planning);
        self::assertNull($novel->getPauseReason());
    }

    public function testChapterCannotBeAcceptedWithoutText(): void
    {
        $chapter = new Chapter(new Novel('Test'), 1, 'Un');
        $this->expectException(InvalidTransitionException::class);
        $chapter->transitionTo(ChapterStatus::Accepted);
    }

    public function testAcceptedChapterCanOnlyGoBackToReviewOrRecheck(): void
    {
        self::assertTrue(ChapterStatus::Accepted->canTransitionTo(ChapterStatus::ToReview));
        self::assertTrue(ChapterStatus::Accepted->canTransitionTo(ChapterStatus::ToRecheck));
        self::assertFalse(ChapterStatus::Accepted->canTransitionTo(ChapterStatus::Planned));
        self::assertFalse(ChapterStatus::Accepted->canTransitionTo(ChapterStatus::Writing));
    }

    public function testJobFinishReleasesActiveSlot(): void
    {
        $job = new GenerationJob(new Novel('Test'), Step::Plan, null, 2);
        self::assertTrue($job->getStatus()->isActive());
        $job->finish(JobStatus::Done);
        self::assertFalse($job->getStatus()->isActive());
        $this->expectException(\LogicException::class);
        $job->finish(JobStatus::Running);
    }
}
