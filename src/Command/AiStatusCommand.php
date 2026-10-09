<?php

namespace App\Command;

use Anthropic\Client;
use App\Ai\Pricing\PricingCatalog;
use App\Ai\Prompt\PromptLibrary;
use App\Ai\Provider\TextGenerationProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Affiche la configuration IA active. Avec --verifier-modele, interroge l'API des modèles
 * d'Anthropic (consultation gratuite, aucune génération de texte) pour confirmer l'identifiant.
 */
#[AsCommand(name: 'app:ai:status', description: 'Affiche la configuration IA et vérifie (optionnellement) le modèle auprès d\'Anthropic')]
final class AiStatusCommand extends Command
{
    public function __construct(
        private readonly TextGenerationProvider $provider,
        private readonly PricingCatalog $pricing,
        private readonly PromptLibrary $prompts,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] #[\SensitiveParameter] private readonly string $apiKey,
        #[Autowire('%env(bool:AI_REAL_CALLS_ENABLED)%')] private readonly bool $realCallsEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('verifier-modele', null, InputOption::VALUE_NONE, 'Interroge GET /v1/models/{modèle} (nécessite ANTHROPIC_API_KEY, aucun token consommé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $price = $this->pricing->find($this->provider->model());
        $io->definitionList(
            ['Fournisseur' => $this->provider->name().($this->provider->isSimulated() ? ' (simulé, gratuit)' : '')],
            ['Appels payants autorisés' => $this->realCallsEnabled ? 'oui' : 'non (AI_REAL_CALLS_ENABLED=0)'],
            ['Modèle' => $this->provider->model()],
            ['Clé API' => '' !== $this->apiKey ? 'renseignée (non affichée)' : 'absente'],
            ['Grille tarifaire' => $price?->reference() ?? 'AUCUNE : les appels seront refusés'],
            ['Version des consignes' => $this->prompts->version()],
        );

        if ($input->getOption('verifier-modele')) {
            if ('' === $this->apiKey) {
                $io->error('ANTHROPIC_API_KEY est vide : ajoutez-la dans .env.local.');

                return Command::FAILURE;
            }
            try {
                $info = (new Client(apiKey: $this->apiKey))->models->retrieve($this->provider->model());
                $io->success(\sprintf('Modèle reconnu par Anthropic : %s (%s), sortie maximale %s tokens.', $info->id, $info->displayName, $info->maxTokens ?? '?'));
            } catch (\Throwable $e) {
                $io->error('Vérification impossible : '.preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
