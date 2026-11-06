<?php

namespace App\Http\Controllers\Operator\Links;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Site\Backlinks\LinkReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/** The month's link report of one brand, a plain page to print as PDF or share with the client. */
final class LinkReportController extends Controller
{
    public function __invoke(int $brand, ?string $month = null): View
    {
        $model = Brand::query()->findOrFail($brand);
        $at = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $month, 'Europe/Istanbul')
            : CarbonImmutable::now('Europe/Istanbul');

        return view('operator.links.report', LinkReport::build($model, $at->startOfMonth()));
    }
}
