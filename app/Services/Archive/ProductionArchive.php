<?php

namespace App\Services\Archive;

use App\Models\AdvisorItem;
use App\Models\AiProduction;
use App\Models\BrandSetupProposal;
use App\Models\MonthlyReport;
use App\Models\SeoTask;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Üretim Arşivi. AI outputs are recorded when they land on their work item (model events, see boot()), so
 * the drafters themselves stay unchanged; the same content is never stored twice for a subject. Versions are
 * never deleted; the operator marks them used / published / discarded and rates them 👍 / 👎.
 */
final class ProductionArchive
{
    public const array KIND_LABELS = [
        'google_ads.ad_copy' => 'Google Ads reklam metni',
        'meta_ads.creative' => 'Meta reklam metni',
        'gbp.profile' => 'İşletme Profili metni',
        'seo.content_brief' => 'SEO içerik briefi',
        'whatsapp.reply' => 'WhatsApp yanıt önerisi',
        'brand_setup.proposal' => 'Marka kurulum önerisi',
        'report.monthly_commentary' => 'Aylık rapor yorumu',
    ];

    /** Advisor draft rules → archive kind. */
    private const array ADVISOR_KINDS = [
        'google_ads' => 'google_ads.ad_copy',
        'meta_ads' => 'meta_ads.creative',
        'gbp' => 'gbp.profile',
        'google_business_profile' => 'gbp.profile',
    ];

    /** Register the model hooks that feed the archive. */
    public static function boot(): void
    {
        AdvisorItem::saved(static function (AdvisorItem $item): void {
            if ($item->wasChanged('draft') && $item->draft_status === 'ready' && is_array($item->draft) && ! isset($item->draft['error'])) {
                self::safely(fn () => app(self::class)->record(
                    self::ADVISOR_KINDS[(string) $item->channel] ?? 'advisor.'.$item->channel, $item, $item->draft,
                    ['brand_id' => $item->brand_id, 'digital_asset_id' => $item->digital_asset_id, 'title' => $item->title],
                ));
            }
        });
        SeoTask::saved(static function (SeoTask $task): void {
            $brief = $task->content_brief;
            if ($task->wasChanged('content_brief') && is_array($brief) && ($brief['source'] ?? null) === 'llm') {
                self::safely(fn () => app(self::class)->record('seo.content_brief', $task, $brief + ['title' => $task->title, 'target_url' => $task->target_url],
                    ['brand_id' => $task->brand_id, 'digital_asset_id' => $task->digital_asset_id, 'title' => $task->title]));
            }
        });
        WhatsAppConversation::saved(static function (WhatsAppConversation $conversation): void {
            if ($conversation->wasChanged('suggested_at') && $conversation->suggestion_status === 'ready' && filled($conversation->suggestion)) {
                self::safely(fn () => app(self::class)->record('whatsapp.reply', $conversation,
                    ['reply' => (string) $conversation->suggestion, 'action' => $conversation->suggestion_action, 'rationale' => $conversation->rationale],
                    ['title' => 'WhatsApp · '.($conversation->contact_name ?? $conversation->id), 'model' => null]));
            }
        });
        MonthlyReport::saved(static function (MonthlyReport $report): void {
            if ($report->wasChanged('commentary') && $report->commentary_status === 'ready' && is_array($report->commentary) && ($report->commentary['source'] ?? null) === 'llm') {
                self::safely(fn () => app(self::class)->record('report.monthly_commentary', $report, $report->commentary,
                    ['brand_id' => $report->brand_id, 'title' => 'Aylık rapor · '.$report->month, 'provider' => $report->commentary['provider'] ?? null, 'model' => $report->commentary['model'] ?? null, 'prompt_version' => $report->commentary['prompt_version'] ?? null]));
            }
        });
        BrandSetupProposal::saved(static function (BrandSetupProposal $proposal): void {
            if ($proposal->wasChanged('summary') && is_array($proposal->summary) && $proposal->summary !== []) {
                self::safely(fn () => app(self::class)->record('brand_setup.proposal', $proposal,
                    ['summary' => $proposal->summary, 'services' => $proposal->services],
                    ['brand_id' => $proposal->brand_id, 'title' => 'Marka kurulum önerisi']));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array{brand_id?: ?int, digital_asset_id?: ?int, title?: ?string, provider?: ?string, model?: ?string, prompt_version?: ?string}  $meta
     */
    public function record(string $kind, Model $subject, array $content, array $meta = []): ?AiProduction
    {
        $body = $content;
        unset($body['created_at']);
        $hash = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');
        $subjectType = class_basename($subject->getMorphClass());

        return DB::transaction(function () use ($kind, $subject, $content, $meta, $hash, $subjectType): ?AiProduction {
            $existing = AiProduction::query()->where('kind', $kind)->where('subject_type', $subjectType)->where('subject_id', $subject->getKey())
                ->lockForUpdate()->get(['id', 'version', 'content_hash']);
            if ($existing->contains('content_hash', $hash)) {
                return null;
            }

            return AiProduction::query()->create([
                'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => (int) $subject->getKey(),
                'brand_id' => $meta['brand_id'] ?? null, 'digital_asset_id' => $meta['digital_asset_id'] ?? null,
                'version' => (int) $existing->max('version') + 1,
                'title' => isset($meta['title']) ? mb_substr((string) $meta['title'], 0, 255) : null,
                'content' => $content, 'content_hash' => $hash,
                'provider' => $meta['provider'] ?? ($content['provider'] ?? null),
                'model' => $meta['model'] ?? ($content['model'] ?? null),
                'prompt_version' => isset($content['prompt_version']) ? (string) $content['prompt_version'] : ($meta['prompt_version'] ?? null),
                'status' => AiProduction::STATUS_NEW,
            ]);
        });
    }

    /** Latest version for a subject created within $days (fresh output is shown before a new AI call). */
    public function fresh(string $kind, Model $subject, int $days = 14): ?AiProduction
    {
        $subjectType = class_basename($subject->getMorphClass());

        return AiProduction::query()->where('kind', $kind)->where('subject_type', $subjectType)->where('subject_id', $subject->getKey())
            ->where('status', '!=', AiProduction::STATUS_DISCARDED)->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('version')->first();
    }

    public function advisorKind(AdvisorItem $item): string
    {
        return self::ADVISOR_KINDS[(string) $item->channel] ?? 'advisor.'.$item->channel;
    }

    public function versions(Model $subject): int
    {
        return AiProduction::query()->where('subject_type', class_basename($subject->getMorphClass()))->where('subject_id', $subject->getKey())->count();
    }

    public function mark(AiProduction $production, string $status, ?User $by): void
    {
        if (! in_array($status, AiProduction::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown archive status: '.$status);
        }
        $production->forceFill(['status' => $status, 'status_changed_by' => $by?->id, 'status_changed_at' => now()])->save();
    }

    public function rate(AiProduction $production, int $rating): void
    {
        $production->forceFill(['rating' => $production->rating === $rating ? null : max(-1, min(1, $rating))])->save();
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception); // the archive must never break the work item that produced the output
        }
    }
}
