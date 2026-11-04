#!/bin/sh
# Prepare un repertoire de distribution autonome (PHAR et image Docker) :
# dependances de production issues du lock, plus dompdf pour les rapports PDF.
#
# Usage : prepare-dist.sh <repertoire cible>
set -eu

TARGET="$1"
SOURCE="$(cd "$(dirname "$0")/../.." && pwd)"

rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -R "$SOURCE/bin" "$SOURCE/src" "$SOURCE/templates" "$SOURCE/data" "$SOURCE/config" \
      "$SOURCE/composer.json" "$SOURCE/composer.lock" "$SOURCE/LICENSE" "$TARGET/"

cd "$TARGET"
composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

# dompdf est une dependance optionnelle du paquet Composer : dans la distribution autonome,
# il est inclus pour que --format=pdf fonctionne sans installation supplementaire.
# Versions epinglees, identiques a celles auditees dans composer.lock.
composer require --no-interaction --no-progress --prefer-dist --no-scripts --update-no-dev \
    dompdf/dompdf:3.1.6 dompdf/php-font-lib:1.0.2 dompdf/php-svg-lib:1.0.2 \
    masterminds/html5:2.12.0 sabberworm/php-css-parser:9.5.0

composer dump-autoload --no-dev --classmap-authoritative --no-interaction
