#!/bin/sh
# Prépare le projet au démarrage du conteneur : dépendances, base de données, migrations.
set -e
cd /app

echo "Attente de PostgreSQL…"
until pg_isready -h database -U app -q; do sleep 1; done

if [ "${PREPARE_PROJECT:-0}" = "1" ]; then
    # Conteneur « app » : installe les dépendances et met la base à jour.
    if [ ! -f vendor/autoload_runtime.php ]; then
        echo "Installation des dépendances PHP (première fois, quelques minutes)…"
        composer install --no-interaction --prefer-dist
    fi
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
else
    # Conteneur « worker » : attend que le conteneur « app » ait tout préparé.
    echo "Attente de la préparation du projet…"
    until [ -f vendor/autoload_runtime.php ] && php bin/console doctrine:migrations:up-to-date -q >/dev/null 2>&1; do sleep 3; done
fi

exec "$@"
