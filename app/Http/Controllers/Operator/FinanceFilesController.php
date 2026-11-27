<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FinanceDocument;
use App\Services\Finance\CompanyProfile;
use App\Services\Finance\FinanceDocuments;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    /** The signed link of {@see FinanceDocuments::unread()}: a paper still waiting to be read, nothing else. */
    public function paper(FinanceDocument $document, FinanceDocuments $documents): Response
    {
        abort_unless($document->source === 'gmail' && data_get($document->meta, 'reason') === FinanceDocuments::UNVERIFIED, 404);
        $bytes = $documents->bytes($document);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $document->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="belge-'.$document->id.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** A vergi levhası: ours ("sirket") or a customer's (its id). Admin only, from the private disk. */
    public function certificate(string $owner, CompanyProfile $company): Response
    {
        $this->admin();
        $path = $owner === 'sirket'
            ? (string) ($company->all()['certificate']['path'] ?? '')
            : (string) (Customer::query()->whereKey((int) $owner)->value('tax_certificate_path') ?? '');
        abort_if($path === '' || ! Storage::disk(FinanceDocuments::DISK)->exists($path), 404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';

        return response(Storage::disk(FinanceDocuments::DISK)->get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="vergi-levhasi.'.$ext.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function zip(Request $request, FinanceDocuments $documents): BinaryFileResponse
    {
        $this->admin();
        // A month (ay=Y-m), a stretch of days (bas / bit, either may be missing) or nothing at all = every paper.
        $month = (string) $request->query('ay', '');
        $day = fn (string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key, '')) === 1 ? (string) $request->query($key) : null;
        [$from, $to] = [$day('bas'), $day('bit')];
        if ($month !== '') {
            abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 404);
            $first = CarbonImmutable::parse($month.'-01');
            [$from, $to] = [$first->toDateString(), $first->endOfMonth()->toDateString()];
        }
        $direction = (string) $request->query('tur', '');
        $docs = FinanceDocument::query()->with('agreement.brand:id,name')
            ->when($from !== null, fn ($q) => $q->whereDate('doc_date', '>=', $from))->when($to !== null, fn ($q) => $q->whereDate('doc_date', '<=', $to))
            ->where('status', '!=', 'ignored')
            ->when(isset(FinanceDocument::DIRECTIONS[$direction]), fn ($q) => $q->where('direction', $direction))
            ->orderBy('doc_date')->orderBy('id')->get();
        $span = $month !== '' ? $month : ($from === null && $to === null ? 'tum-zamanlar' : ($from ?? 'bastan').'_'.($to ?? 'bugune'));
        $label = 'finans-'.$span.(isset(FinanceDocument::DIRECTIONS[$direction]) ? '-'.['out' => 'faturalar', 'in' => 'giderler', 'receipt' => 'dekontlar'][$direction] : '');

        return response()->download($documents->zip($docs, $label), $label.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function admin(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
