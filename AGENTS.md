# AGENTS.md

## Cursor Cloud specific instructions

Ürün gerçeği: `docs/MASTER_SPEC.md` (+ accepted ADR’ler, `PROJECT_MEMORY.md`, `docs/product/*`, `PRODUCT_CAPABILITY_LEDGER.md`, roadmap, foundation, module-sdk).

**Before any development task that changes behavior**, read:

1. `docs/MASTER_SPEC.md`
2. Relevant accepted ADRs (`docs/foundation/DECISION_LOG.md`)
3. `PROJECT_MEMORY.md`
4. `PRODUCT_CAPABILITY_LEDGER.md`
5. Relevant module / feature docs under `docs/product/*` (and module docs as applicable)

**Source priority (canonical):**

1. `docs/MASTER_SPEC.md`
2. Latest accepted ADRs
3. `PROJECT_MEMORY.md`
4. Relevant product / module blueprints
5. `PRODUCT_CAPABILITY_LEDGER.md` — implementation truth (coded / tested / UAT / UX / async)
6. `docs/IMPLEMENTATION_ROADMAP.md`
7. `docs/PROJECT_STATUS.md`
8. `AGENTS.md` / supporting references

Laravel Boost guidelines ile çelişirse DOP belgeleri kazanır.

**Completeness rule:** Before claiming a capability is complete / DONE, reconcile **code**, **tests**, **real UAT**, **operator UX**, **async requirement**, **known blockers**, and the **Capability Ledger**. Do not treat “IMPLEMENTED V1” as DONE.

**Same-PR memory updates:**

- When behavior or capability state changes → update `PRODUCT_CAPABILITY_LEDGER.md` in the **same PR**
- When material product / architecture decisions change → update `PROJECT_MEMORY.md` in the **same PR**
- Long-running operator work must follow `OPERATOR_ASYNC_EXECUTION.md`

### Cloud development environment

- Checkout path: **`/workspace`** (expected Cursor Cloud root)
- Reproducible config: `.cursor/environment.json` + `.cursor/Dockerfile`
- Details: `docs/implementation/CURSOR_CLOUD_ENVIRONMENT.md`
- Install: `bash .cursor/cloud-agent-install.sh` (`composer install`, `npm ci`, `npm run build`)
- Start: `bash .cursor/cloud-agent-start.sh` (runtime `.env`, SQLite, migrate, seed)
- App server terminal: `php artisan serve --host=0.0.0.0 --port=8000` (forward port 8000)
- Login: `/login` (operator product); Filament technical/admin: `/admin/login`
- Admin user: `php artisan dop:create-admin` (interactive; never commit passwords)
- Provider credentials are **not** required for basic boot / PHPUnit / **Demo Mode** product review
- Full interactive product demo (Atlas Dental): branch `feature/moxdop-full-product-demo` — session fixtures only; `docs/product/MOXDOP_MASTER_PRODUCT_BLUEPRINT.md`

**DB isolation (mandatory):**

- PHPUnit uses `DB_DATABASE=:memory:` (`phpunit.xml`) — never the operator SQLite file
- Browser / agent UAT must **not** insert synthetic provider fixtures (`act_1001`, “Lead Camp A”, etc.) into `database/database.sqlite`
- Disposable browser UAT: use a separate SQLite file (e.g. `database/browser-uat.sqlite`) via `DB_DATABASE` override, or seed only inside PHPUnit `RefreshDatabase`
- Operator real Meta UAT: bind a **real** discovered Ad Account explicitly; never auto-pick first/random

### Kilit kararlar

- Moximu **iç** operasyon; SaaS / Workspace / müşteri girişi yok
- Harici **write action yok**
- Tek Filament panel: id `app`, path **`/admin`** (developer / technical tooling only — **not** the operator product; ADR-044)
- Canonical operator product: **root routes** (`/`, `/login`, `/customers`, `/brands`, `/assets`, `/integrations`, `/tasks`, … — TailAdmin Livewire). One normal application. Do not duplicate Customers/Brands/Assets under Filament.
- Legacy **`/app/*`** and **`/system/*`**: retired (HTTP 410). No parallel operator product.
- Do **not** advertise Filament as “Back-office” in the product UI. Operators should not need Filament for daily work.
- **Screenshots:** Do **not** automatically generate screenshot packages, visual UAT artifact loops, or screenshot self-reviews for product UI unless the operator **explicitly** asks. Browser smoke to confirm routes render is OK; visual acceptance is human manual review.
- Modüller: `app-modules/` + `internachi/modular` — MVP’de custom plugin framework yok (minimal registry: id + enabled/disabled)
- Finding kalıcı + fingerprint; Evidence Run’a bağlı; **ayrı Result entity yok**
- AI: `laravel/ai`; key environment’ta
- Event: `{kebab-module}.{kebab-action}`
- Prensip: framework’ün çözdüğünü tekrar yazma (ADR-033)
- Website Diagnosis katalog: diagnosis fazı öncesi `docs/website/DIAGNOSIS_CATALOG.md` (Core blocker değil)
- Canonical project memory: `PROJECT_MEMORY.md` + `PRODUCT_CAPABILITY_LEDGER.md` + `OPERATOR_ASYNC_EXECUTION.md`
- Product blueprints: `docs/product/*` — Architect/Reviewer/Implementer okur; blueprint’te olmayan ürün davranışı uydurulmaz
- Unmerged PR functionality (ör. Meta Ads Intelligence on PR #119) is **not** canonical main until merged
- Public Website Discovery is **limited** public website/context discovery — not “all digital web discovery”
- Test standardı: **PHPUnit** (ADR-038); Pest eklenmez
- Product feature task’larında `product_spec_paths` dolu olmalı

### DOP Autopilot exception (overrides Boost “ask user” for CI agents)

GitHub **DOP Autopilot** altında çalışan Implementer/Fixer:

- routine safe local actions için kullanıcıya soru sormaz
- task için gerekli testleri çalıştırır
- final gate’te full required suite otomatik çalışır
- dependency değişikliği yalnızca Architect task açıkça gerektiriyorsa yapılabilir
- MASTER_SPEC / product blueprint scope’u değiştirilemez
- Product PR merge yalnızca Actions içi verified Reviewer `APPROVED` + final gates ile yapılır; local Cursor maintenance agent Reviewer yokken product PR merge etmez

### Pratik

- SaaS, Client Portal, harici write, marketplace/ZIP, custom migrator/FSM ekleme.
- MVP Core listesi dışında Attachments/Tags/feature-flags/ağır health-audit zorunlu sayma.
===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
