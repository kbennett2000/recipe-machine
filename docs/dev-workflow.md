# Development workflow

How to work on Recipe Machine — adding recipes, running tests,
debugging, and shipping changes.

## Prerequisites

- Docker + Docker Compose
- (Optional) `make` for the wrapper targets in the [Makefile](../Makefile).
- (Optional, for asset edits) Node 20+ on the host for `npm run build`.
  Node is also installed inside the container so the PHP↔JS parity
  test runs in CI.

## First-time setup

```sh
git clone <this repo>
cd recipe-machine
cp .env.example .env
touch database/database.sqlite     # required for the bind mount
make rebuild                       # build image + boot + migrate
make reindex                       # parse recipes/ into the cache
```

Now open <http://localhost:8000>. (To use a different host port, set
`APP_PORT` in `.env` before `make dev` — see
[Changing the port](#changing-the-port) below.)

## Changing the port

The host-facing port the container binds to is configurable via the
`APP_PORT` environment variable. Default is `8000`.

```sh
# In .env (or your shell)
APP_PORT=8080
```

Then `make dev` (or `docker compose up -d`) — the app will be served
at `http://<host>:8080/`. The container-internal port stays at 8000
regardless; only the host-side mapping changes.

## Offline operation

Recipe Machine is designed to run **fully offline** on a LAN once
the image is built and the recipes are indexed. There are no CDN
dependencies, no Google Fonts, no analytics, no telemetry. Specifically:

- **Fonts**: Fraunces + Inter are self-hosted under
  [public/fonts/](../public/fonts/).
- **JS/CSS**: bundled into the image at build time via Vite; no
  runtime fetches to package CDNs.
- **Database**: SQLite, local file.
- **LLM fallback** (Phase 9): **opt-in, indexer-only**. Disabled by
  default (`RECIPE_MACHINE_LLM_FALLBACK=false` in `.env`). Even when
  enabled, it only makes network calls when you explicitly run
  `php artisan recipes:reindex --with-llm` — never during request
  handling. Leave it off to keep the app 100 % offline.

The initial `make rebuild` does need internet (Docker pulls the
base image; Composer + npm pull dependencies during the build).
After that, nothing on the LAN host needs to reach the public
internet to use Recipe Machine.

## The Makefile targets

```sh
make dev        # start the container in the background
make rebuild    # rebuild the image (after Dockerfile / dep changes)
make assets     # rebuild Vite CSS/JS bundle on the host
make test       # full PHPUnit suite (498 tests)
make parity     # PHP↔JS formatter parity test only
make reindex    # rebuild the SQLite cache from recipes/*.md
make fresh      # migrate:fresh + reindex (drops all cached data)
make shell      # open a shell inside the running container
make down       # stop the container
make clean      # down + remove image (DB + recipes preserved)
```

## Adding a recipe

1. Write a markdown file under
   `recipes/<category>/<slug>.md`. See
   [recipe-format.md](recipe-format.md) for the frontmatter
   spec and the supported syntax inside ingredient/method sections.
2. `make reindex`. The recipe shows up at
   `http://localhost:8000/recipes/<slug>` and in the category
   listing.

If you set `RECIPE_MACHINE_LLM_FALLBACK=true` in `.env` and provide
an `ANTHROPIC_API_KEY`, `php artisan recipes:reindex --with-llm`
will additionally route any unparsed ingredient lines through
Claude Haiku — see [llm-fallback.md](llm-fallback.md).

## Editing CSS or JS

CSS lives in [resources/css/app.css](../resources/css/app.css).
JavaScript lives under [resources/js/](../resources/js/). The bundler
is [Vite](https://vitejs.dev/), configured to ship into
`public/build/`. Two workflows:

- **One-shot rebuild**: `make assets` (runs `npm run build` on the
  host). The container picks up the new manifest immediately.
- **Live reload**: run `npm run dev` on the host. Vite serves the
  bundle from `localhost:5173` and the Blade `@vite` directive
  reads from there when present.

After a CSS/JS change, if you ALSO rebuild the container image
(`make rebuild`), the new bundle gets baked into the image. The
manifest hash will change — that's fine and expected.

## Running tests

```sh
make test
```

PHPUnit lives in [vendor/bin/phpunit](../vendor/bin/phpunit). The
config in [phpunit.xml](../phpunit.xml) runs every test against an
in-memory SQLite database, so the host's `database/database.sqlite`
is never touched.

The PHP↔JS ingredient-formatter parity test runs Node inside the
container. If you see "Node not available on PATH" the container
needs a rebuild (`make rebuild`).

## Invalidating the LLM cache

```sh
docker compose exec app php artisan recipes:llm-cache-clear --all
docker compose exec app php artisan recipes:llm-cache-clear --misses-only
docker compose exec app php artisan recipes:llm-cache-clear --line='exact raw line'
```

`--misses-only` is the usual one — drops tombstones so they get
re-attempted on the next `--with-llm` reindex without losing the
permanent hits.

## Health-check report

```sh
docker compose exec app php artisan recipes:health-check
```

Prints a corpus-wide summary: per-recipe ingredient parse rate,
method-step count, cross-reference graph, frontmatter completeness,
and LLM fallback usage. The exit code is 0 unless `--fail-on-regress`
was supplied and the corpus regressed against the baseline.

## Debugging

The container runs `php artisan serve` directly — no nginx, no
opcache. Errors render Whoops in `APP_DEBUG=true` mode. To watch
the log stream:

```sh
docker compose logs -f app
```

To poke at the DB:

```sh
docker compose exec app php artisan tinker
```

(Note: tinker uses the file-backed SQLite at
`/var/www/html/database/database.sqlite` — same DB the app uses,
NOT the in-memory test DB.)
