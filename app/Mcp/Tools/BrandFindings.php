<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\BrandFinding;
use App\Services\Assistant\PushNotifier;
use App\Services\Brand\Baseline;
use App\Services\Brand\Findings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Marka sayfası › Bulgular: the findings store of a brand page. action=baseline (brand_id) takes the start numbers of the brand once: demand per channel, organic clicks and the clicks of each main service over the last 30 full days (Marka sayfası › Hizmet karnesi shows them beside the current 30 days). action=list (brand_id optional; only_requested=true = the rows the operator pressed "Çöz" on, do these before the AI queue). action=save writes one row for a brand after you looked at its data: kind finding (needs evidence: the number or the address), untouched ("baktım, dokunmuyorum": looked, fine as it is), unseen ("göremedim": no access / no data, say what is missing) or task (only the operator can do it: an access to ask for, a decision); area (Web sitesi | Ölçüm | Google Ads | Meta reklamları | İşletme Profili | İçerik | Mevzuat | Erişim | Diğer); title one plain Turkish sentence; evidence one line with numbers; saw / plan / needs = Ne gördüm / Ne yapacağım / Senden; approval none (reversible, no approval) | redirect | publish | file (you prepare a file, the operator uploads; ads are never written). Pass id to update a row instead of adding a twin. action=result on a requested row after doing the work with the usual tools: result (what was done, plain Turkish), url (where to see it), waiting=true when the operator still has to approve something. action=effect a month later: the measured change ("mobil hız 30 → 55"). Never write a finding you did not see in the data; plain Turkish, no jargon.')]
class BrandFindings extends Tool
{
    public function handle(Request $request, Findings $findings, PushNotifier $push): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['list', 'save', 'result', 'effect', 'baseline'])],
            'brand_id' => ['required_if:action,save', 'nullable', 'integer'],
            'id' => ['required_if:action,result', 'required_if:action,effect', 'nullable', 'integer'],
            'only_requested' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'string'], 'area' => ['nullable', 'string', 'max:40'], 'title' => ['nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'string', 'max:500'], 'saw' => ['nullable', 'string', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'],
            'needs' => ['nullable', 'string', 'max:2000'], 'approval' => ['nullable', 'string'],
            'result' => ['required_if:action,result', 'nullable', 'string', 'min:3', 'max:4000'], 'url' => ['nullable', 'string', 'max:500'],
            'waiting' => ['nullable', 'boolean'], 'effect' => ['required_if:action,effect', 'nullable', 'string', 'min:3', 'max:2000'],
        ]);
        if ($validated['action'] === 'list') {
            $rows = BrandFinding::query()->with('brand:id,name')
                ->when(isset($validated['brand_id']), fn ($q) => $q->where('brand_id', (int) $validated['brand_id']))
                ->when((bool) ($validated['only_requested'] ?? false), fn ($q) => $q->where('status', 'requested'))
                ->orderBy('brand_id')->orderBy('id')->limit(200)->get();

            return Response::json(['requested' => Findings::requested(), 'rows' => $rows->map(fn (BrandFinding $row): array => [
                'id' => $row->id, 'brand_id' => $row->brand_id, 'brand' => $row->brand?->name, 'kind' => $row->kind, 'area' => $row->area, 'title' => $row->title,
                'evidence' => $row->evidence, 'saw' => $row->saw, 'plan' => $row->plan, 'needs' => $row->needs, 'approval' => $row->approval, 'status' => $row->status,
                'result' => $row->result, 'url' => $row->result_url, 'effect' => $row->effect,
                'requested_at' => $row->requested_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
            ])->all()]);
        }
        if ($validated['action'] === 'baseline') {
            // Marka Düzeni 3.10: the brand's start numbers (30 full days), taken once at its setup.
            $brand = Brand::query()->find((int) ($validated['brand_id'] ?? 0));
            if ($brand === null) {
                return Response::error('baseline için brand_id gerekli.');
            }
            $taken = app(Baseline::class)->take($brand);

            return Response::json(['brand' => $brand->name, 'from' => $taken->period_from->toDateString(), 'to' => $taken->period_to->toDateString(), 'numbers' => $taken->numbers]);
        }
        if ($validated['action'] === 'save') {
            $brand = Brand::query()->find((int) $validated['brand_id']);
            if ($brand === null) {
                return Response::error('Marka #'.$validated['brand_id'].' yok.');
            }
            try {
                $row = $findings->save($brand, $validated, isset($validated['id']) ? (int) $validated['id'] : null);
            } catch (ValidationException $exception) {
                return Response::error((string) collect($exception->errors())->flatten()->implode(' '));
            }

            return Response::text('Bulgu #'.$row->id.' yazıldı: '.$brand->name.' · '.$row->title);
        }
        $row = BrandFinding::query()->with('brand:id,name')->find((int) $validated['id']);
        if ($row === null) {
            return Response::error('Bulgu #'.$validated['id'].' yok.');
        }
        if ($validated['action'] === 'effect') {
            $findings->effect($row, (string) $validated['effect']);

            return Response::text('Bulgu #'.$row->id.' etkisi yazıldı.');
        }
        if (! in_array($row->status, ['requested', 'waiting'], true)) {
            return Response::error('Bulgu #'.$row->id.' için "Çöz" denmedi (durum: '.$row->status.'). Önce operatör ister.');
        }
        $waiting = (bool) ($validated['waiting'] ?? false);
        $findings->result($row, (string) $validated['result'], $validated['url'] ?? null, $waiting);
        $push->send('brand-finding:'.$row->id.':'.($waiting ? 'w' : 'd'), ($row->brand?->name ?? 'Marka').': '.($waiting ? 'onayını bekleyen iş' : 'bulgu çözüldü'),
            mb_substr($row->title.' · '.trim((string) $validated['result']), 0, 300), 'high', route('operator.brand', ['brand' => $row->brand_id]));

        return Response::text('Bulgu #'.$row->id.' sonucu yazıldı ('.($waiting ? 'onay bekliyor' : 'yapıldı').').');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'save', 'result', 'effect', 'baseline'])->description('list | save | result | effect | baseline')->required(),
            'brand_id' => $schema->integer()->description('Brand (save; optional filter for list).'),
            'id' => $schema->integer()->description('Row id (result, effect; save = update this row).'),
            'only_requested' => $schema->boolean()->description('list: only rows the operator pressed "Çöz" on.'),
            'kind' => $schema->string()->enum(array_keys(BrandFinding::KINDS))->description('finding | untouched | unseen | task'),
            'area' => $schema->string()->description('Web sitesi | Ölçüm | Google Ads | Meta reklamları | İşletme Profili | İçerik | Mevzuat | Erişim | Diğer'),
            'title' => $schema->string()->description('One plain Turkish sentence.'),
            'evidence' => $schema->string()->description('One line with the numbers / the address (required for a finding).'),
            'saw' => $schema->string()->description('Ne gördüm.'),
            'plan' => $schema->string()->description('Ne yapacağım.'),
            'needs' => $schema->string()->description('Senden ne gerekiyor ("Bir şey gerekmiyor." when nothing).'),
            'approval' => $schema->string()->enum(array_keys(BrandFinding::APPROVALS))->description('none | redirect | publish | file'),
            'result' => $schema->string()->description('result: what was done.'),
            'url' => $schema->string()->description('result: where to see it.'),
            'waiting' => $schema->boolean()->description('result: true when the operator still has to approve.'),
            'effect' => $schema->string()->description('effect: the measured change a month later.'),
        ];
    }
}
