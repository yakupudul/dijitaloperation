# MoxDOP (dijitaloperation)

Moximu agency internal digital operations platform (DOP).

Product source of truth: [`docs/MASTER_SPEC.md`](docs/MASTER_SPEC.md)

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
