<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\FinanceDocument;
use App\Services\Finance\FinanceDocuments;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finans: a kept document opened in the browser, and a month's documents as one ZIP (the files in folders by kind
 * with a list beside them) for the muhasebeci. Admin only; the files never leave the private disk any other way.
 */
final class FinanceFilesController extends Controller
{
    public function show(FinanceDocument $document, FinanceDocuments $documents): Response
    {
        $this->admin();
        $bytes = $documents->bytes($document);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addcslashes($document->archiveName(), '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function zip(Request $request, FinanceDocuments $documents): BinaryFileResponse
    {
        $this->admin();
        $month = (string) $request->query('ay', '');
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 404);
        $from = CarbonImmutable::parse($month.'-01');
        $direction = (string) $request->query('tur', '');
        $docs = FinanceDocument::query()->with('agreement.brand:id,name')
            ->whereDate('doc_date', '>=', $from->toDateString())->whereDate('doc_date', '<=', $from->endOfMonth()->toDateString())
            ->where('status', '!=', 'ignored')
            ->when(isset(FinanceDocument::DIRECTIONS[$direction]), fn ($q) => $q->where('direction', $direction))
            ->orderBy('doc_date')->orderBy('id')->get();
        $label = 'finans-'.$month.(isset(FinanceDocument::DIRECTIONS[$direction]) ? '-'.['out' => 'faturalar', 'in' => 'giderler', 'receipt' => 'dekontlar'][$direction] : '');

        return response()->download($documents->zip($docs, $label), $label.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function admin(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
