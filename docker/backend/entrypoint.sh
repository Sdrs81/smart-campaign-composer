#!/bin/sh
# One image, several roles. Every role waits for the database; only the web role
# (no command, or a frankenphp flag) migrates and seeds, so workers never race it.
set -eu

DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-60}"
# Seed only when this table exists and is empty, so restarts never reseed.
SEED_CHECK_TABLE="${SEED_CHECK_TABLE:-customers}"

log() {
    echo "[entrypoint] $*"
}

wait_for_database() {
    waited=0
    until php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE"));
        new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD") ?: "");
    ' >/dev/null 2>&1; do
        waited=$((waited + 1))
        if [ "$waited" -ge "$DB_WAIT_SECONDS" ]; then
            log "database at ${DB_HOST:-?}:${DB_PORT:-5432} did not accept connections within ${DB_WAIT_SECONDS}s"
            exit 1
        fi
        sleep 1
    done
    log "database is reachable"
}

# Prints "empty" only when the table exists and has no rows.
seed_table_state() {
    php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE"));
        $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD") ?: "");
        $table = getenv("SEED_CHECK_TABLE");
        $exists = $pdo->prepare("SELECT to_regclass(?) IS NOT NULL");
        $exists->execute(["public." . $table]);
        if (! $exists->fetchColumn()) {
            echo "missing";
            exit;
        }
        $hasRows = $pdo->query(sprintf("SELECT EXISTS (SELECT 1 FROM \"%s\")", str_replace("\"", "", $table)))->fetchColumn();
        echo $hasRows ? "filled" : "empty";
    '
}

is_web_role() {
    [ "$#" -eq 0 ] || [ "${1#-}" != "$1" ] || [ "$1" = "frankenphp" ]
}

wait_for_database

if is_web_role "$@"; then
    # A key is only needed to boot; this API stores nothing encrypted, so a per-container
    # key is acceptable for the local demo. Set APP_KEY in the environment to pin it.
    if [ -z "${APP_KEY:-}" ]; then
        APP_KEY="$(php artisan key:generate --show)"
        export APP_KEY
        log "APP_KEY was empty; generated a temporary key for this container"
    fi

    php artisan migrate --force

    export SEED_CHECK_TABLE
    case "$(seed_table_state)" in
        empty)
            log "table ${SEED_CHECK_TABLE} is empty; seeding"
            php artisan db:seed --force
            ;;
        missing)
            log "table ${SEED_CHECK_TABLE} does not exist yet; skipping seed"
            ;;
        *)
            log "table ${SEED_CHECK_TABLE} already has rows; skipping seed"
            ;;
    esac
fi

exec docker-php-entrypoint "$@"
