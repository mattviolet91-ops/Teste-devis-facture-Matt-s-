#!/usr/bin/env bash
# Fabrique l'archive à envoyer sur o2switch (dépendances incluses, sans les
# fichiers de développement). Usage : scripts/build-release.sh [dossier_sortie]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/build}"
VERSION="$(git -C "$ROOT" rev-parse --short HEAD)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

git -C "$ROOT" archive HEAD | tar -x -C "$WORK"
(cd "$WORK" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet)
rm -rf "$WORK/tests" "$WORK/.github" "$WORK/phpunit.xml" "$WORK/.env.example"
mkdir -p "$WORK/storage/app/private" "$WORK/storage/framework/"{cache/data,sessions,views} "$WORK/storage/logs"

mkdir -p "$OUT"
ARCHIVE="$OUT/gestion-matts-couverture-$VERSION.zip"
rm -f "$ARCHIVE"
(cd "$WORK" && zip -qr "$ARCHIVE" . -x '*.DS_Store' '*/.git/*')
echo "$ARCHIVE"
