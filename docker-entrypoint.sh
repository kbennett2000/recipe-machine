#!/bin/sh
# Recipe Machine startup script.
#
# Idempotent: safe to re-run on every container start.
#   1. Ensure database/database.sqlite exists (the bind mount expects it).
#   2. Run any pending migrations.
#   3. Reindex the corpus IF the recipes table is empty (fresh start) —
#      but skip reindex on warm restarts so we don't lose the LLM cache
#      tombstones or burn an unnecessary indexer pass on every container
#      cycle.
#   4. Hand off to the main process (php artisan serve).
set -e

DB=/var/www/html/database/database.sqlite
mkdir -p /var/www/html/database
touch "$DB"
chmod ug+rw "$DB"

echo "[entrypoint] Running migrations…"
php artisan migrate --force --no-interaction

# Reindex only if the cache is empty. This lets a fresh `docker compose
# up --build` come up usable with one command, while warm restarts skip
# the reindex (which would clobber LLM cache + see-also data).
COUNT=$(php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require '/var/www/html/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
try { echo DB::table('recipes')->count(); } catch (\\Throwable \$e) { echo 0; }
")
if [ "$COUNT" = "0" ]; then
    echo "[entrypoint] Empty cache — running first-boot reindex…"
    php artisan recipes:reindex
else
    echo "[entrypoint] Cache already populated ($COUNT recipes). Skipping reindex on warm restart."
fi

exec "$@"
