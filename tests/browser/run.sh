#!/bin/bash
# Test navigateur : base SQLite neuve, serveur local, puis vérifications (voir checks.js).
# Prérequis : dépendances Composer installées, Node et Playwright (Chromium) disponibles.
set -euo pipefail
cd "$(dirname "$0")/../.."

DB="$(mktemp -t navigateur-XXXXXX.sqlite)"
PORT="${PORT:-8765}"
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_URL="http://127.0.0.1:$PORT" CLIENT_URL="http://127.0.0.1:$PORT"

php artisan migrate --force --quiet
URLS="$(php tests/browser/setup.php)"
(cd public && exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -f "$DB"' EXIT
sleep 3

node tests/browser/checks.js "http://127.0.0.1:$PORT" "$URLS"
