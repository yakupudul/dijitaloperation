# Core Application Bootstrap

> Implementation notes for the first runnable MoxDOP core.  
> Product source of truth remains `docs/MASTER_SPEC.md`.

## Stack

| Piece | Choice |
|-------|--------|
| PHP | 8.3+ |
| Framework | Laravel 13 |
| Admin UI | Filament 5 panel `app` at `/app` |
| Auth roles | `spatie/laravel-permission` (`Admin`, `Team Member`) |
| Modules | `internachi/modular` under `app-modules/` (namespace `MoxDop`) |
| Queue | Database driver (no Redis/Horizon in bootstrap) |
| Scheduler | Laravel default scheduler |
| AI assist | `laravel/boost` (dev) |

## Local dependencies

- PHP 8.3+ with common Laravel extensions (`pdo_mysql` / `pdo_sqlite`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`)
- Composer 2
- Node.js + npm (Vite assets; Filament ships published assets under `public/`)
- Git
- MySQL 8 for production-like local use (optional; SQLite works for smoke tests)

## First-time setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure database in `.env`:

- Preferred: MySQL 8 (`DB_CONNECTION=mysql`, database/user/password of your choosing)
- Quick local: `DB_CONNECTION=sqlite` and ensure `database/database.sqlite` exists

```bash
php artisan migrate
php artisan db:seed
php artisan dop:create-admin
php artisan serve
```

Open `http://127.0.0.1:8000/app/login`.

Optional frontend tooling:

```bash
npm install
npm run build
# or: npm run dev
```

## Queue

`.env` / `.env.example` use `QUEUE_CONNECTION=database`. Jobs table ships with Laravel migrations.

```bash
php artisan queue:work
```

Cursor Cloud: a queue worker is **not** required for basic `/app` boot. Start one only when exercising async jobs.

## Scheduler

Use Laravel’s standard scheduler (cron pointing at `php artisan schedule:run`). No custom scheduler framework in bootstrap.

Cursor Cloud: scheduler is **not** required for basic interactive UAT.

## First admin

Never commit real credentials. Create interactively:

```bash
php artisan dop:create-admin
```

Prompts for name, email, and password (password input is hidden). Assigns the `Admin` role.

## Tests

PHPUnit uses SQLite in-memory (`phpunit.xml`).

```bash
php artisan test
# or: composer test
```

## Sample module

Path: `app-modules/sample-module`  
Composer: `moxdop/sample-module`  
Provider: `MoxDop\SampleModule\Providers\SampleModuleServiceProvider`

Purpose: prove modular loading only. No product UI or business logic.

```bash
php artisan modules:list
```

## Module enable/disable (future registry)

`internachi/modular` loads Composer packages. DOP enable/disable will be a Core registry concern later:

- disabled modules stay installed in Composer
- UI / scheduled / analysis work should not run when disabled
- data is not purged

Bootstrap does **not** implement the full Module Registry yet.

## Quality commands

```bash
composer validate --strict
php artisan test
vendor/bin/pint --test
php artisan modules:list
php artisan route:list --path=app
php artisan about
```
