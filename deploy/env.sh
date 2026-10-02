#!/usr/bin/env bash
# Set values in the backend .env, then re-cache the config and restart the worker.
# No editor needed:
#
#   deploy/env.sh APP_URL=https://kotak.example.com SESSION_SECURE_COOKIE=true TRUSTED_PROXIES='*'
#   deploy/env.sh MAIL_MAILER=smtp MAIL_PASSWORD='secret with spaces'
#
# A key that exists is replaced in place, a new key is added at the end.
set -Eeuo pipefail

main() {
    local env="${BACKEND:-/var/www/kotak/kotak-parcel-backend}/.env"
    [ $# -gt 0 ] || { echo "Usage: deploy/env.sh KEY=value [KEY=value ...]" >&2; exit 1; }
    [ -f "$env" ] || { echo "No $env" >&2; exit 1; }

    for pair in "$@"; do
        local key="${pair%%=*}" value="${pair#*=}"
        [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || { echo "Bad key: $key" >&2; exit 1; }
        # Quote values with spaces or # so .env reads them whole.
        [[ "$value" =~ [[:space:]#] ]] && value="\"${value//\"/\\\"}\""
        if grep -q "^#\? \?$key=" "$env"; then
            KEY="$key" VALUE="$value" awk -i inplace 'BEGIN{k=ENVIRON["KEY"]; v=ENVIRON["VALUE"]} $0 ~ "^#? ?" k "=" && !done {print k "=" v; done=1; next} 1' "$env"
        else
            printf '%s=%s\n' "$key" "$value" >> "$env"
        fi
        echo "set $key"
    done

    cd "$(dirname "$env")"
    php artisan optimize --quiet
    php artisan queue:restart --quiet
    echo "Config re-cached, worker restarted."
}

main "$@"
