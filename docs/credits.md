# Credits

Third-party content and tools used by Recipe Machine.

## Fonts

Both fonts are self-hosted under [public/fonts/](../public/fonts/)
and licensed under the [SIL Open Font License v1.1](https://openfontlicense.org/).

- **[Fraunces](https://github.com/undercasetype/Fraunces)** by
  Undercase Type — the display serif used for headings and step
  text in cooking mode. Variable font with weight, optical-size,
  soft, and wonk axes; we use the weight + opsz axes.
- **[Inter](https://github.com/rsms/inter)** by Rasmus Andersson —
  the sans-serif used for body copy, UI chrome, and ingredient
  lists. Variable font covering 100–900 weight in both upright
  and italic.

## How this was built

Recipe Machine was built collaboratively. Anthropic's Claude served
two roles in the loop:

- **Engineer:** [Claude Code](https://www.anthropic.com/claude-code)
  (Opus 4.7) did the implementation work — reading the codebase,
  writing services, blade templates, migrations, JS, tests, and
  commits. Every code change was made by Claude Code with the
  human user in the loop for direction and review.
- **Product owner:** A separate Claude conversation drafted phase
  briefs, reviewed delivered work, surfaced gaps for follow-up
  phases, and wrote the README + docs you're reading now. The
  PO conversation was as opinionated as a real human PM would
  need to be: pointing at TODO items that mattered, pushing
  back on architecture choices, asking for screenshots and
  test counts as proof.

The PO/engineer split is unusual enough to be worth noting honestly
rather than pretending the project sprang fully formed from one
agent. Both ends benefited from the friction: the engineer wrote
real code; the PO kept the scope from drifting.

The user (the human who owns this repo and runs the home-LAN
deployment) directed both ends — set the goals, chose the
trade-offs, decided when each phase was done.

## Libraries

Standard parts of the stack, all of which have their own credits and
licenses inside their own repos:

- [Laravel 11](https://laravel.com/) (framework)
- [Alpine.js](https://alpinejs.dev/) (lightweight client-side reactivity)
- [Tailwind CSS](https://tailwindcss.com/) (utility CSS)
- [Vite](https://vitejs.dev/) (asset bundler)
- [Symfony YAML](https://symfony.com/components/Yaml) (frontmatter parsing)
- [CommonMark](https://commonmark.thephpleague.com/) (via `Str::markdown`)
- [PHPUnit](https://phpunit.de/) (test runner)
- [SQLite](https://sqlite.org/) (cache + FTS5 search)
- [Docker](https://www.docker.com/) + Docker Compose (self-hosting)

## API

The optional LLM ingredient-parser fallback (Phase 9, opt-in)
calls Anthropic's Messages API via the
[Claude Haiku](https://www.anthropic.com/claude) model. See
[llm-fallback.md](llm-fallback.md) for the architecture and
[../config/recipe-machine.php](../config/recipe-machine.php)
for the configuration knobs.
