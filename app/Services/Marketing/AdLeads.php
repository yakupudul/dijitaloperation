<?php

namespace App\Services\Marketing;

use App\Models\SalesLead;
use App\Models\WhatsAppConversation;
use Carbon\CarbonImmutable;

/**
 * Hangi reklam gerçek aday getiriyor (Satış Düzeni 4.3). An ad is judged by the people it brought, not by its message
 * count: every Click-to-WhatsApp conversation carries the ad it started from, and its record says what the person
 * turned out to be (a prospect, someone screened out at the door, an offer, a customer). Read only.
 */
final class AdLeads
{
    public const int DAYS = 90;

    /**
     * @return list<array{ad: string, campaign: string, headline: string, text: string, url: string, spend: ?float, meta_messages: ?int, wrote: int, prospects: int, screened: int, offers: int, won: int, last: string}>
     */
    public function rows(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Europe/Istanbul');
        $conversations = WhatsAppConversation::query()->whereNotNull('referral')->where('last_incoming_at', '>=', $now->subDays(self::DAYS)->utc())->orderBy('id')->get(['id', 'referral', 'last_incoming_at']);
        if ($conversations->isEmpty()) {
            return [];
        }
        $leads = SalesLead::query()->whereIn('conversation_id', $conversations->pluck('id'))->orderBy('id')->get()->keyBy('conversation_id');
        $rows = [];
        foreach ($conversations as $conversation) {
            $referral = (array) $conversation->referral;
            $key = (string) ($referral['source_id'] ?? $referral['headline'] ?? '');
            if ($key === '') {
                continue;
            }
            $rows[$key] ??= ['id' => (string) ($referral['source_id'] ?? ''), 'referral' => $referral, 'wrote' => 0, 'prospects' => 0, 'screened' => 0, 'offers' => 0, 'won' => 0, 'last' => ''];
            $rows[$key]['wrote']++;
            $rows[$key]['last'] = max($rows[$key]['last'], (string) $conversation->last_incoming_at?->timezone('Europe/Istanbul')->format('Y-m-d'));
            $lead = $leads[$conversation->id] ?? null;
            if ($lead === null) {
                continue;
            }
            if ($lead->lifecycle === 'irrelevant') {
                $rows[$key]['screened']++;

                continue;
            }
            $rows[$key]['prospects']++;
            $rows[$key]['offers'] += $lead->hasRealOffer() || $lead->stage === 'won' ? 1 : 0;
            $rows[$key]['won'] += $lead->stage === 'won' || $lead->lifecycle === 'customer' ? 1 : 0;
        }
        // The ad's own name and campaign from the ad account (Meta's headline is often a generic "Kampanyadan faydalanın"),
        // and what Meta counted for it in the same days: spend and conversations started.
        $source = app(AdSource::class);
        $source->names(array_column($rows, 'id'));
        $totals = $source->totals(array_column($rows, 'id'), $now->subDays(self::DAYS));
        foreach ($rows as $key => $row) {
            $ad = $source->of($row['referral']) ?? ['ad' => 'Reklam '.$key, 'campaign' => '', 'headline' => '', 'text' => '', 'url' => ''];
            unset($row['referral']);
            $rows[$key] = ['ad' => $ad['ad'], 'campaign' => $ad['campaign'], 'headline' => $ad['headline'] !== $ad['ad'] ? $ad['headline'] : '', 'text' => $ad['text'], 'url' => $ad['url'],
                'spend' => $totals[$row['id']]['spend'] ?? null, 'meta_messages' => $totals[$row['id']]['messages'] ?? null] + $row;
            unset($rows[$key]['id']);
        }
        usort($rows, fn (array $a, array $b): int => [$b['prospects'], $b['wrote'], $a['ad']] <=> [$a['prospects'], $a['wrote'], $b['ad']]);

        return array_values($rows);
    }
}
