<?php

use App\Http\Middleware\DropInvalidLivewireUpdates;
use App\Http\Middleware\SetOperatorLocale;
use App\Http\Middleware\SetOperatorTimezone;
use App\Services\Operations\ErrorAlertReporter;
use App\Support\Operator\LivewireActionErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // Listeners are registered explicitly (AppServiceProvider); discovery would register each one a second time.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Canonical operator login is /login. Filament technical admin is /admin.
        $middleware->redirectGuestsTo(fn (): string => route('app.login'));

        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*'
                    ? '*'
                    : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            );
        }

        $middleware->web(append: [
            DropInvalidLivewireUpdates::class,
            SetOperatorLocale::class,
            SetOperatorTimezone::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The WhatsApp connect page talks to its routes with fetch(): errors (session, expiry, limits) must come back
        // as JSON with a message the page can show, not as an HTML page or a redirect to the login form.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->is('whatsapp/connect/*', 'whatsapp/backup*') && $request->expectsJson()),
        );
        // PostgreSQL: a read by a non-numeric / out-of-range id is a bad link (404), not a server error.
        $exceptions->map(QueryException::class, fn (QueryException $exception): Throwable => LivewireActionErrors::isBadIdentifier($exception)
            ? new NotFoundHttpException('Kayıt bulunamadı.', $exception) : $exception);
        // Phone notification for new kinds of application errors (Ayarlar › Telefon bildirimleri).
        $exceptions->report(function (Throwable $exception): void {
            app(ErrorAlertReporter::class)->report($exception);
        });
    })->create();
