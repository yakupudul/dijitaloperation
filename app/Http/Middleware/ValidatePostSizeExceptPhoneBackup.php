<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;

/**
 * The phone's WhatsApp backup (FolderSync, WebDAV PUT /api/telefon-yedek/) can be hundreds of MB. PHP does not parse a
 * PUT body into $_POST, and the controller streams php://input to disk, so post_max_size does not apply there; the
 * framework's post size check would still refuse it with 413 "The POST data is too large.". Every other request keeps it.
 */
class ValidatePostSizeExceptPhoneBackup extends ValidatePostSize
{
    public function handle($request, Closure $next)
    {
        if ($request instanceof Request && $request->is('api/telefon-yedek', 'api/telefon-yedek/*') && ! $request->isMethod('POST')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
