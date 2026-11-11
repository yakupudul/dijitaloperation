<?php

namespace App\Services\Sales;

use App\Models\AccessLink;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\BrandBaseline;
use App\Models\SalesLead;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Anlaşma adımları (Satış Düzeni 3.1). "Kazanıldı" is not one step: after the yes come the contract, the first
 * payment, the accesses and the setup. Every step but the contract is read from what is already recorded (the
 * agreement's payment ticks, the access link, the brand's start numbers), so nothing is kept twice. A won record
 * stays in front of the operator until the five are done, or until the window has passed.
 */
final class ClosingSteps
{
    public const array STEPS = ['yes' => 'Evet dedi', 'contract' => 'Sözleşme', 'payment' => 'İlk ödeme', 'access' => 'Erişimler', 'setup' => 'Kurulum'];

    /** A win older than this is no longer followed; the brand page takes over. */
    public const int WINDOW_DAYS = 60;

    /**
     * @return array{steps: list<array{key: string, label: string, done: bool, note: string}>, current: ?string, finished: bool, agreement: ?Agreement}|null
     */
    public function of(SalesLead $lead): ?array
    {
        if ($lead->stage !== 'won' || $lead->brand_id === null) {
            return null;
        }
        $agreement = Agreement::query()->where('brand_id', $lead->brand_id)->orderByDesc('id')->first();
        $signed = $agreement !== null && SchemaState::hasColumn('agreements', 'contract_on') && $agreement->contract_on !== null;
        $paid = $agreement !== null ? AgreementPayment::query()->where('agreement_id', $agreement->id)->whereNotNull('paid_on')->orderBy('paid_on')->orderBy('id')->first() : null;
        $link = SchemaState::hasTable('access_links') ? AccessLink::query()->where('brand_id', $lead->brand_id)->orderByDesc('id')->first() : null;
        $asked = $link !== null ? count((array) $link->asked) : 0;
        $given = $link !== null ? count(array_intersect((array) $link->asked, array_keys((array) $link->done))) : 0;
        $looked = SchemaState::hasTable('brand_baselines') && BrandBaseline::query()->where('brand_id', $lead->brand_id)->exists();
        $day = fn ($date): string => CarbonImmutable::parse($date)->setTimezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMMM');

        $steps = [
            ['key' => 'yes', 'done' => true, 'note' => $lead->won_at !== null ? $day($lead->won_at) : ''],
            ['key' => 'contract', 'done' => $signed, 'note' => match (true) {
                $agreement === null => 'Önce anlaşma şartlarını gir',
                $signed => $day($agreement->contract_on),
                default => $link === null ? 'İmza bekleniyor' : 'İmza bekleniyor, bağlantıdan onaylanır',
            }],
            ['key' => 'payment', 'done' => $paid !== null, 'note' => match (true) {
                $agreement === null => 'Önce anlaşma şartlarını gir',
                $paid !== null => $day($paid->paid_on),
                ($link?->start['paid_at'] ?? null) !== null => 'Müşteri ödedim dedi, hesabında görünce işaretle',
                default => $agreement->feeLabel().' bekleniyor',
            }],
            ['key' => 'access', 'done' => $asked > 0 && $given >= $asked, 'note' => match (true) {
                $link === null => 'Erişim bağlantısı hazırlanmadı',
                $given >= $asked => 'Hepsi verildi',
                default => $asked.' erişimden '.$given.' tanesi verildi',
            }],
            ['key' => 'setup', 'done' => $looked, 'note' => $looked ? 'Başlangıç ölçümü alındı' : 'Başlangıç ölçümü bekliyor'],
        ];
        $steps = array_map(fn (array $step): array => $step + ['label' => self::STEPS[$step['key']]], $steps);
        $current = collect($steps)->firstWhere('done', false)['key'] ?? null;

        return ['steps' => $steps, 'current' => $current, 'finished' => $current === null, 'agreement' => $agreement];
    }

    /**
     * Won records whose steps are not finished yet, oldest win first.
     *
     * @return Collection<int, array{lead: SalesLead, steps: list<array{key: string, label: string, done: bool, note: string}>, current: string, line: string}>
     */
    public function open(): Collection
    {
        return SalesLead::query()->where('stage', 'won')->whereNotNull('brand_id')->where('won_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderBy('won_at')->orderBy('id')->get()
            ->map(function (SalesLead $lead): ?array {
                $state = $this->of($lead);
                if ($state === null || $state['finished']) {
                    return null;
                }
                $step = collect($state['steps'])->firstWhere('key', $state['current']);

                return ['lead' => $lead, 'steps' => $state['steps'], 'current' => $state['current'], 'line' => $step['label'].': '.mb_strtolower(strtr(mb_substr($step['note'], 0, 1), ['İ' => 'i', 'I' => 'ı'])).mb_substr($step['note'], 1)];
            })->filter()->values();
    }

    /** "Sözleşme imzalandı" (or taken back with null). */
    public function contract(SalesLead $lead, ?string $on): bool
    {
        $agreement = $this->of($lead)['agreement'] ?? null;
        if ($agreement === null || ! SchemaState::hasColumn('agreements', 'contract_on')) {
            return false;
        }
        $agreement->forceFill(['contract_on' => $on])->save();

        return true;
    }

    /** "İlk ödeme alındı": the agreement's first open payment is ticked. */
    public function firstPayment(SalesLead $lead, Agreements $agreements): bool
    {
        $agreement = $this->of($lead)['agreement'] ?? null;
        if ($agreement === null) {
            return false;
        }
        $agreements->ensurePayments($agreement);
        $payment = AgreementPayment::query()->where('agreement_id', $agreement->id)->whereNull('paid_on')->orderBy('due_on')->orderBy('id')->first();
        if ($payment === null) {
            return false;
        }
        $agreements->markPaid($payment);

        return true;
    }
}
