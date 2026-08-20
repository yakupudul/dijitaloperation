<?php

namespace App\Http\Controllers\Operator;

use App\Support\Work\WorkUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LegacyWorkRedirectController
{
    public function __invoke(Request $request, string $workId): RedirectResponse
    {
        $type = $request->query('type');
        if (! is_string($type) || ! WorkUrl::isType($type)) {
            abort(404);
        }

        return new RedirectResponse(route('operator.work.show', WorkUrl::parameters($type, $workId)), 302);
    }
}
