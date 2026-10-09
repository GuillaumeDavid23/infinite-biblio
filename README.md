# Bibliothèque infinie · Laboratoire de génération

Laboratoire local pour tester la qualité et le coût de romans écrits par une IA (Claude Sonnet, via l'API Anthropic) avec une méthode de rédaction structurée : demande → plan validé par un humain → chapitres un par un → contrôle de cohérence → corrections → export.

Ce n'est **pas** la plateforme commerciale : pas de comptes, pas de paiement, pas de catalogue. Le prototype est prévu pour tourner **sur votre machine uniquement**.

> **Par défaut, aucun appel payant n'est possible.** Le laboratoire démarre avec un fournisseur simulé qui produit des textes factices marqués `[SIMULATION]`. Les appels réels exigent deux réglages explicites (voir « Passer aux appels réels »).

## Installation avec Docker (recommandé, Windows + WSL 2)

Prérequis : [Docker Desktop](https://docs.docker.com/desktop/) avec l'intégration WSL 2 activée, et Git.

Sous Windows, clonez le projet **dans le système de fichiers WSL** (par exemple `~/projets`, pas `/mnt/c/...`) : c'est nettement plus rapide.

```bash
git clone https://github.com/GuillaumeDavid23/infinite-biblio.git
cd infinite-biblio
docker compose up -d --build
```

Le premier démarrage installe les dépendances PHP et crée la base (quelques minutes). Suivre l'avancement :

```bash
docker compose logs -f app
```

Ouvrez ensuite **http://localhost:8000**.

Trois services tournent :

| Service    | Rôle                                                              |
|------------|-------------------------------------------------------------------|
| `database` | PostgreSQL 16 (données et file d'attente des générations)         |
| `app`      | Interface web sur http://localhost:8000 (liée à 127.0.0.1)        |
| `worker`   | Traitement des générations en arrière-plan (Symfony Messenger)    |

Commandes utiles :

```bash
docker compose ps                                       # état des services
docker compose logs -f worker                           # suivre les générations
docker compose restart worker                           # relancer le traitement
docker compose exec app php bin/console app:ai:status   # configuration IA active
docker compose exec app php bin/phpunit                 # tests (voir plus bas)
docker compose down                                     # tout arrêter (les données restent)
```

## Installation sans Docker

Prérequis : PHP 8.3 (extensions `pdo_pgsql`, `intl`, `zip`), Composer 2, PostgreSQL 16.

```bash
composer install
cp .env .env.local      # puis adaptez DATABASE_URL dans .env.local
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
```

Dans deux terminaux :

```bash
php -S 127.0.0.1:8000 -t public public/index.php     # interface
php bin/console messenger:consume async -vv          # traitement en arrière-plan
```

## Utilisation

1. **Nouveau roman** : seule l'idée de départ est obligatoire. Le lien « Préremplir avec un exemple fictif » donne une demande complète.
2. **Générer la proposition d'histoire et le plan** : reformulation, synopsis, enjeux, fin, personnages, règles de l'univers, plan par chapitre.
3. **Modifier** puis **valider le plan**. Rien n'est rédigé avant la validation.
4. **Générer le chapitre 1**. Avec l'enchaînement automatique (activé par défaut), le contrôle de cohérence suit, puis le laboratoire attend votre relecture. Après votre acceptation, la mémoire narrative est mise à jour et le chapitre suivant démarre.
5. Sur chaque chapitre : alertes de cohérence, **correction ciblée d'un passage** (avec motif), modification manuelle, historique et restauration des versions.
6. **Interrompre après l'étape en cours** / **Reprendre** à tout moment. Une reprise ne refait jamais un chapitre déjà livré.
7. **Exporter** en Markdown ou EPUB, et consulter **le détail des coûts** de chaque appel.

Un guide pas à pas sans jargon est disponible dans [docs/guide-laboratoire.md](docs/guide-laboratoire.md).

## Coûts et budget

- Chaque appel est journalisé : roman, chapitre, étape, modèle exact, version des consignes, tokens d'entrée et de sortie, lecture et écriture en cache, durée, statut, erreurs, tentative.
- Le **coût constaté** est calculé uniquement à partir de la consommation renvoyée par l'API et d'une grille tarifaire datée (`config/ai/pricing.yaml`). Si l'API ne renvoie pas de consommation, le coût est affiché « inconnu » : rien n'est deviné.
- **Avant chaque appel**, le texte envoyé est mesuré par l'API (comptage de tokens, gratuit) et le coût maximal possible (sortie maximale autorisée) est réservé. Si ce maximum dépasse le budget restant du roman, l'appel n'est pas envoyé et le roman passe en pause.
- Un appel déjà envoyé ne peut pas être interrompu au centime près : il est facturé selon ce que le modèle a réellement produit, dans la limite de sortie fixée par étape.
- Tous les appels comptent dans le total : brouillons remplacés, corrections abandonnées, réponses inexploitables.
- Les coûts du simulateur sont **fictifs** et affichés à part.

Les montants sont stockés en picodollars entiers (10⁻¹² $) pour éviter les erreurs d'arrondi.

## Passer aux appels réels

L'abonnement utilisé pour Claude Code **ne finance pas** les appels de l'application : il faut une clé du [compte API Anthropic](https://platform.claude.com/), facturée à l'usage.

1. Vérifiez les tarifs sur la [page officielle](https://platform.claude.com/docs/en/about-claude/pricing) et mettez à jour `config/ai/pricing.yaml` si besoin (ajoutez une ligne datée plutôt que de modifier l'ancienne).
2. Créez `.env.local` (jamais versionné) :

   ```dotenv
   AI_PROVIDER=anthropic
   AI_REAL_CALLS_ENABLED=1
   ANTHROPIC_API_KEY=sk-ant-...
   ANTHROPIC_MODEL=claude-sonnet-5-5
   ```

3. Vérifiez la configuration et l'identifiant du modèle (consultation gratuite de l'API des modèles, aucune génération) :

   ```bash
   docker compose exec app php bin/console app:ai:status --verifier-modele
   ```

4. Redémarrez le traitement pour qu'il lise la nouvelle configuration : `docker compose restart worker app`.
5. Fixez un petit budget sur le roman d'essai (par exemple 1 $) avant de lancer le plan.

Pour revenir à la simulation : `AI_PROVIDER=simulation` dans `.env.local`, puis redémarrez `worker` et `app`.

### Modèle et paramètres

- Modèle par défaut : `claude-sonnet-5-5` (Claude Sonnet 5.5), identifiant vérifié dans la documentation Anthropic le 2026-10-09. Contexte de 1 M de tokens, sortie maximale de 128 K tokens. Tarif relevé : 2 $ / M tokens en entrée, 10 $ / M en sortie, 0,20 $ / M en lecture de cache.
- Réflexion adaptative (`thinking: {type: "adaptive"}`) avec un effort configurable (`ANTHROPIC_EFFORT`, `medium` par défaut). Sur ce modèle, la réflexion ne peut pas être désactivée ; ses tokens sont facturés comme de la sortie et comptent dans la limite de sortie.
- Sorties structurées (`output_config.format` avec schéma JSON) pour le plan, le contrôle, la mémoire et les corrections. Les schémas sont dans `prompts/v1/schemas/`.
- Les consignes système stables sont marquées pour le cache (`cache_control`) ; le cache ne s'applique que si le texte est assez long.
- Aucune reprise cachée dans le SDK (`maxRetries: 0`) : chaque tentative est décidée et journalisée par l'application.

## Configuration

Toutes les variables sont dans `.env` avec leur explication. Les principales :

| Variable | Défaut | Rôle |
|---|---|---|
| `AI_PROVIDER` | `simulation` | `simulation` ou `anthropic` |
| `AI_REAL_CALLS_ENABLED` | `0` | Verrou des appels payants |
| `ANTHROPIC_API_KEY` | vide | Clé API, uniquement dans `.env.local` |
| `ANTHROPIC_MODEL` | `claude-sonnet-5-5` | Identifiant exact du modèle |
| `ANTHROPIC_EFFORT` | `medium` | `low`, `medium`, `high`, `xhigh`, `max` |
| `AI_MAX_OUTPUT_TOKENS_*` | 8000 / 4000 | Limites de sortie par étape (plafonnent le coût d'un appel) |
| `JOB_MAX_ATTEMPTS` | `2` | Tentatives maximales par étape |
| `JOB_STALE_SECONDS` | `600` | Délai après lequel une étape silencieuse est considérée interrompue |
| `DEFAULT_NOVEL_BUDGET_USD` | `1.00` | Budget proposé à la création |
| `MAX_CHAPTERS` | `30` | Nombre maximal de chapitres |
| `LONG_NOVEL_WORDS` | `15000` | Au-delà, l'enchaînement automatique est désactivé |

## Tests

```bash
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test
docker compose exec app php bin/phpunit
```

Sans Docker, retirez `docker compose exec app`. Les tests utilisent le simulateur et un faux serveur Anthropic local : **ils ne font aucun appel payant**. Ils couvrent le calcul des coûts, les changements de statut, les doubles commandes et messages en double, les reprises après incident, la conservation des versions, les réponses structurées invalides, le budget, et la validité des exports Markdown et EPUB.

## Organisation du code

```
config/ai/pricing.yaml      Grille tarifaire datée
prompts/                    Consignes de génération versionnées (hors code métier)
  manifest.yaml             Version active
  v1/*.twig                 Consignes par étape
  v1/schemas/*.json         Schémas des réponses structurées
src/Ai/Provider/            Interface fournisseur, simulateur, intégration Anthropic
src/Ai/Pricing/             Tarifs, calcul des coûts
src/Ai/Prompt/              Rendu des consignes, validation des réponses
src/Generation/             Étapes, orchestration, budget, tâches, versions
src/Export/                 Markdown et EPUB
src/Controller/, templates/ Interface web
tests/                      Tests unitaires, d'intégration et fonctionnels
```

## Sécurité

- Aucune clé dans le dépôt ni dans les journaux. `.env.local` est ignoré par Git ; les messages d'erreur masquent les clés.
- Les textes du lecteur sont transmis à l'IA comme des **données** (encodées en JSON, balises neutralisées) et ne peuvent pas modifier les règles, les budgets ou les limites, qui sont appliqués par le code.
- L'interface n'a pas d'authentification : le port est lié à `127.0.0.1`. **Ne l'exposez pas sur Internet** sans protection adaptée.

## Limites connues

- Le plan n'est modifiable qu'avant sa validation.
- Le budget est vérifié avec un plafond pessimiste (sortie maximale) : la pause peut intervenir alors qu'il reste un peu de budget.
- Le contrôle de cohérence est une aide à la relecture produite par l'IA, pas une garantie de qualité.
- Les exports ne sont pas passés par `epubcheck` (structure vérifiée par les tests : `mimetype` non compressé en premier, manifeste, navigation, XHTML bien formé).
- Pas encore d'essai avec l'API réelle : les tarifs et le comportement exact doivent être confirmés lors du premier essai autorisé.
