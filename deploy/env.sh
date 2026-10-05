#!/usr/bin/env bash
# Set values in the backend .env, then re-cache the config and restart the
# queue worker and Reverb.
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

    # Never re-cache a config that runs Reverb, reachable from the internet,
    # on the example credentials from .env.example, which anyone can read.
    if grep -qE '^REVERB_APP_(KEY|SECRET)=kotak-local-' "$env"; then
        echo "Saved, but not applied: give Reverb its own credentials first (docs/deploy-aws-ec2.md, step 4)." >&2
        exit 1
    fi

    cd "$(dirname "$env")"
    php artisan optimize --quiet
    php artisan queue:restart --quiet
    php artisan reverb:restart --quiet
    echo "Config re-cached, worker and Reverb restarted."
}

main "$@"
