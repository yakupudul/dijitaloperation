<?php

namespace App\Services\Sales;

use App\Enums\IntentRadarRunStatus;
use App\Jobs\RunFreeIntentRadar;
use App\Models\SalesIntentRadarRun;
use App\Models\SalesIntentSignal;
use App\Models\SalesRadarPage;
use App\Models\SalesRadarSource;
use App\Models\SalesSearchProfile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class FreeIntentRadar
{
    public function tick(): void
    {
        SalesIntentRadarRun::query()->where('provider', 'public_sources')
            ->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(20))
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => json_encode(['message' => 'worker_interrupted']), 'updated_at' => now()]);
        $profile = SalesSearchProfile::query()->where('active', true)->where('free_radar_enabled', true)
            ->whereHas('owner', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('radar_next_at')->orWhere('radar_next_at', '<=', now()))
            ->orderByRaw('CASE WHEN radar_next_at IS NULL THEN 0 ELSE 1 END')->orderBy('radar_next_at')->orderBy('id')->first();
        if ($profile && $profile->owner?->can(\App\Support\Permissions::ACCESS_APP)) {
            $this->queue($profile, $profile->owner);
        }
    }

    public function queue(SalesSearchProfile $profile, User $actor): ?SalesIntentRadarRun
    {
        abort_unless($actor->is_active && $actor->can(\App\Support\Permissions::ACCESS_APP), 403);
        $lock = Cache::lock('free-radar:admission', 15);
        if (! $lock->get()) {
            return null;
        }
        try {
            if (! $profile->active || ! $profile->free_radar_enabled || ! SalesRadarSource::query()->where('enabled', true)->exists()) {
                return null;
            }
            if (SalesIntentRadarRun::query()->where('provider', 'public_sources')->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }
            if (! $profile->owner_user_id) {
                $profile->update(['owner_user_id' => $actor->id]);
            }
            $run = SalesIntentRadarRun::query()->create([
                'sales_search_profile_id' => $profile->id, 'status' => 'queued', 'provider' => 'public_sources',
                'provider_reality' => 'unavailable', 'paid_call' => false, 'reported_cost_usd' => 0,
                'metadata' => ['mode' => 'free_rules_v1', 'actor_id' => $actor->id],
            ]);
            $profile->update(['radar_next_at' => now()->addMinutes($profile->radar_interval_minutes)]);
            try {
                RunFreeIntentRadar::dispatch($run->id)->onConnection(in_array(config('queue.default'), ['redis', 'database'], true) ? config('queue.default') : 'database')->onQueue('default');
            } catch (Throwable $exception) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => ['message' => 'dispatch_failed']]);
                throw $exception;
            }
            app(IntentActivityRecorder::class)->record('intent_run.queued', __('free_radar.queued'), $profile, $run, actor: $actor);
            return $run;
        } finally {
            $lock->release();
        }
    }

    public function execute(int $id): void
    {
        $lock = Cache::lock('free-radar:execution', 300);
        if (! $lock->get()) {
            throw new RuntimeException('radar_busy');
        }
        try {
            $run = SalesIntentRadarRun::query()->findOrFail($id);
            if ($run->status !== IntentRadarRunStatus::Queued) {
                return;
            }
            $profile = SalesSearchProfile::query()->with(['owner', 'catalogService.names', 'catalogService.matchingKeywords'])->findOrFail($run->sales_search_profile_id);
            if (! $profile->active || ! $profile->free_radar_enabled || ! $profile->owner?->is_active
                || ! $profile->owner->can(\App\Support\Permissions::ACCESS_APP)) {
                throw new RuntimeException('profile_paused');
            }
            $run->update(['status' => 'running', 'started_at' => now()]);
            $reader = app(FreeRadarReader::class);
            $matcher = app(FreeRadarMatcher::class);
            $errors = [];
            $sources = SalesRadarSource::query()->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
                ->orderByRaw('CASE WHEN next_at IS NULL THEN 0 ELSE 1 END')->orderBy('next_at')->limit(2)->get();
            foreach ($sources as $source) {
                try {
                    $rows = $reader->listing($reader->read($source->url), $source->url, $source->format);
                    foreach ($rows as $row) {
                        $page = SalesRadarPage::query()->firstOrNew(['url_hash' => hash('sha256', $row['url'])]);
                        if (! $page->exists) {
                            $page->fill(['sales_radar_source_id' => $source->id, 'url' => $row['url'], 'first_seen_at' => now()]);
                        }
                        if ($page->exists && $page->title !== $row['title']) {
                            $page->fill(['retry_at' => now(), 'state' => 'pending', 'excerpt' => null]);
                        }
                        $page->fill(['title' => $row['title'], 'last_seen_at' => now()]);
                        if ($row['published_at'] && ! $page->published_at) {
                            $page->published_at = $row['published_at'];
                        }
                        $page->save();
                    }
                    $source->update(['checked_at' => now(), 'next_at' => now()->addMinutes($source->interval_minutes),
                        'state' => 'ready', 'error' => null, 'failures' => 0, 'item_count' => count($rows)]);
                } catch (Throwable $exception) {
                    $failure = $this->error($exception);
                    $errors[] = $failure;
                    $source->update(['checked_at' => now(), 'next_at' => now()->addMinutes(min(1440, 60 * (2 ** min(4, $source->failures)))),
                        'state' => 'failed', 'error' => $failure, 'failures' => $source->failures + 1]);
                }
            }

            $sourceIds = SalesRadarSource::query()->where('enabled', true)->pluck('id');
            $pages = SalesRadarPage::query()->whereIn('sales_radar_source_id', $sourceIds)
                ->where('last_seen_at', '>=', now()->subDays(30))->orderByDesc('last_seen_at')->orderByDesc('id')->limit(500)->get();
            $terms = $matcher->terms($profile);
            $requests = 0;
            $backlog = SalesRadarSource::query()->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))->exists();
            foreach ($pages as $page) {
                if (! $matcher->matches($page->title, $terms)) {
                    continue;
                }
                if ($page->published_at?->lessThan(now()->subDays(30))) {
                    continue;
                }
                $due = $page->retry_at === null || $page->retry_at->lessThanOrEqualTo(now());
                if (! $due) {
                    continue;
                }
                if ($requests >= 2) {
                    $backlog = true;
                    continue;
                }
                $requests++;
                try {
                    $detail = $reader->detail($reader->read($page->url));
                    $page->update([
                        'excerpt' => $detail['excerpt'], 'published_at' => $detail['published_at'] ?? $page->published_at,
                        'author' => $detail['author'], 'state' => $detail['excerpt'] ? 'read' : 'unverified',
                        'fetched_at' => now(), 'retry_at' => now()->addDay(), 'attempts' => 0,
                        'error' => $detail['excerpt'] ? null : 'content_unrecognized',
                    ]);
                } catch (Throwable $exception) {
                    $errors[] = $this->error($exception);
                    $page->update(['state' => 'unreachable', 'error' => $this->error($exception),
                        'retry_at' => now()->addMinutes(min(1440, 60 * (2 ** min(4, $page->attempts)))),
                        'attempts' => $page->attempts + 1]);
                }
            }

            $created = 0;
            $profile->refresh();
            foreach ($pages as $page) {
                if (! $profile->free_radar_enabled || ! $profile->active) {
                    break;
                }
                $decision = $matcher->evaluate($profile, $page);
                $fingerprint = hash('sha256', 'free-radar|'.$profile->id.'|'.$page->url_hash);
                $existing = SalesIntentSignal::query()->where('fingerprint', $fingerprint)->first();
                if (! $decision['eligible'] && ! $existing) {
                    continue;
                }
                if (! $decision['eligible']) {
                    $existing->update(['last_seen_at' => $page->last_seen_at, 'negative_signals' => $decision['negatives'],
                        'purchase_stage' => 'unknown', 'classification_reason' => __('free_radar.no_longer_matches')]);
                    continue;
                }
                $signal = $existing ?? new SalesIntentSignal;
                if (! $signal->exists) {
                    $signal->fill(['sales_search_profile_id' => $profile->id, 'fingerprint' => $fingerprint,
                        'status' => 'new', 'discovered_at' => now(), 'first_seen_at' => $page->first_seen_at]);
                    $created++;
                }
                $signal->fill([
                    'sales_intent_radar_run_id' => $run->id, 'source_type' => 'public_source',
                    'source_url' => $page->url, 'source_title' => $page->title,
                    'observed_snippet' => $page->title, 'fetched_source_excerpt' => $page->excerpt,
                    'published_at' => $page->published_at, 'last_seen_at' => $page->last_seen_at,
                    'source_verification_state' => match ($page->state) { 'read' => 'verified', 'unreachable' => 'unreachable', default => 'unverified' },
                    'service_definition_code' => $profile->service_definition_code,
                    'intent_category' => $profile->service_definition_code,
                    'intent_confidence' => $decision['score'], 'purchase_stage' => $decision['stage'],
                    'classification_status' => 'available', 'classification_reason' => __('free_radar.'.$decision['reason']),
                    'negative_signals' => $decision['negatives'], 'identity_status' => 'unknown',
                    'provenance' => ['provider' => 'public_sources', 'method' => 'rules_v1', 'source_id' => $page->sales_radar_source_id,
                        'page_id' => $page->id, 'author' => $page->author, 'service_catalog_item_id' => $profile->service_catalog_item_id,
                        'service_name' => $profile->catalogService?->primaryName?->raw_label ?? $profile->name,
                        'market' => $profile->location, 'market_verified' => false,
                        'score_is_probability' => false, 'source_fetched_at' => $page->fetched_at?->toIso8601String()],
                ])->save();
            }
            $unavailable = SalesRadarSource::query()->where('enabled', true)->where('state', '!=', 'ready')->count();
            $available = SalesRadarSource::query()->where('enabled', true)->where('state', 'ready')->count();
            $run->update([
                'status' => $available === 0 ? 'failed' : (($errors !== [] || $unavailable > 0 || $backlog) ? 'partial' : 'completed'),
                'provider_reality' => $available === 0 ? 'unavailable' : (($unavailable > 0 || $errors !== []) ? 'partial' : 'real'),
                'signal_count' => $created, 'query_count' => $sources->count(), 'finished_at' => now(),
                'error_summary' => ['errors' => $errors, 'unavailable_sources' => $unavailable, 'pending_pages' => $backlog, 'scope' => 'latest_500_candidates'],
            ]);
            $profile->update(['radar_next_at' => now()->addMinutes($backlog ? 5 : $profile->radar_interval_minutes)]);
            app(IntentActivityRecorder::class)->record('intent_run.completed', __('free_radar.run_finished'), $profile, $run,
                metadata: ['created' => $created, 'status' => $run->status->value]);
        } finally {
            $lock->release();
        }
    }

    private function error(Throwable $exception): string
    {
        return $exception instanceof RuntimeException && preg_match('/^[a-z0-9_]+$/', $exception->getMessage())
            ? substr($exception->getMessage(), 0, 150) : 'read_failed';
    }
}
