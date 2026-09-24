<?php

namespace App\Services\Operations;

use App\Services\Assistant\PushNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Real-time error alert without an outside error tracker: a new kind of application error (class + place) sends
 * one phone notification, then stays quiet for that kind for 6 hours. Expected errors (validation, 404, auth,
 * expired forms) are ignored. Never throws.
 */
final class ErrorAlertReporter
{
    private const IGNORED = [ValidationException::class, AuthenticationException::class, AuthorizationException::class,
        ModelNotFoundException::class, TokenMismatchException::class];

    public function report(Throwable $exception): void
    {
        try {
            if (! (bool) config('moxdop-observability.error_alerts', true)) {
                return;
            }
            foreach (self::IGNORED as $class) {
                if ($exception instanceof $class) {
                    return;
                }
            }
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return;
            }
            $where = str_replace(base_path().'/', '', $exception->getFile()).':'.$exception->getLine();
            app(PushNotifier::class)->send('app-error:'.md5($exception::class.'|'.$where), 'Uygulama hatası',
                class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 180).' @ '.$where, 'critical', null, 6);
        } catch (Throwable) {
            // an alert must never break error handling
        }
    }
}
