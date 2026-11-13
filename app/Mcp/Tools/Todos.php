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
#[Description('Yapılacaklar: the operator\'s own to-do list (Takvim › Yapılacaklar). Whenever a job is left that only the operator can do (give an access, upload a prepared file, decide, tell a date or an amount, press a connect button), add it here at once with action=add: text = one plain Turkish sentence starting with the verb ("Serap Korkmaz\'ı panele marka olarak ekle"), the second line and on = details; "yarın 10" / "15 aralık" in the text sets a date, without one it stays undated until ticked; brand_id ties it to a brand; url = where to do it. An open item with the same title is not added twice. action=list shows open, late and today\'s items; action=done (id) ticks one you saw finished. Never add your own work here, only the operator\'s.')]
class Todos extends Tool
{
    public function handle(Request $request, QuickCapture $capture): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['add', 'list', 'done'])],
            'text' => ['required_if:action,add', 'nullable', 'string', 'min:5', 'max:2000'],
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

            return Response::json(['url' => route('operator.calendar'), 'count' => $board['count'], 'today' => $board['today'], 'late' => $board['late'], 'open' => $board['open']]);
        }

        if ($validated['action'] === 'done') {
            $row = Reminder::query()->where('user_id', $user->id)->where('kind', 'task')->find((int) $validated['id']);
            if ($row === null) {
                return Response::error('İş #'.$validated['id'].' bulunamadı.');
            }
            if ($row->done_at === null) {
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
            'action' => $schema->string()->enum(['add', 'list', 'done'])->description('add | list | done')->required(),
            'text' => $schema->string()->description('add: the job in plain Turkish, verb first; first line = title, the rest = details.'),
            'brand_id' => $schema->integer()->description('add: the brand it belongs to.'),
            'url' => $schema->string()->description('add: where the operator does it (panel page, account page).'),
            'id' => $schema->integer()->description('done: the item id.'),
        ];
    }
}
