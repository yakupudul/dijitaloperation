<?php

namespace App\Http\Controllers\Operator;

use Illuminate\Http\RedirectResponse;

final class RetiredAssetTypeRedirectController
{
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse(route('operator.assets'), 302);
    }
}
