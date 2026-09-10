# AGENTS.md — Working with `route-forge/laravel`

Guidance for AI coding agents (Cursor, GitHub Copilot, Claude Code, Codex, …) integrating **Route Forge** into a Laravel backend, and for editing this package.

## What this package is

`route-forge/laravel` (namespace `RouteForge\Laravel\`) is the **backend** of the route-forge project. It exposes Laravel named routes to a SPA via per-tier HTTP metadata endpoints and generates TypeScript types. Companion frontend SDKs (`@route-forge/core`, `@route-forge/vue`, `@route-forge/react`) live in a separate repository.

- Language / runtime: PHP `^8.2`
- Framework: illuminate `^11 || ^12 || ^13`
- Package manager: **Composer** (this repo); the frontend monorepo uses pnpm — do not mix.

## Integrating it into a Laravel app

1. `composer require route-forge/laravel` — the `ForgeServiceProvider` is auto-registered.
2. Optionally `php artisan vendor:publish --tag=forge-config` to get `config/forge.php`. Defaults work with zero config.
3. Assign tiers to your named routes (see below). Anything that matches nothing lands in the special `unassigned` tier (or throws in strict mode).
4. Point the frontend at `GET /_forge/routes` (summary) and `GET /_forge/routes/{level}` (per tier).

### Correct ways to tag tiers

```php
// Explicit (also works on resource routes)
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login')->tier('public');

// Group (array syntax)
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin'], 'tier' => 'admin'], function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
});

// Group (fluent) — tier is just another chainable attribute
Route::middleware(['auth', 'admin'])->tier('admin')->prefix('admin')->group(function () { /* ... */ });

// Batch rules in config/forge.php
// 'admin' => ['match' => ['prefix' => ['admin'], 'middleware' => ['auth', 'admin']], 'load' => 'lazy'],
```

Priority: explicit `->tier()` > group pass-through > `classifier` callback > config match > `unassigned`.

### Route aliases (renaming routes without breaking the frontend)

When a named route is renamed, declare the old name as an alias so SPA callers keep working unchanged:

```php
// Fluent (explicit, wins over config) — declare where you rename
Route::get('/admin/members', [MemberController::class, 'index'])
    ->name('admin.members.index')->tier('admin')->forgeAlias('admin.users.index');
```

```php
// Batch in config/forge.php: 'aliases' => ['admin.users.index' => 'admin.members.index']
```

Aliases are injected as extra keys into the target route's level metadata (pure copy, no marker fields), counted in summary `route_count`, and typed by `route:forge:types` — the frontend needs zero changes. A dangling alias (target route name missing) throws `AliasTargetException` (RF_BE_008); a colliding alias yields to the real route with a warning. Audit with `route:forge:list --aliases`; clean aliases up once the rename has settled. See [`.docs/SPEC.md` §3.1.7](./.docs/SPEC.md).

### The one rule agents get wrong

> Group attributes must be declared **before** `group()`. `Route::group([...], fn)->tier('admin')` silently does NOT apply, because `group()` registers children and pops the group stack before returning. Use the array form or `Route::tier('admin')->group(fn)`.

## Endpoints (do not hardcode; discover)

- `GET /_forge/routes` → summary: tier list, global config, `schemeVersion`, unassigned info.
- `GET /_forge/routes/{level}` → named-route metadata for that tier: `name`, `uri`, `method`, `params`.
- The special level `unassigned` holds routes that matched no tier.
- **Optional first-page embedding**: for Blade/SSR-rendered homepages, the `@forgeSummary` directive inlines the *summary* payload into `<head>` as a one-time self-deleting `window.__ROUTE_FORGE__` accessor so `@route-forge/core` skips the first summary round-trip. It embeds only the summary (per-tier tables still lazy-load over HTTP), reuses the same producer/cache, adds no endpoint, does not bump `schemeVersion`, and is a no-op for pure SPA / Vite dev (which fall back to the network summary). See [`.docs/SPEC.md` §3.1.8](./.docs/SPEC.md).

## Generating frontend types

```bash
php artisan route:forge:types --out resources/js/route-forge.d.ts
```

Run it in the PHP/CI build stage — it reads the in-memory route registry, so it needs no running HTTP server.

## Useful commands

- `php artisan route:forge:list [--level=...] [--json] [--unassigned]`
- `php artisan route:forge:clear [--level=...]` (also auto-cleared by `route:clear`)

## Config keys (`config/forge.php`)

`levels`, `endpoint_prefix`, `url_prefix`, `endpoint_middleware`, `cache_ttl`, `cache_driver`, `strict_mode`, `scheme_version`, `classifier`, `aliases`. See [`.docs/SPEC.md` §5](./.docs/SPEC.md) for the full reference including `levels.{name}.*`.

## Behavior constraints to preserve

- **Dev (`APP_DEBUG=true`)**: cache reads/writes are bypassed automatically; route edits apply instantly.
- **Manager page** (`GET /_forge/manager`): only registered when `APP_DEBUG=true`; also gated by the `manager_allowed_ips` allowlist (default `127.0.0.1` / `::1`). Never expose it in production.
- **Strict mode**: `strict_mode=true` throws `RouteTierNotAssignedException` on an unmatched level; `false` routes them to `unassigned`.
- **Frontend validation always throws** — no silent ignore. `strict_mode` is a backend concept (where an unmatched route goes); the deprecated frontend `strict` flag is unrelated — do not reintroduce it.
- **Exclude the package's own routes** (`forge.routes.*`, `forge.manager.*`) from every metadata scan. If they leak into a scan, `strict_mode` will 500.
- **Normalize config values at the read site**: any `config('forge.*')` / `levels.{name}.*` value that is array-valued by contract must be coerced with `(array)` *before* `count()`/`foreach` — a single string is a supported spelling (it mirrors Laravel's own `->middleware('auth')`), and `null`/missing means empty. Both type-safety bugs found in this project were the same pattern: `(array)` on the `foreach` but the raw value on the `count()`. One of them sat in `ForgeServiceProvider::registerMetadataEndpoint()`, which runs during `boot()` and therefore 500s the **entire host app**, not just the forge endpoints. Never "fix" this with a silent `is_array()` guard either: that variant dropped a non-array `endpoint_middleware` without a word, leaving the metadata endpoint (all tiers + runtime config) looking protected while carrying no middleware at all — fall back and warn instead.

## Editing this package (repo conventions)

- Structure: `src/` (Http controllers/middleware, Console commands, `Adapter/` (LaravelRouteNormalizer + LaravelCacheAdapter bridging the common contracts), `Blade/ForgeSummaryRenderer` for the `@forgeSummary` directive, registrars, `ForgeRouter`, `ForgeServiceProvider`), `config/forge.php`, `resources/` (manager views), `tests/` (PHPUnit + orchestra/testbench), `.docs/` (SPEC + DESIGN as the single source of truth for the front/back contract), `_ide_helper.php` (manually-maintained macro stub — keep in sync when adding `Route` macros).
- **Framework-agnostic core lives in [`route-forge/common`](https://github.com/route-forge/php-common)** (tier resolution, aliases, route repository, cache, TS type generation, exceptions, summary renderer, command-layer analyzer/typegen). Do **not** re-add framework-independent logic to this repo; framework bindings belong in `src/`, pure business logic belongs in `common`.
- **Command-layer wiring**: `route:forge:list` / `route:forge:types` inject the container-singleton `RouteAnalyzer` (wired in `ForgeServiceProvider` with TierResolver + config aliases + the internal-prefix filter). The `storage.*` prefix is declared **once** in `ForgeServiceProvider::LARAVEL_INTERNAL_ROUTE_PREFIXES`, and `RouteNameFilter` is bound as a container singleton shared by both the analyzer and `RouteRepository` — never construct a `RouteNameFilter`/`AliasResolver` inside a command or repeat the prefix list; duplicated prefixes drift and leak framework-internal routes into scans. Command output contracts (`list --json` shape, types input mapping) come from common (`RouteAnalyzer::listPayload` / `TypeGenerator::collectTargets`); commands keep only arg parsing, I/O, and table coloring.
- **Cache invariant**: invalidating one level must invalidate the summary — always go through `RouteCache::forgetLevel($level)` (common), never bare `forget($level)` plus a magic `'summary'` string.
- **Local dev wiring**: the committed `composer.json` must stay publishable — plain `composer install` resolves `route-forge/common` from Packagist. To develop against an *unpublished* core, temporarily add a path repository and **strip it before committing**; the `repositories` block must never reach git:

  ```bash
  composer config repositories.common '{"type":"path","url":"../php-common","options":{"symlink":true,"versions":{"route-forge/common":"<version your core checkout will be released as>"}}}'
  composer update route-forge/common
  # ... run the suite, then:
  composer config repositories.common --unset
  ```

  The `versions` override is required: without it a path repository resolves as a branch/dev version and fails the package's `^<minor>` constraint on `route-forge/common`. CI deliberately installs from Packagist, so the pipeline tests exactly what users get — a green CI does **not** prove an unpublished core works; bump the core, tag it, then raise this package's constraint.
  **Deprecated — do not resurrect it:** the old `composer.local.json` + `COMPOSER=...` flow. The `COMPOSER` env var *replaces* `composer.json` rather than merging it, so that file had to be a full copy of the manifest, and writing only `{"repositories": [...]}` silently produced an empty vendor with "Nothing to install".
- Tests: `composer test` (or `vendor/bin/phpunit`). Full suite must pass before any commit. New features/fixes ship with matching tests.
- Commit messages: `type(scope): 中文描述` (type ∈ feat/fix/test/docs/refactor/chore). Do **not** push without explicit instruction.
- Design decisions and the front/back contract are documented in [`.docs/DESIGN.md`](./.docs/DESIGN.md) and [`.docs/SPEC.md`](./.docs/SPEC.md) — consult them before proposing changes, and keep them in sync when behavior changes.
