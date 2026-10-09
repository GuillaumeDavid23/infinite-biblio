<?php

namespace App\Controller;

use App\Ai\Pricing\Money;
use App\Ai\Provider\TextGenerationProvider;
use App\Entity\HumanReview;
use App\Entity\Novel;
use App\Exception\UserFacingException;
use App\Export\BookContent;
use App\Export\EpubExporter;
use App\Export\MarkdownExporter;
use App\Form\NovelBriefType;
use App\Generation\CostReport;
use App\Generation\NovelOrchestrator;
use App\Model\NovelBrief;
use App\Model\PlanForm;
use App\Repository\ConsistencyIssueRepository;
use App\Repository\GenerationJobRepository;
use App\Repository\MemorySnapshotRepository;
use App\Repository\NovelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class NovelController extends AbstractController
{
    public const CSRF_ID = 'novel_action';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NovelOrchestrator $orchestrator,
        private readonly GenerationJobRepository $jobs,
        private readonly TextGenerationProvider $provider,
    ) {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('novel_index');
    }

    #[Route('/romans', name: 'novel_index', methods: ['GET'])]
    public function index(NovelRepository $novels, CostReport $costs): Response
    {
        $rows = [];
        foreach ($novels->findAllRecentFirst() as $novel) {
            $report = $costs->forNovel($novel);
            $rows[] = ['novel' => $novel, 'real' => $report['real'], 'simulated' => $report['simulated']];
        }

        return $this->render('novel/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/romans/nouveau', name: 'novel_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[Autowire('%app.max_chapters%')] int $maxChapters,
        #[Autowire('%app.default_budget_usd%')] string $defaultBudget,
        #[Autowire('%app.long_novel_words%')] int $longNovelWords,
    ): Response {
        $brief = $request->query->getBoolean('exemple') ? NovelBrief::example() : new NovelBrief();
        $brief->budgetDollars = $defaultBudget;
        $form = $this->createForm(NovelBriefType::class, $brief, ['max_chapters' => $maxChapters]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($brief->chapterCount > $maxChapters) {
                $this->addFlash('error', \sprintf('Le laboratoire est limité à %d chapitres (réglage MAX_CHAPTERS).', $maxChapters));

                return $this->render('novel/new.html.twig', ['form' => $form], new Response(status: 422));
            }
            $novel = new Novel(trim((string) $brief->title) ?: 'Roman sans titre');
            $novel->setBrief($brief->toArray());
            $novel->setBudgetPico(Money::fromDollars($brief->budgetDollars));
            $novel->setAutoAccept($brief->autoAccept);
            $novel->setAutoContinue($brief->autoContinue);
            if ($brief->chapterCount * $brief->targetWordsPerChapter > $longNovelWords && $brief->autoContinue) {
                $novel->setAutoContinue(false);
                $this->addFlash('info', 'Roman long : l\'enchaînement automatique a été désactivé. Chaque chapitre sera lancé à la main.');
            }
            $this->em->persist($novel);
            $this->em->flush();
            $this->addFlash('success', 'Demande enregistrée. Prochaine étape : générer la proposition d\'histoire et le plan.');

            return $this->redirectToRoute('novel_show', ['id' => $novel->getId()]);
        }

        return $this->render('novel/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/romans/{id}', name: 'novel_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Novel $novel, CostReport $costs, ConsistencyIssueRepository $issues, MemorySnapshotRepository $memories): Response
    {
        $lastChapter = $novel->getChapters()->last() ?: null;

        return $this->render('novel/show.html.twig', [
            'novel' => $novel,
            'activeJob' => $this->jobs->findActive($novel),
            'recentJobs' => $this->jobs->findRecent($novel, 8),
            'costs' => $costs->forNovel($novel),
            'issues' => $issues->findForNovel($novel),
            'openIssues' => $issues->countOpenForNovel($novel),
            'memory' => null !== $lastChapter ? $memories->findLatestBefore($novel, $lastChapter->getNumber() + 1) : null,
            'reviews' => $this->em->getRepository(HumanReview::class)->findBy(['novel' => $novel], ['id' => 'DESC']),
            'fingerprint' => $this->fingerprint($novel),
            'providerSimulated' => $this->provider->isSimulated(),
        ]);
    }

    #[Route('/romans/{id}/etat', name: 'novel_state', methods: ['GET'])]
    public function state(Novel $novel): JsonResponse
    {
        $job = $this->jobs->findActive($novel);

        return new JsonResponse([
            'fingerprint' => $this->fingerprint($novel),
            'status' => $novel->getStatus()->value,
            'active' => null !== $job,
        ]);
    }

    #[Route('/romans/{id}/plan/generer', name: 'novel_plan_generate', methods: ['POST'])]
    public function generatePlan(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, function () use ($novel) {
            $this->orchestrator->requestPlan($novel);

            return 'Préparation de l\'histoire et du plan lancée.';
        });
    }

    #[Route('/romans/{id}/plan/modifier', name: 'novel_plan_edit', methods: ['GET', 'POST'])]
    public function editPlan(Novel $novel, Request $request): Response
    {
        if (\App\Enum\NovelStatus::PlanReady !== $novel->getStatus() || null === $novel->getPlan()) {
            $this->addFlash('error', 'Le plan n\'est modifiable qu\'avant sa validation.');

            return $this->redirectToRoute('novel_show', ['id' => $novel->getId()]);
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $novel->setPlan(PlanForm::fromRequest($request->request->all('plan'), $novel->getPlan(), $novel->getChapterCount()));
            if ('' !== trim((string) $request->request->get('title'))) {
                $novel->setTitle(mb_substr(trim((string) $request->request->get('title')), 0, 200));
            }
            $this->em->flush();
            $this->addFlash('success', 'Plan enregistré. Relisez-le puis validez-le pour lancer la rédaction.');

            return $this->redirectToRoute('novel_show', ['id' => $novel->getId(), '_fragment' => 'plan']);
        }

        return $this->render('novel/plan_edit.html.twig', ['novel' => $novel, 'plan' => $novel->getPlan()]);
    }

    #[Route('/romans/{id}/plan/valider', name: 'novel_plan_validate', methods: ['POST'])]
    public function validatePlan(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, function () use ($novel) {
            $this->orchestrator->validatePlan($novel);

            return 'Plan validé. Vous pouvez lancer la rédaction du premier chapitre.';
        });
    }

    #[Route('/romans/{id}/generer', name: 'novel_generate', methods: ['POST'])]
    public function generate(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, fn () => $this->orchestrator->advance($novel, true));
    }

    #[Route('/romans/{id}/interrompre', name: 'novel_pause', methods: ['POST'])]
    public function pause(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, fn () => $this->orchestrator->requestPause($novel));
    }

    #[Route('/romans/{id}/reprendre', name: 'novel_resume', methods: ['POST'])]
    public function resume(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, fn () => $this->orchestrator->resume($novel));
    }

    #[Route('/romans/{id}/reglages', name: 'novel_settings', methods: ['POST'])]
    public function settings(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, function () use ($novel, $request) {
            $budget = (string) $request->request->get('budget');
            try {
                $novel->setBudgetPico(Money::fromDollars($budget));
            } catch (\InvalidArgumentException) {
                throw new UserFacingException('Budget invalide : indiquez un montant comme 1.50');
            }
            $novel->setAutoContinue($request->request->getBoolean('autoContinue'));
            $novel->setAutoAccept($request->request->getBoolean('autoAccept'));
            $this->em->flush();

            return 'Réglages enregistrés.';
        }, 'reglages');
    }

    #[Route('/romans/{id}/appreciation', name: 'novel_review', methods: ['POST'])]
    public function review(Novel $novel, Request $request): Response
    {
        return $this->action($request, $novel, function () use ($novel, $request) {
            try {
                $review = new HumanReview(
                    $novel,
                    $request->request->getInt('style'),
                    $request->request->getInt('dialogues'),
                    $request->request->getInt('coherence'),
                    trim((string) $request->request->get('correctionsNeeded')) ?: null,
                    trim((string) $request->request->get('comment')) ?: null,
                );
            } catch (\InvalidArgumentException $e) {
                throw new UserFacingException($e->getMessage());
            }
            $this->em->persist($review);
            $this->em->flush();

            return 'Merci, votre appréciation est enregistrée.';
        }, 'appreciation');
    }

    #[Route('/romans/{id}/couts', name: 'novel_costs', methods: ['GET'])]
    public function costs(Novel $novel, CostReport $costs): Response
    {
        return $this->render('novel/costs.html.twig', ['novel' => $novel, 'costs' => $costs->forNovel($novel)]);
    }

    #[Route('/romans/{id}/export.md', name: 'novel_export_md', methods: ['GET'])]
    public function exportMarkdown(Novel $novel, MarkdownExporter $exporter): Response
    {
        $response = new Response($exporter->export(BookContent::fromNovel($novel)));
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->fileName($novel, 'md')));

        return $response;
    }

    #[Route('/romans/{id}/export.epub', name: 'novel_export_epub', methods: ['GET'])]
    public function exportEpub(Novel $novel, EpubExporter $exporter): Response
    {
        $path = $exporter->export(BookContent::fromNovel($novel), $novel->getUpdatedAt());
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/epub+zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->fileName($novel, 'epub'));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * Exécute une action POST protégée par jeton, affiche le résultat et revient sur la page du roman.
     */
    private function action(Request $request, Novel $novel, callable $fn, string $fragment = ''): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré : rechargez la page et réessayez.');
        } else {
            try {
                $this->addFlash('success', (string) $fn());
            } catch (UserFacingException|\App\Exception\InvalidTransitionException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        $target = $request->request->get('_back');
        if (\is_string($target) && str_starts_with($target, '/romans/')) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute('novel_show', ['id' => $novel->getId(), '_fragment' => $fragment]);
    }

    private function fingerprint(Novel $novel): string
    {
        $job = $this->jobs->findActive($novel);
        $parts = [$novel->getStatus()->value, $novel->getPlanRevision(), $job?->getId(), $job?->getStatus()->value, $job?->getAttempts()];
        foreach ($novel->getChapters() as $chapter) {
            $parts[] = $chapter->getStatus()->value.':'.$chapter->getCurrentVersion()?->getNumber().':'.$chapter->getReviewedVersionNumber();
        }

        return md5(implode('|', array_map('strval', $parts)));
    }

    private function fileName(Novel $novel, string $ext): string
    {
        return (new AsciiSlugger('fr'))->slug($novel->getTitle())->lower()->truncate(60).'.'.$ext;
    }
}
