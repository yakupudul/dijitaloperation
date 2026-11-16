<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Yapılacaklar (yakup 2026-11-13: "Bundan sonra her yapacağım işi sistemde yapılacaklara yaz oradan görebileyim"): every
 * job only the operator can do (an access to give, a file to upload, a decision, a date to tell) lands in Takvim ›
 * Yapılacaklar, the same list the panel, Moxi and the browser extension use. An open item with the same title is not
 * written twice, so several Claude sessions can add the same ask safely.
 */
#[Description('Yapılacaklar: the operator\'s own to-do list (Takvim › Yapılacaklar). action=add: a job only the operator can do with his own hands (give an access, upload a prepared file, tell a fact, a date or an amount, press a connect button, talk to a client): text = one plain Turkish sentence starting with the verb ("Serap Korkmaz\'ı panele marka olarak ekle"), the second line and on = details; "yarın 10" / "15 aralık" in the text sets a date, without one it stays undated until ticked; brand_id ties it to a brand; url = where to do it. action=ask: YOUR OWN work that needs his yes before you do it (delete pages, write to a Google profile, send something, change a package, pick between plans): text first line = the question as he would answer it ("Avrupadent\'ten ayrılan 3 hekimin İngilizce sayfalarını kaldırayım mı?"), the rest = exactly what will be done, where, ids and how it can be undone, written so another Claude can do it from that text alone. He sees Onayla / Reddet; Onayla comes back to Claude as a Moxi işi (moxi-requests), Reddet closes it. Never put your own work in add and never ask him to "say yap" in an add. An open item with the same title is not added twice. action=list shows asks, open, late and today\'s items plus asks answered in the last 14 days; action=done (id) ticks one you saw finished or closes an ask that is no longer needed.')]
class Todos extends Tool
{
    public function handle(Request $request, QuickCapture $capture): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['add', 'ask', 'list', 'done'])],
            'text' => ['required_if:action,add', 'required_if:action,ask', 'nullable', 'string', 'min:5', 'max:4000'],
            'brand_id' => ['nullable', 'integer'],
            'url' => ['nullable', 'string', 'max:500'],
            'id' => ['required_if:action,done', 'nullable', 'integer'],
        ]);
        $user = self::operator();
        if ($user === null) {
            return Response::error('Etkin yönetici kullanıcı yok.');
        }

        if ($validated['action'] === 'list') {
            $board = $capture->board((int) $user->id, 0);

            $answered = Reminder::query()->where('user_id', $user->id)->whereIn('approval', [Reminder::APPROVED, Reminder::REJECTED])
                ->where('done_at', '>=', now()->subDays(14))->orderByDesc('done_at')->limit(30)->get()
                ->map(fn (Reminder $r): array => ['id' => $r->id, 'title' => $r->title, 'answer' => $r->approval === Reminder::APPROVED ? 'onaylandı' : 'reddedildi',
                    'at' => $r->done_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i')])->all();

            return Response::json(['url' => route('operator.calendar'), 'count' => $board['count'], 'asks' => $board['asks'], 'answered' => $answered,
                'today' => $board['today'], 'late' => $board['late'], 'open' => $board['open']]);
        }

        if ($validated['action'] === 'done') {
            $row = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->find((int) $validated['id']);
            if ($row === null) {
                return Response::error('İş #'.$validated['id'].' bulunamadı.');
            }
            if ($row->waitsForApproval()) {
                // An ask that is no longer needed: closed without an answer, nothing goes back to Claude.
                $row->forceFill(['done_at' => now()])->save();
            } elseif ($row->done_at === null) {
                $capture->toggle((int) $user->id, (int) $row->id);
            }

            return Response::text('İş #'.$row->id.' tamamlandı olarak işaretlendi.');
        }

        $brandId = $validated['brand_id'] ?? null;
        $brand = $brandId !== null ? Brand::query()->find($brandId) : null;
        if ($brandId !== null && $brand === null) {
            return Response::error('Marka bulunamadı.');
        }
        $text = trim((string) $validated['text']);
        if (preg_match('/^\s*(not|toplant[ıi])\s*:/iu', $text) === 1) {
            return Response::error('Burası yalnız operatörün yapacağı işler için; not ya da toplantı yazma.');
        }

        if ($validated['action'] === 'ask') {
            try {
                $row = $capture->ask($user, $text, $brand, $validated['url'] ?? null);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage());
            }
            if (! $row->wasRecentlyCreated) {
                return Response::text('Zaten onayında: #'.$row->id.' '.$row->title);
            }

            return Response::text('#'.$row->id.' onayına sunuldu (Takvim › Yapılacaklar › Onayını bekleyen). Onaylarsa iş Moxi işi olarak Claude\'a döner; yapmadan bekle. '.route('operator.calendar'));
        }
        $title = QuickCapture::parse($text)['title'];
        $same = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->whereNull('done_at')->where('title', $title)->first();
        if ($same !== null) {
            return Response::text('Zaten Yapılacaklar\'da: #'.$same->id.' '.$same->title);
        }
        try {
            $row = $capture->capture($user, $text, $validated['url'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
        if ($brand !== null && (int) $row->brand_id !== (int) $brand->id) {
            $row->forceFill(['brand_id' => $brand->id, 'customer_id' => $brand->customer_id])->save();
        }

        return Response::text('#'.$row->id.' '.QuickCapture::said($row).' '.route('operator.calendar'));
    }

    /** The operator: the first active admin, as the promise book and the calendar use. */
    public static function operator(): ?User
    {
        return User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['add', 'ask', 'list', 'done'])->description('add | ask | list | done')->required(),
            'text' => $schema->string()->description('add: the job in plain Turkish, verb first; first line = title, the rest = details. ask: first line = the yes/no question about your own work, the rest = exactly what will be done.'),
            'brand_id' => $schema->integer()->description('add / ask: the brand it belongs to.'),
            'url' => $schema->string()->description('add: where the operator does it (panel page, account page). ask: the page the work is about.'),
            'id' => $schema->integer()->description('done: the item id.'),
        ];
    }
}
