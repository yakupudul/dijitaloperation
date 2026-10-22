<?php

namespace App\Services\Operations;

use App\Services\Assistant\PushNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Real-time error alert and error grouping without an outside error tracker.
 *
 * Grouping: every unexpected error is counted under a fingerprint (class + file:line + normalized message) in
 * app_error_groups with first/last seen and the release SHA. Occurrences are counted in the cache and written at
 * most once per group per throttle window (after the caller's open database transaction commits), so a burst of the same
 * error costs one row write per window; counts can lag by up to one window.
 *
 * Alert: a new kind of application error (class + place) sends one phone notification, then stays quiet for that
 * kind for 6 hours. Expected errors (validation, 404, auth, expired forms) are ignored. Never throws.
 */
final class ErrorAlertReporter
{
    private const IGNORED = [ValidationException::class, AuthenticationException::class, AuthorizationException::class,
        ModelNotFoundException::class, TokenMismatchException::class];

    public function report(Throwable $exception): void
    {
        try {
            if (! self::isReportable($exception)) {
                return;
            }
            $where = self::where($exception);

            $this->group($exception, $where);

            if (! (bool) config('moxdop-observability.error_alerts', true)) {
                return;
            }
            $release = ReleaseInfo::shortSha();
            app(PushNotifier::class)->send('app-error:'.md5($exception::class.'|'.$where), 'Uygulama hatası',
                class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 180).' @ '.$where.($release !== null ? ' · sürüm '.$release : ''),
                'critical', null, 6);
        } catch (Throwable) {
            // an alert must never break error handling
        }
    }

    /**
     * Where the error happened: the throw site, and when that is inside vendor/ also the app line that called it
     * (audit N9: "getKey() on array" was grouped under Eloquent's Collection.php only, so nobody could find it).
     */
    public static function where(Throwable $exception): string
    {
        $base = base_path().'/';
        $where = str_replace($base, '', $exception->getFile()).':'.$exception->getLine();
        if (! str_starts_with($where, 'vendor/')) {
            return $where;
        }
        foreach ($exception->getTrace() as $frame) {
            $file = str_replace($base, '', (string) ($frame['file'] ?? ''));
            if ($file !== '' && ! str_starts_with($file, 'vendor/') && ! str_starts_with($file, 'storage/framework/')) {
                return $where.' ← '.$file.':'.($frame['line'] ?? 0);
            }
        }

        return $where;
    }

    public static function isReportable(Throwable $exception): bool
    {
        foreach (self::IGNORED as $class) {
            if ($exception instanceof $class) {
                return false;
            }
        }

        return ! ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
    }

    /** Numbers, ids, hashes and quoted values vary per occurrence; the shape of the message does not. */
    public static function normalizeMessage(string $message): string
    {
        $normalized = preg_replace([
            // Audit Y3: a credential in a URL query (Meta debug_token, an API key) never reaches the error center.
            '/([?&](?:access_token|input_token|token|key|api_key|client_secret|secret|password|appsecret_proof)=)[^&\s"\']+/i',
            '/\b[A-Za-z0-9_\-]{40,}\b/',                                       // long opaque values (tokens)
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', // uuids
            '/"[^"]*"|\'[^\']*\'|`[^`]*`/u',                                  // quoted values
            '/\b[0-9a-f]{12,}\b/i',                                            // long hex (hashes, tokens)
            '/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)?/',    // dates and timestamps
            '/\d+(?:[.,]\d+)*/',                                               // numbers, ids, dates, ips
            '/\s+/u',
        ], ['$1#', '#', '#', '?', '#', '#', '#', ' '], $message) ?? $message;

        return mb_substr(trim($normalized), 0, 300);
    }

    public static function fingerprint(Throwable $exception, string $where): string
    {
        return sha1($exception::class.'|'.$where.'|'.self::normalizeMessage($exception->getMessage()));
    }

    private function group(Throwable $exception, string $where): void
    {
        if (! (bool) config('moxdop-observability.error_groups.enabled', true)) {
            return;
        }

        $fingerprint = self::fingerprint($exception, $where);
        $pendingKey = 'error-group:pending:'.$fingerprint;
        Cache::add($pendingKey, 0, 86400);
        Cache::increment($pendingKey);

        // Written after the caller's open transaction commits (at once when there is none): a write inside it
        // would be rolled back with it or, on PostgreSQL, could poison it. On rollback the pending count waits in
        // the cache for the next occurrence.
        DB::afterCommit(fn () => $this->flush($exception, $where, $fingerprint));
    }

    private function flush(Throwable $exception, string $where, string $fingerprint): void
    {
        try {
            $throttle = max(1, (int) config('moxdop-observability.error_groups.throttle_seconds', 60));
            if (! Cache::add('error-group:flush:'.$fingerprint, 1, $throttle)) {
                return;
            }

            $occurrences = max(1, (int) Cache::pull('error-group:pending:'.$fingerprint, 1));
            $now = now();
            $release = ReleaseInfo::current()['sha'];
            $table = 'app_error_groups';

            DB::table($table)->upsert([[
                'fingerprint' => $fingerprint,
                'exception_class' => mb_substr($exception::class, 0, 255),
                'location' => mb_substr($where, 0, 255),
                'message' => mb_substr(self::normalizeMessage($exception->getMessage()), 0, 500),
                'occurrences' => $occurrences,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'first_release' => $release,
                'last_release' => $release,
            ]], ['fingerprint'], [
                'occurrences' => DB::raw(DB::getQueryGrammar()->wrap($table.'.occurrences').' + '.$occurrences),
                'last_seen_at',
                'last_release',
            ]);
        } catch (Throwable) {
            // grouping must never break error handling
        }
    }
}
