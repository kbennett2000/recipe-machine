# Recipe Machine

> Self-hosted recipe library that parses markdown files into a searchable, scalable, shopping-list-generating web app.

## What this is

Recipe Machine is a self-hosted PHP web application that turns a directory of plain markdown recipe files into a searchable, browsable recipe library — with shopping-list generation, scaling, and tagging on the roadmap. Recipes live as files on disk so they stay portable, diff-able, and version-controllable; the app indexes them into a database for fast lookups.

**Status:** under active development. Phase 0 complete.

Known issues and deferred work are tracked in [TODO.md](TODO.md).

## Quick start

Two ways to run it locally — pick one.

### Native (PHP + Composer + Node on your machine)

```bash
git clone https://github.com/kbennett2000/recipe-machine.git
cd recipe-machine
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

Then open <http://localhost:8000>.

### Docker Compose

```bash
git clone https://github.com/kbennett2000/recipe-machine.git
cd recipe-machine
docker compose up --build
```

Then open <http://localhost:8000>. The `recipes/` directory is mounted into the container, and the SQLite database is persisted in a named volume.

## Stack

- **PHP 8.3** + **Laravel 11**
- **SQLite** (file-based, zero-config)
- **Tailwind CSS** + **Alpine.js** (built with Vite)
- **Docker** for self-hosting

## Repository layout

```
recipe-machine/
├── app/                 Laravel application code
├── config/              Laravel configuration
├── database/            Migrations + SQLite file (untracked)
├── docs/                Design and architecture docs
├── public/              Web root + compiled assets
├── recipes/             Markdown recipe files (data, not code)
│   ├── breads/
│   ├── desserts/
│   ├── entrees/
│   ├── sauces/
│   ├── seafood/
│   └── soups/
├── resources/           Blade views, CSS, JS
├── routes/              HTTP routes
├── Dockerfile
├── docker-compose.yml
└── README.md
```

The `recipes/` directory sits outside the Laravel app tree on purpose — recipes are *data*, not code.

## Roadmap

- [x] **Phase 0** — Project skeleton (Laravel + Tailwind + Alpine + SQLite + Docker)
- [ ] **Phase 1** — Markdown recipe parsing and ingestion
- [ ] **Phase 2** — Recipe browsing and detail pages
- [ ] **Phase 3** — Full-text search
- [ ] **Phase 4** — Tags, categories, and filtering
- [ ] **Phase 5** — Recipe scaling (servings)
- [ ] **Phase 6** — Shopping list generation
- [ ] **Phase 7** — Meal planning
- [ ] **Phase 8** — User accounts and favorites
- [ ] **Phase 9** — Import/export and backups
- [ ] **Phase 10** — Mobile-friendly polish and PWA
- [ ] **Phase 11** — Production hardening, security, deploy guide

## License

[MIT](LICENSE).
