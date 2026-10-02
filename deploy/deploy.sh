#!/usr/bin/env bash
# Pull and deploy both repositories on the server. See docs/deploy-aws-ec2.md.
# Usage: deploy/deploy.sh [/var/www/kotak]
#
# Everything runs inside main(), so bash has read the whole script before
# "git pull" can replace this file.
set -Eeuo pipefail

main() {
    local root="${1:-/var/www/kotak}"
    local backend="$root/kotak-parcel-backend"
    local frontend="$root/kotak-parcel-frontend"

    # Files created here stay writable for the web server's group.
    umask 0002

    # A failed step leaves the site in maintenance mode, so it never serves
    # new code with the old database schema or assets.
    trap "echo 'Deploy failed; the site is still down for maintenance. Fix the error and run this script again, or bring the site back with: cd $backend && php artisan view:clear && php artisan up' >&2" ERR

    cd "$backend"
    # Compiled views left by the web server can make "down --render" fail.
    php artisan view:clear
    # A pre-rendered page keeps working while the build replaces public/build.
    php artisan down --retry=15 --render="errors::503"

    git pull --ff-only
    composer install --no-dev --optimize-autoloader --no-interaction
    # The frontend build reads the routes (wayfinder:generate), so drop the
    # previous route cache first.
    php artisan route:clear

    cd "$frontend"
    git pull --ff-only
    npm ci
    npm run build

    cd "$backend"
    php artisan migrate --force
    php artisan optimize
    php artisan queue:restart
    sudo systemctl reload php-fpm
    php artisan up
}

main "$@"
exit
