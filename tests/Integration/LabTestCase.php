<?php

namespace App\Tests\Integration;

use App\Ai\Pricing\Money;
use App\Ai\Provider\SimulatedProvider;
use App\Ai\Provider\TextGenerationProvider;
use App\Entity\AiCall;
use App\Entity\Novel;
use App\Generation\JobRunner;
use App\Generation\NovelOrchestrator;
use App\Message\RunGenerationJob;
use App\Model\NovelBrief;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

abstract class LabTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected NovelOrchestrator $orchestrator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->orchestrator = self::getContainer()->get(NovelOrchestrator::class);
        $this->em->getConnection()->executeStatement('TRUNCATE novel, messenger_messages RESTART IDENTITY CASCADE');
    }

    protected function simulator(): SimulatedProvider
    {
        $provider = self::getContainer()->get(TextGenerationProvider::class);
        self::assertInstanceOf(SimulatedProvider::class, $provider);

        return $provider;
    }

    protected function createNovel(bool $autoAccept = true, bool $autoContinue = true, string $budget = '5.00', int $chapters = 3): Novel
    {
        $brief = NovelBrief::example();
        $brief->chapterCount = $chapters;
        $novel = new Novel('Essai');
        $novel->setBrief($brief->toArray());
        $novel->setBudgetPico(Money::fromDollars($budget));
        $novel->setAutoAccept($autoAccept);
        $novel->setAutoContinue($autoContinue);
        $this->em->persist($novel);
        $this->em->flush();

        return $novel;
    }

    /** Exécute toutes les tâches en attente, comme le ferait le processus en arrière-plan. */
    protected function drain(int $max = 100): int
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $runner = self::getContainer()->get(JobRunner::class);
        $count = 0;
        while ($envelopes = iterator_to_array($transport->get())) {
            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                $message = $envelope->getMessage();
                self::assertInstanceOf(RunGenerationJob::class, $message);
                $runner->run($message->jobId);
                if (++$count > $max) {
                    self::fail('Boucle de tâches infinie.');
                }
            }
        }

        return $count;
    }

    /** @return list<AiCall> */
    protected function calls(Novel $novel): array
    {
        return $this->em->getRepository(AiCall::class)->findBy(['novel' => $novel], ['id' => 'ASC']);
    }

    protected function refresh(Novel $novel): Novel
    {
        $this->em->refresh($novel);
        foreach ($novel->getChapters() as $chapter) {
            $this->em->refresh($chapter);
        }

        return $novel;
    }
}
