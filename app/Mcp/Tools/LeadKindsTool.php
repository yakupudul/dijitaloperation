<?php

namespace App\Mcp\Tools;

use App\Models\SalesLead;
use App\Support\Sales\LeadKinds;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('lead-kinds')]
#[Description('The Tür list of the sales records (Satış): what kind of business a record is (klinik, muayenehane, hekim, hastane, fabrika, mağaza, hizmet işletmesi…). The record itself is generic; the kind decides the message tone (hitap), whether moximu.com\'s clinic prices may be quoted by AI, and the health rules. action=list shows the kinds with how many records carry each; add / update create or change one (key, label, address hocam|bey_hanim, prices clinic|none, health, hint = comma-separated words the classifier matches, note = why); remove deletes one (its records fall back to "other"). Change the list only after an analysis of the real records shows a kind is missing or unused, and say why in note; the operator sees "Claude" next to what you changed.')]
class LeadKindsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'in:list,add,update,remove'],
            'key' => ['nullable', 'string', 'max:30'],
            'label' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'in:hocam,bey_hanim'],
            'prices' => ['nullable', 'in:clinic,none'],
            'health' => ['nullable', 'boolean'],
            'hint' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $action = $validated['action'];
        if ($action === 'list') {
            return Response::text($this->listing());
        }
        $key = LeadKinds::key((string) ($validated['key'] ?? ''));
        if ($key === '') {
            return Response::error('key gerekli (harf, rakam, alt çizgi).');
        }
        try {
            if ($action === 'remove') {
                $moved = LeadKinds::remove($key);

                return Response::text('Tür silindi: '.$key.'. '.$moved.' kayıt "Diğer" oldu.'."\n\n".$this->listing());
            }
            if ($action === 'update' && ! LeadKinds::has($key)) {
                return Response::error('Böyle bir tür yok: '.$key.'. add ile ekle.');
            }
            if ($action === 'add' && LeadKinds::has($key)) {
                return Response::error('Bu tür zaten var: '.$key.'. update ile değiştir.');
            }
            if ($action === 'add' && trim((string) ($validated['label'] ?? '')) === '') {
                return Response::error('Yeni tür için label gerekli.');
            }
            LeadKinds::put($key, array_filter([
                'label' => $validated['label'] ?? null, 'address' => $validated['address'] ?? null, 'prices' => $validated['prices'] ?? null,
                'health' => $validated['health'] ?? null, 'hint' => $validated['hint'] ?? null, 'note' => $validated['note'] ?? null,
            ], fn ($v): bool => $v !== null), 'claude');
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        }

        return Response::text(($action === 'add' ? 'Tür eklendi: ' : 'Tür güncellendi: ').$key."\n\n".$this->listing());
    }

    private function listing(): string
    {
        $counts = SalesLead::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $lines = ['Türler (anahtar · ad · hitap · fiyat · sağlık · kayıt sayısı · kim ekledi):'];
        foreach (LeadKinds::all() as $key => $kind) {
            $lines[] = sprintf('- %s · %s · %s · %s · %s · %d kayıt · %s%s', $key, $kind['label'], $kind['address'], $kind['prices'], $kind['health'] ? 'sağlık' : '-',
                (int) ($counts[$key] ?? 0), $kind['by'] ?? 'varsayılan', filled($kind['note'] ?? null) ? ' · '.$kind['note'] : '');
        }
        $lines[] = sprintf('- (türsüz) · %d kayıt', (int) ($counts[''] ?? 0) + (int) ($counts[null] ?? 0));

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'add', 'update', 'remove'])->required(),
            'key' => $schema->string()->description('The kind\'s key (ascii, e.g. factory). Required for add / update / remove.'),
            'label' => $schema->string()->description('Turkish name shown in the panel (e.g. Fabrika / üretici).'),
            'address' => $schema->string()->enum(['hocam', 'bey_hanim'])->description('How messages address this kind.'),
            'prices' => $schema->string()->enum(['clinic', 'none'])->description('clinic = moximu.com clinic prices may be written by AI; none = AI never writes a price.'),
            'health' => $schema->boolean()->description('Health-advertising rules apply.'),
            'hint' => $schema->string()->description('Comma-separated words the classifier matches in the name, site, branch and notes.'),
            'note' => $schema->string()->description('Why this change (shown to the operator).'),
        ];
    }
}
