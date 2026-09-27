<?php

namespace App\Services\CommandCenter;

use App\Models\ClientApproval;
use App\Models\ContentCalendarItem;
use Illuminate\Support\Collection;

/** İçerik takvimi in the command center: content due today or late, and Business Profile posts that failed. */
final class CalendarSource implements CommandCenterSource
{
    public function items(): Collection
    {
        return $this->calendar()->concat($this->clientAnswers());
    }

    /** ADR-075: the client answered an approval link; the operator acts on it once. */
    private function clientAnswers(): Collection
    {
        return ClientApproval::query()->with('brand')->whereIn('status', ['approved', 'changes_requested'])->whereNull('acknowledged_at')
            ->orderBy('responded_at')->limit(100)->get()
            ->map(fn (ClientApproval $approval): array => CommandCenter::item('client_approval', $approval->id, $approval->status === 'changes_requested' ? 'high' : 'medium',
                ($approval->status === 'approved' ? 'Müşteri onayladı: ' : 'Müşteri değişiklik istedi: ').$approval->title, [
                    'detail' => $approval->client_note,
                    'brand_id' => $approval->brand_id,
                    'brand' => $approval->brand?->name,
                    'channel' => 'Müşteri onayı',
                    'rule' => (string) $approval->status,
                    'url' => route('operator.content.calendar', ['brand' => $approval->brand_id]),
                    'age' => $approval->responded_at,
                    'actions' => ['done'],
                ]));
    }

    private function calendar(): Collection
    {
        return ContentCalendarItem::query()->with('brand')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('channel', '!=', 'gbp_post')->whereIn('status', ['draft', 'approved'])->where('scheduled_for', '<=', now()->endOfDay()))
                ->orWhere('status', 'failed')
                ->orWhere(fn ($q) => $q->where('channel', 'gbp_post')->where('status', 'draft')->where('scheduled_for', '<=', now()->addDay())))
            ->orderBy('scheduled_for')->limit(100)->get()
            ->map(fn (ContentCalendarItem $item): array => CommandCenter::item('calendar', $item->id, $item->status === 'failed' ? 'high' : 'medium', match (true) {
                $item->status === 'failed' => 'Gönderi yayınlanamadı: '.$item->title,
                $item->channel === 'gbp_post' => 'Onay bekleyen gönderi: '.$item->title,
                default => (ContentCalendarItem::CHANNELS[$item->channel] ?? 'İçerik').' zamanı: '.$item->title,
            }, [
                'detail' => $item->error ?? $item->body,
                'brand_id' => $item->brand_id,
                'brand' => $item->brand?->name,
                'channel' => 'İçerik takvimi',
                'rule' => match (true) {
                    $item->status === 'failed' => 'failed',
                    $item->channel === 'gbp_post' => 'gbp_post',
                    default => 'due',
                },
                'url' => route('operator.content.calendar', ['brand' => $item->brand_id]),
                'age' => $item->scheduled_for,
                'actions' => $item->channel === 'gbp_post' ? ['snooze'] : ['done', 'snooze', 'dismiss'],
            ]));
    }
}
