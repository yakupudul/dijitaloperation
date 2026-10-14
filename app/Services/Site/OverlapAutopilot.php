<?php

namespace App\Services\Site;

use App\Jobs\Site\PrepareOverlapRewriteJob;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Küme çakışmalarını Claude yürütür (yakup 2026-10-14: "301 ve ayrıştır onarım masasındaki işleri yap. Claude API yapsın
 * o dolarsa sen yap. Yapmadan önce öneri doğru mu değil mi bir kere kontrol et."). Claude reads each overlap over MCP
 * (`cluster-overlaps`), checks it and decides (`overlap-act`) in the Admin's name:
 * - redirect / keep / make_main: the desk buttons ("301 ile birleştir", "Ayrı kalsın", "Ana sayfa bu olsun");
 * - rewrite: "Ayrıştır" — the AI (`site.apply_change`, type differentiate) writes the page's refocused version in the
 *   background; when the API cannot, Claude writes it itself (`submit_rewrite`, same checks);
 * - publish: after Claude read the version, it goes to WordPress (SEO fields at once, the body as a draft copy);
 * - go_live: once WordPress confirmed the draft copy, Claude puts it live ("Canlıya al"). Every step is Claude's
 *   explicit call, never a timer; everything stays undoable on the site log.
 * State of a rewrite: `action.rewrite.state` = preparing | ready | blocked | failed | needs_claude | publishing | live.
 */
final class OverlapAutopilot
{
    public const array DECISIONS = ['redirect', 'keep', 'make_main', 'rewrite', 'submit_rewrite', 'publish', 'go_live'];

    public function __construct(
        private readonly ClusterOverlaps $overlaps,
        private readonly ChangeApplier $changes,
    ) {}

    public static function admin(): ?User
    {
        return User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>|null  $written  submit_rewrite: seo_title, meta_description, html, internal_links, note
     * @return array<int, string> suggestion id → what happened (Turkish)
     */
    public function act(array $ids, string $decision, User $user, ?array $written = null): array
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Bilinmeyen karar: '.$decision]);
        }
        $suggestions = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            if (! $suggestions->has($id)) {
                $out[$id] = 'Çakışma önerisi bulunamadı.';
            }
        }
        if ($decision === 'redirect') {
            return $out + $this->redirect($suggestions->values(), $user);
        }
        foreach ($suggestions as $id => $suggestion) {
            try {
                $out[$id] = match ($decision) {
                    'keep' => $this->keep($suggestion, $user),
                    'make_main' => $this->makeMain($suggestion, $user),
                    'rewrite' => $this->rewrite($suggestion, $user),
                    'submit_rewrite' => $this->submit($suggestion, $written ?? []),
                    'publish' => $this->publish($suggestion, $user),
                    'go_live' => $this->goLive($suggestion, $user),
                };
            } catch (ValidationException $exception) {
                $out[$id] = (string) collect($exception->errors())->flatten()->first();
            } catch (Throwable $exception) {
                $out[$id] = 'Hata: '.mb_substr($exception->getMessage(), 0, 200);
            }
        }

        return $out;
    }

    /** The background "Ayrıştır" step (PrepareOverlapRewriteJob). */
    public function prepare(Suggestion $suggestion): string
    {
        $result = $this->changes->prepare($suggestion);
        $state = match ($result['status']) {
            'ready' => 'ready',
            'blocked' => 'blocked',
            // The API could not write it (no credit, ceiling, provider error): Claude writes it over MCP.
            'no_provider', 'error' => 'needs_claude',
            default => 'failed',
        };
        $this->state($suggestion->fresh() ?? $suggestion, $state, $result['message'] ?? $result['status']);

        return $state;
    }

    /** "Canlıya al" for a rewrite sent with publish, once WordPress confirmed its draft copy. */
    private function goLive(Suggestion $suggestion, User $user): string
    {
        if (data_get($suggestion->action, 'rewrite.state') !== 'publishing') {
            throw ValidationException::withMessages(['decision' => 'Canlıya alınacak taslak yok (durum: '.(data_get($suggestion->action, 'rewrite.state') ?? 'yok').').']);
        }
        $draft = ExternalWriteAction::query()->where('suggestion_id', $suggestion->id)->where('action', ExternalWriteAction::ACTION_CONTENT_DRAFT)->orderByDesc('id')->first();
        if ($draft === null || ! in_array($draft->status, ['succeeded', 'failed'], true)) {
            return 'WordPress taslak kopyası henüz yazılmadı; biraz sonra yeniden dene.';
        }
        if ($draft->status === 'failed') {
            $this->state($suggestion, 'failed', 'WordPress taslak kopyası yazılamadı: '.mb_substr((string) $draft->error, 0, 200));

            return 'Taslak kopyası yazılamadı: '.mb_substr((string) $draft->error, 0, 200);
        }
        $this->changes->publishDraft($suggestion, $user);
        $suggestion->forceFill(['status' => Suggestion::APPLIED])->save();
        $this->state($suggestion, 'live', 'Sayfanın yeni sürümü canlıya gönderildi.');

        return 'Canlıya gönderildi.';
    }

    /**
     * @param  Collection<int, Suggestion>  $suggestions
     * @return array<int, string>
     */
    private function redirect(Collection $suggestions, User $user): array
    {
        $out = [];
        $ready = $suggestions->filter(function (Suggestion $s) use (&$out): bool {
            if (data_get($s->action, 'recommendation') !== ClusterOverlaps::REDIRECT) {
                $out[(int) $s->id] = '301 önerilmiyor (öneri: '.data_get($s->action, 'recommendation').').';

                return false;
            }
            if (! ClusterOverlaps::mergeable($s)) {
                $out[(int) $s->id] = ClusterOverlaps::mergePending($s) ? '301 zaten siteye gönderildi.' : 'Öneri zaten sonuçlandı.';

                return false;
            }

            return true;
        });
        // One site at a time: an old connector or an unreachable site fails only its own pages.
        foreach ($ready->groupBy(fn (Suggestion $s): int => (int) data_get($s->action, 'site_id')) as $group) {
            try {
                $this->overlaps->redirectMany($group, $user);
                foreach ($group as $s) {
                    $out[(int) $s->id] = '301 siteye gönderildi.';
                }
            } catch (ValidationException $exception) {
                foreach ($group as $s) {
                    $out[(int) $s->id] = (string) collect($exception->errors())->flatten()->first();
                }
            } catch (Throwable $exception) {
                foreach ($group as $s) {
                    $out[(int) $s->id] = 'Hata: '.mb_substr($exception->getMessage(), 0, 200);
                }
            }
        }

        return $out;
    }

    private function keep(Suggestion $suggestion, User $user): string
    {
        $this->open($suggestion);
        $this->overlaps->keep($suggestion, $user);

        return 'Ayrı kalsın olarak kapatıldı.';
    }

    private function makeMain(Suggestion $suggestion, User $user): string
    {
        $this->overlaps->makeMain($suggestion, $user);

        return 'Kümenin ana sayfası bu sayfa yapıldı.';
    }

    private function rewrite(Suggestion $suggestion, User $user): string
    {
        $this->open($suggestion);
        if (data_get($suggestion->action, 'recommendation') !== ClusterOverlaps::DIFFERENTIATE) {
            throw ValidationException::withMessages(['decision' => 'Ayrıştır önerilmiyor (öneri: '.data_get($suggestion->action, 'recommendation').').']);
        }
        if (in_array(data_get($suggestion->action, 'rewrite.state'), ['preparing', 'publishing', 'live'], true)) {
            throw ValidationException::withMessages(['decision' => 'Yeniden yazım zaten sürüyor ('.data_get($suggestion->action, 'rewrite.state').').']);
        }
        $this->state($suggestion, 'preparing', 'AI sayfanın ayrıştırılmış sürümünü yazıyor.', ['by' => (int) $user->id]);
        PrepareOverlapRewriteJob::dispatch((int) $suggestion->id, (int) $user->id);

        return 'Ayrıştırma yazımı başladı.';
    }

    /** @param  array<string, mixed>  $written */
    private function submit(Suggestion $suggestion, array $written): string
    {
        $this->open($suggestion);
        $result = $this->changes->prepare($suggestion, [
            'seo_title' => $written['seo_title'] ?? null, 'meta_description' => $written['meta_description'] ?? null, 'html' => $written['html'] ?? null,
            'internal_links' => (array) ($written['internal_links'] ?? []), 'schema_json' => null, 'note' => (string) ($written['note'] ?? ''),
        ]);
        $state = match ($result['status']) {
            'ready' => 'ready',
            'blocked' => 'blocked',
            default => 'failed',
        };
        $this->state($suggestion->fresh() ?? $suggestion, $state, $result['message'] ?? ($state === 'ready' ? 'Claude yazdı.' : $result['status']));

        return match ($state) {
            'ready' => 'Sürüm kontrollerden geçti; "publish" ile gönderilebilir.',
            'blocked' => 'Sektör kuralına takıldı: '.($result['message'] ?? ''),
            default => 'Sürüm doğrulanamadı ('.$result['status'].'); sayfada olmayan sayı / adres ya da değişmeyen metin olabilir.',
        };
    }

    private function publish(Suggestion $suggestion, User $user): string
    {
        $this->open($suggestion);
        if (data_get($suggestion->action, 'rewrite.state') !== 'ready' || data_get($suggestion->action, 'proposal') === null) {
            throw ValidationException::withMessages(['decision' => 'Gönderilecek hazır sürüm yok (durum: '.(data_get($suggestion->action, 'rewrite.state') ?? 'yok').').']);
        }
        $hasBody = filled(data_get($suggestion->action, 'proposal.new.html'));
        $this->changes->approve($suggestion, $user);
        $fresh = $suggestion->fresh() ?? $suggestion;
        $this->state($fresh, $hasBody ? 'publishing' : 'live', $hasBody ? 'SEO alanları gönderildi; metin WordPress\'te taslak kopya, go_live ile canlıya alınır.' : 'SEO alanları siteye gönderildi.', ['by' => (int) $user->id]);

        return $hasBody ? 'Gönderildi; metin WordPress\'te taslak kopya (go_live ile canlıya alınır).' : 'SEO alanları siteye gönderildi.';
    }

    private function open(Suggestion $suggestion): void
    {
        if (! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true)) {
            throw ValidationException::withMessages(['decision' => 'Bu öneri zaten sonuçlandı (durum: '.$suggestion->status.').']);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function state(Suggestion $suggestion, string $state, string $message, array $extra = []): void
    {
        $action = (array) $suggestion->action;
        $action['rewrite'] = array_merge((array) ($action['rewrite'] ?? []), $extra, ['state' => $state, 'message' => mb_substr($message, 0, 300), 'at' => now()->toIso8601String()]);
        $suggestion->forceFill(['action' => $action])->save();
    }
}
