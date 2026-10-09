<?php

namespace App\Controller;

use App\Entity\Chapter;
use App\Entity\ConsistencyIssue;
use App\Entity\Novel;
use App\Enum\ChapterStatus;
use App\Enum\IssueStatus;
use App\Enum\VersionSource;
use App\Exception\InvalidTransitionException;
use App\Exception\UserFacingException;
use App\Generation\ChapterService;
use App\Generation\NovelOrchestrator;
use App\Repository\ConsistencyIssueRepository;
use App\Repository\GenerationJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/romans/{id}/chapitres/{number}', requirements: ['id' => '\d+', 'number' => '\d+'])]
final class ChapterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChapterService $chapters,
        private readonly NovelOrchestrator $orchestrator,
        private readonly GenerationJobRepository $jobs,
    ) {
    }

    #[Route('', name: 'chapter_show', methods: ['GET'])]
    public function show(Novel $novel, int $number, ConsistencyIssueRepository $issues, Request $request): Response
    {
        $chapter = $this->chapter($novel, $number);
        $versionNumber = $request->query->getInt('version');
        $version = $versionNumber > 0 ? $chapter->getVersion($versionNumber) : $chapter->getCurrentVersion();
        if ($versionNumber > 0 && null === $version) {
            throw $this->createNotFoundException('Version introuvable.');
        }

        return $this->render('chapter/show.html.twig', [
            'novel' => $novel,
            'chapter' => $chapter,
            'version' => $version,
            'isCurrent' => $version === $chapter->getCurrentVersion(),
            'issues' => $issues->findForChapter($chapter),
            'activeJob' => $this->jobs->findActive($novel),
            'previous' => $novel->getChapter($number - 1),
            'next' => $novel->getChapter($number + 1),
        ]);
    }

    #[Route('/accepter', name: 'chapter_accept', methods: ['POST'])]
    public function accept(Novel $novel, int $number, Request $request): Response
    {
        return $this->action($request, $novel, $number, function (Chapter $chapter) use ($novel) {
            $this->chapters->accept($chapter);
            $this->orchestrator->reopenIfCompleted($novel);
            $this->em->flush();
            $next = $this->orchestrator->advance($novel, $novel->isAutoContinue());

            return 'Chapitre '.$chapter->getNumber().' accepté. '.$next;
        });
    }

    #[Route('/reverifier', name: 'chapter_recheck', methods: ['POST'])]
    public function recheck(Novel $novel, int $number, Request $request): Response
    {
        return $this->action($request, $novel, $number, function (Chapter $chapter) use ($novel) {
            if (ChapterStatus::ToRecheck !== $chapter->getStatus()) {
                throw new UserFacingException('Ce chapitre n\'est pas marqué « à revérifier ».');
            }
            $chapter->transitionTo(ChapterStatus::ToReview);
            $chapter->setReviewedVersionNumber(null);
            $this->orchestrator->reopenIfCompleted($novel);
            $this->em->flush();

            return $this->orchestrator->advance($novel);
        });
    }

    #[Route('/corriger', name: 'chapter_correct', methods: ['POST'])]
    public function correct(Novel $novel, int $number, Request $request): Response
    {
        return $this->action($request, $novel, $number, function (Chapter $chapter) use ($request) {
            $this->orchestrator->requestCorrection($chapter, (string) $request->request->get('passage'), (string) $request->request->get('reason'));

            return 'Correction lancée. La version actuelle est conservée dans l\'historique.';
        });
    }

    #[Route('/modifier', name: 'chapter_edit', methods: ['GET', 'POST'])]
    public function edit(Novel $novel, int $number, Request $request): Response
    {
        $chapter = $this->chapter($novel, $number);
        if (null === $chapter->getCurrentVersion()) {
            throw $this->createNotFoundException('Ce chapitre n\'a pas encore de texte.');
        }
        if ($request->isMethod('POST')) {
            return $this->action($request, $novel, $number, function (Chapter $chapter) use ($novel, $request) {
                if (null !== $this->jobs->findActive($novel)) {
                    throw new UserFacingException('Une étape est en cours : attendez sa fin avant de modifier le texte.');
                }
                $content = str_replace("\r\n", "\n", (string) $request->request->get('content'));
                if ($content === $chapter->getCurrentVersion()?->getContent()) {
                    throw new UserFacingException('Aucune modification détectée.');
                }
                $reason = trim((string) $request->request->get('reason')) ?: 'Modification manuelle';
                $this->chapters->addVersion($chapter, $content, VersionSource::ManualEdit, false, $reason);
                $this->orchestrator->reopenIfCompleted($novel);
                $this->em->flush();

                return 'Nouvelle version enregistrée. '.$this->orchestrator->advance($novel);
            });
        }

        return $this->render('chapter/edit.html.twig', ['novel' => $novel, 'chapter' => $chapter]);
    }

    #[Route('/restaurer/{version}', name: 'chapter_restore', methods: ['POST'], requirements: ['version' => '\d+'])]
    public function restore(Novel $novel, int $number, int $version, Request $request): Response
    {
        return $this->action($request, $novel, $number, function (Chapter $chapter) use ($novel, $version) {
            if (null !== $this->jobs->findActive($novel)) {
                throw new UserFacingException('Une étape est en cours : attendez sa fin.');
            }
            $this->chapters->restore($chapter, $version);
            $this->orchestrator->reopenIfCompleted($novel);
            $this->em->flush();

            return 'Version '.$version.' restaurée comme nouvelle version. '.$this->orchestrator->advance($novel);
        });
    }

    #[Route('/alertes/{issue}', name: 'chapter_issue_status', methods: ['POST'], requirements: ['issue' => '\d+'])]
    public function issueStatus(Novel $novel, int $number, int $issue, Request $request): Response
    {
        return $this->action($request, $novel, $number, function (Chapter $chapter) use ($issue, $request) {
            $entity = $this->em->find(ConsistencyIssue::class, $issue);
            if (null === $entity || $entity->getChapter() !== $chapter) {
                throw new UserFacingException('Alerte introuvable.');
            }
            $status = IssueStatus::tryFrom((string) $request->request->get('status')) ?? throw new UserFacingException('Statut inconnu.');
            $entity->setStatus($status);
            $this->em->flush();

            return 'Alerte marquée « '.$status->label().' ».';
        });
    }

    private function chapter(Novel $novel, int $number): Chapter
    {
        return $novel->getChapter($number) ?? throw $this->createNotFoundException('Chapitre introuvable.');
    }

    private function action(Request $request, Novel $novel, int $number, callable $fn): Response
    {
        $chapter = $this->chapter($novel, $number);
        if (!$this->isCsrfTokenValid(NovelController::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré : rechargez la page et réessayez.');
        } else {
            try {
                $this->addFlash('success', (string) $fn($chapter));
            } catch (UserFacingException|InvalidTransitionException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        $back = $request->request->get('_back');
        if (\is_string($back) && str_starts_with($back, '/romans/')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('chapter_show', ['id' => $novel->getId(), 'number' => $number]);
    }
}
