<?php

namespace App\Command;

use App\Generation\NovelOrchestrator;
use App\Repository\NovelRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:jobs:recover', description: 'Libère les étapes bloquées après un arrêt brutal (sans relancer de génération)')]
final class RecoverJobsCommand extends Command
{
    public function __construct(private readonly NovelRepository $novels, private readonly NovelOrchestrator $orchestrator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale en secondes (défaut : JOB_STALE_SECONDS)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $since = null !== $input->getOption('depuis') ? (int) $input->getOption('depuis') : null;
        $count = 0;
        foreach ($this->novels->findAll() as $novel) {
            if ($this->orchestrator->recoverStale($novel, $since)) {
                ++$count;
                $io->writeln('Étape interrompue libérée : '.$novel->getTitle());
            }
        }
        $io->success($count.' roman(s) débloqué(s). Utilisez « Reprendre » dans l\'interface pour continuer.');

        return Command::SUCCESS;
    }
}
