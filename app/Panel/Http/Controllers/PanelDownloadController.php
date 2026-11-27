<?php

namespace App\Panel\Http\Controllers;

use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelFile;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelTicketMessage;
use App\Panel\Services\PanelLeads;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelSources;
use App\Panel\Support\PanelStages;
use App\Panel\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Files leaving the panel: a person's attachment, and the Excel list (the brand's manager only, always logged). */
final class PanelDownloadController
{
    public function file(string $file, PanelLeads $leads): StreamedResponse
    {
        abort_unless(strlen($file) <= 18, 404);
        $row = PanelFile::query()->findOrFail((int) $file);
        abort_unless($leads->visible()->whereKey($row->person_id)->exists(), 404);

        return Storage::disk((string) config('panel.files_disk'))->download($row->path, $row->name);
    }

    public function ticketFile(string $message): StreamedResponse
    {
        abort_unless(strlen($message) <= 18, 404);
        $row = PanelTicketMessage::query()->whereNotNull('attachment_path')->findOrFail((int) $message);

        return Storage::disk((string) config('panel.files_disk'))->download($row->attachment_path, $row->attachment_name);
    }

    public function export(Request $request, PanelContext $context): BinaryFileResponse
    {
        abort_unless($context->isManager(), 403);
        $query = PanelPerson::query()->with('assignee:id,name')->orderByDesc('created_at');
        if (array_key_exists((string) $request->query('asama'), PanelStages::labels())) {
            $query->where('stage', (string) $request->query('asama'));
        }
        $rows = (function () use ($query) {
            foreach ($query->lazyById(500, 'id') as $p) {
                yield [
                    $p->name, (string) $p->phone, (string) $p->email, PanelStages::label($p->stage), $p->lost_reason ? (PanelStages::lostReasons()[$p->lost_reason] ?? '') : '',
                    PanelSources::label($p->source), (string) $p->interest, (string) $p->city, (string) $p->district,
                    ['female' => 'Kadın', 'male' => 'Erkek'][$p->gender] ?? '', $p->age() ?? '', (string) $p->assignee?->name,
                    (string) $p->appointment_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'), (string) $p->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                    (string) $p->last_activity_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                ];
            }
        })();
        $path = tempnam(sys_get_temp_dir(), 'panel-xlsx-');
        Xlsx::write($path, ['Ad', 'Telefon', 'E-posta', 'Aşama', 'Olumsuz nedeni', 'Kaynak', 'İlgilendiği', 'Şehir', 'İlçe', 'Cinsiyet', 'Yaş', 'İlgilenen', 'Randevu', 'Kayıt', 'Son hareket'], $rows, 'Adaylar');
        PanelAudit::record('export.leads', $context->brandId(), 'panel', (int) Auth::guard('client')->id(), ['stage' => $request->query('asama')]);

        return response()->download($path, 'adaylar-'.now()->timezone('Europe/Istanbul')->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    /** An empty list with the right column titles, so the first upload matches without any mapping. */
    public function sample(Request $request): BinaryFileResponse
    {
        $calls = $request->query('tur') === 'calls';
        $path = tempnam(sys_get_temp_dir(), 'panel-xlsx-');
        Xlsx::write($path, $calls ? ['Telefon', 'Ad soyad', 'Tarih', 'Not'] : ['Ad soyad', 'Telefon', 'E-posta', 'İlgilendiği konu', 'Kaynak', 'Şehir', 'İlçe', 'Not', 'Tarih'],
            [$calls ? ['0532 000 00 00', 'Örnek Kişi', now()->timezone('Europe/Istanbul')->format('d.m.Y H:i'), 'Fiyat sordu'] : ['Örnek Kişi', '0532 000 00 00', '', 'Bilgi', 'Tavsiye', 'Manisa', 'Yunusemre', 'Eski listemizden', now()->timezone('Europe/Istanbul')->format('d.m.Y')]], $calls ? 'Çağrılar' : 'Adaylar');

        return response()->download($path, ($calls ? 'cagri' : 'aday').'-listesi-ornek.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
