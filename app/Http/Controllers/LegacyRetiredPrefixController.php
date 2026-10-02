<?php

namespace App\Http\Controllers;

final class LegacyRetiredPrefixController
{
    public function app(): never
    {
        abort(410, 'Legacy /app operator prefix retired.');
    }

    public function system(): never
    {
        abort(410, 'Legacy /system operator surface retired.');
    }
}
