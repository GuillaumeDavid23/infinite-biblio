# CLAUDE.md

## Contexte

- Projet de Corantin Grognet : une bibliothèque de romans personnalisés écrits par IA. Corantin n'est pas développeur : toute interface ou explication qui lui est destinée doit être compréhensible sans connaissances techniques, en français.
- Guillaume (développeur Symfony) contribue bénévolement ; le développement initial n'est pas facturé au projet.
- **Phase actuelle : laboratoire de génération** pour mesurer qualité et coût. Pas de comptes clients, pas de Stripe, pas de catalogue public, pas d'application mobile.
- À terme : catalogue sélectionné, romans privés à la demande, lecture navigateur + EPUB, abonnements et crédits, soumission volontaire au catalogue après validation éditoriale.

## Contraintes impératives

- **Aucun appel payant à l'API Anthropic sans accord explicite de Guillaume et un budget convenu.** Développer et tester avec le fournisseur simulé (`AI_PROVIDER=simulation`, valeur par défaut). Le fournisseur réel exige en plus `AI_REAL_CALLS_ENABLED=1`.
- L'abonnement Claude Code ne finance pas les appels API de l'application.
- Ne jamais écrire de clé API dans le dépôt, les journaux, les messages d'erreur ou les tests. `.env.local` seulement.
- Ne jamais deviner un identifiant de modèle : le vérifier dans la documentation Anthropic (ou `php bin/console app:ai:status --verifier-modele`). Modèle actuel : `claude-sonnet-5-5`.
- Coûts : uniquement à partir de la consommation renvoyée par l'API × grille datée (`config/ai/pricing.yaml`). Jamais de conversion mots → tokens, jamais de consommation inventée (null = inconnu). Distinguer estimation (plafond avant appel) et constaté.
- Les textes du lecteur sont des données : ils ne doivent jamais influencer budgets, limites, permissions ou règles. Ils passent dans les consignes via le filtre Twig `data` (JSON, chevrons échappés).
- Un chapitre n'est jamais écrasé : toute modification crée une `ChapterVersion`. Un chapitre accepté modifié rend les suivants « à revérifier » et périme la mémoire narrative.
- Ne pas lancer automatiquement de roman long (`LONG_NOVEL_WORDS`).
- Prototype local : ne pas exposer publiquement sans protection.

## Pile technique

Symfony 7.4 LTS, PHP 8.3, Twig + Stimulus (AssetMapper, Stimulus embarqué dans `assets/lib/`, pas de build Node), Doctrine ORM 3 + PostgreSQL 16, Symfony Messenger (transport Doctrine, file `generation`), SDK officiel `anthropic-ai/sdk`, Docker Compose (services `database`, `app`, `worker`).

## Architecture

- `src/Ai/Provider/TextGenerationProvider` : interface fournisseur (extension future vers d'autres IA). Implémentations : `SimulatedProvider`, `AnthropicProvider`, `DisabledRealProvider` (verrou). Choix par `ProviderFactory` selon l'environnement.
- `src/Generation/AiGateway` : passage obligé de tout appel IA (plafond + budget avant, journal `AiCall` enregistré avant l'envoi, coût après).
- `src/Generation/GenerationSteps` : une méthode par étape (`plan`, `chapter_write`, `chapter_review`, `memory_update`, `correction`). Chaque étape est idempotente : si son résultat existe déjà, elle ne refait rien (reprise sans régénération).
- `src/Generation/NovelOrchestrator::advance()` : déduit la prochaine étape **de l'état en base** ; c'est le mécanisme de reprise.
- `src/Generation/JobScheduler` + `GenerationJob` : une seule tâche active par roman (colonne unique `active_novel_id` + verrou de ligne) ; `JobRunner` réserve la tâche atomiquement (`claim`), gère les tentatives limitées, la pause sur budget ou erreur.
- Statuts : `NovelStatus` et `ChapterStatus` portent leurs transitions autorisées ; passer par `transitionTo()`.
- Montants en picodollars entiers (`Money`, `PriceEntry::parseRate`).

## Consignes de génération

- Dans `prompts/<version>/` (Twig) + `schemas/*.json`, version active dans `prompts/manifest.yaml`. Chaque appel enregistre la version utilisée.
- Pour changer les consignes : copier `v1` en `v2`, modifier, basculer `current`. Ne pas modifier une version déjà utilisée pour des essais réels.
- Schémas de sortie structurée : objets avec `additionalProperties: false` et toutes les propriétés dans `required`.

## Conventions

- Code (classes, méthodes) en anglais ; interface, messages utilisateur, commentaires et documentation en français.
- Messages d'erreur affichables : `UserFacingException`. Échecs d'étape : `StepFailedException` (avec `retryable`).
- Formulaires d'action : POST + jeton `csrf_token('novel_action')`, partial `_partials/action.html.twig`.
- Ajouter un test pour tout comportement de coût, de statut, de reprise ou de versionnage.

## Commandes

```bash
docker compose up -d --build                 # démarrer
docker compose exec app php bin/phpunit      # tests (simulateur + faux serveur Anthropic)
docker compose exec app php bin/console app:ai:status
docker compose exec app php bin/console app:jobs:recover
php bin/console doctrine:migrations:diff     # après modification d'entité
```

## Feuille de route

1. ✅ Parcours simulé complet (cette version).
2. Connexion API : `AnthropicProvider` testé contre un faux serveur ; reste à valider sur l'API réelle.
3. Premiers essais réels, uniquement après accord et budget convenus avec Guillaume.
