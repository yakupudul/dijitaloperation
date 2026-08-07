# MoxDOP (dijitaloperation)

Moximu agency internal digital operations platform (DOP).

Product source of truth: [`docs/MASTER_SPEC.md`](docs/MASTER_SPEC.md)

Project status: [`docs/PROJECT_STATUS.md`](docs/PROJECT_STATUS.md)

Autopilot Automations (Supervisor + PR Repair): [`.automation/supervisor/`](.automation/supervisor/)

## Bootstrap

See [`docs/implementation/CORE_BOOTSTRAP.md`](docs/implementation/CORE_BOOTSTRAP.md) for local setup, admin creation, queue, tests, and the sample module.

Quick start:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan dop:create-admin
php artisan serve
```

Panel: `/app/login`

## Website Diagnosis (reachability)

Minimal deterministic check for Website assets (`primary_url`): creates a `Run`, stores normalized `http_fetch` Evidence, and upserts Findings for catalog item `reachability-http` (transport failure / 5xx).

```php
use App\Jobs\DiagnoseWebsiteJob;
use App\Models\DigitalAsset;
use App\Services\WebsiteDiagnosisService;

$asset = DigitalAsset::query()->findOrFail($id);

// Sync
app(WebsiteDiagnosisService::class)->diagnose($asset);

// Queued
DiagnoseWebsiteJob::dispatch($asset);
```

Catalog: `docs/website/DIAGNOSIS_CATALOG.md`. Product: `docs/product/website/DIAGNOSIS.md`.
