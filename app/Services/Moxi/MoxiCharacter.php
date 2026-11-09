<?php

namespace App\Services\Moxi;

use App\Models\MoxiMessage;
use App\Models\SalesLead;
use App\Models\User;

/**
 * Moxi as a character (yakup 2026-11-09): the speech bubbles over its head now and then (half jokes, half useful
 * things read from the panel, with the screen to open), its rank grown from the work done together, and the notebook
 * of what it learned. Jokes are written here once (no AI call, no cost); useful bubbles come from live counts.
 */
final class MoxiCharacter
{
    /** Ranks by points: an answer is 1 point, a card the operator ran is 3, a job Claude finished is 2. */
    public const array RANKS = [
        1 => ['name' => 'Çırak', 'from' => 0],
        2 => ['name' => 'Samuray', 'from' => 30],
        3 => ['name' => 'Usta', 'from' => 150],
        4 => ['name' => 'Şogun', 'from' => 500],
    ];

    /** Notebook lines Moxi keeps at most (the oldest go first). */
    public const int NOTEBOOK = 80;

    private const array JOKES = [
        'Kılıcımı biledim. Sıra onaylarda mı?',
        'Bir samuray asla acele etmez… ama cevapsız mesaj da bekletmez.',
        'Şapkam Moximu ikonu, biliyor muydun? Çok havalı.',
        'Bugün kaç müşteri kazandık? Ben saymıyorum, kılıcım sayıyor.',
        'Kahve molası mı? Ben çay içerim. Yeşil olanından.',
        'Ustam derdi ki: "Önce ölç, sonra kes, sonra raporla."',
        'Sabır bir samurayın en keskin kılıcıdır. İkincisi de Ctrl+S.',
        'Hiç uyumam sanma, sadece gözlerimi dinlendiriyorum.',
        'Bir klinik, bir site, bir zafer. Sıradaki kim?',
        'Kılıç da klavye de aynı şeyi ister: düzenli bakım.',
        'Reklam bütçesi değil, strateji keser.',
        'Dojoda öğrendiğim ilk şey: önce selam, sonra iş.',
        'Ben buradayım. Sağ alt köşede, her zamanki gibi.',
        'Yüz marka mı? Bir samuray için ısınma turu.',
        'Gizli tekniğim: her işi bir karta yazmak.',
        'Google sıralaması da kılıç gibi: her gün biraz bilenir.',
        'Söyle bakalım, bugün kimi müşteri yapıyoruz?',
        'Bir öneri yazdım mı? Yazdımsa bir göz at derim.',
        'Şapkamın tepesindeki altın düğme süs değil, anten.',
        'Rapor günü yaklaşırken samuraylar bile terler.',
    ];

    private const array NIGHT = [
        'Samuraylar da uyur. Sen de biraz dinlen.',
        'Gece yarısı kod, sabah bug. Benden söylemesi.',
        'Ay doğdu, kılıç kınına. Yarın devam mı?',
    ];

    private const array MORNING = [
        'Günaydın! Bugünün ilk işi ne?',
        'Sabah antrenmanı: önce Gelen kutusu, sonra Satış.',
    ];

    private const array PAGE = [
        '/satis' => ['Her aday bir müşteri adayıdır. Bazıları biraz daha aday.', 'Teklif yazdıysan sonraki adımı da yaz, ben hatırlatırım.'],
        '/gelen-kutusu' => ['Cevapsız mesaj, kınında paslanan kılıç gibidir.', 'Hızlı cevap = sıcak aday.'],
        '/icerik' => ['Yazı uzun, dikkat kısa. Başlık iyi olsun.'],
        '/pazarlama' => ['Huniyi dolduralım, ayda 25 hedefi bekler.'],
        '/takvim' => ['Takvim boşsa ya çok iyisin ya da bir şey unuttun.'],
    ];

    /** @return array{level: int, name: string, points: int, next: ?int, next_name: ?string} */
    public function rank(int $userId): array
    {
        $base = MoxiMessage::query()->where('user_id', $userId);
        $points = (clone $base)->where('role', MoxiMessage::MOXI)->count()
            + 3 * (clone $base)->where('role', MoxiMessage::CARD)->where('status', MoxiMessage::DONE)->count()
            + 2 * (clone $base)->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::DONE)->count();
        $level = 1;
        foreach (self::RANKS as $at => $rank) {
            if ($points >= $rank['from']) {
                $level = $at;
            }
        }
        $next = self::RANKS[$level + 1] ?? null;

        return ['level' => $level, 'name' => self::RANKS[$level]['name'], 'points' => $points, 'next' => $next['from'] ?? null, 'next_name' => $next['name'] ?? null];
    }

    /** Cards the operator ran with Moxi (milestones are told in a bubble). */
    public function doneCards(int $userId): int
    {
        return MoxiMessage::query()->where('user_id', $userId)->where('role', MoxiMessage::CARD)->where('status', MoxiMessage::DONE)->count();
    }

    /**
     * One bubble for now: a useful one from the panel when there is something (about every other time), otherwise a
     * joke that fits the screen and the hour.
     *
     * @return array{text: string, url: ?string, kind: string}
     */
    public function bubble(User $user, ?string $page = null, ?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $useful = $this->useful($user);
        if ($useful !== [] && $seed % 2 === 0) {
            $pick = $useful[intdiv($seed, 2) % count($useful)];

            return $pick + ['kind' => 'tip'];
        }
        $hour = (int) now('Europe/Istanbul')->format('G');
        $path = (string) (parse_url((string) $page, PHP_URL_PATH) ?? '');
        $pool = self::JOKES;
        foreach (self::PAGE as $prefix => $lines) {
            if (str_starts_with($path, $prefix)) {
                $pool = [...$lines, ...$lines, ...$pool];
            }
        }
        if ($hour >= 23 || $hour < 5) {
            $pool = [...self::NIGHT, ...self::NIGHT, ...$pool];
        } elseif ($hour >= 7 && $hour < 10) {
            $pool = [...self::MORNING, ...$pool];
        }

        return ['text' => $pool[$seed % count($pool)], 'url' => null, 'kind' => 'joke'];
    }

    /** @return list<array{text: string, url: ?string}> things worth saying now, read from the panel */
    private function useful(User $user): array
    {
        $out = [];
        $late = SalesLead::query()->open()->whereNotNull('next_step_at')->where('next_step_at', '<', now())->count();
        if ($late > 0) {
            $out[] = ['text' => 'Satış\'ta '.$late.' kaydın yapılacağı gecikti. Bir bakalım mı?', 'url' => route('operator.sales')];
        }
        foreach (app(MoxiTools::class)->waiting() as $row) {
            if (($row['count'] ?? 0) > 0) {
                $out[] = ['text' => $row['label'].': '.$row['count'].' iş seni bekliyor.', 'url' => $row['url']];
            }
        }
        $cards = MoxiMessage::query()->where('user_id', $user->id)->where('role', MoxiMessage::CARD)->where('status', MoxiMessage::PENDING)->count();
        if ($cards > 0) {
            $out[] = ['text' => 'Kutumda Yap dememi bekleyen '.$cards.' kart var.', 'url' => null];
        }
        $note = MoxiMessage::query()->where('user_id', $user->id)->where('role', MoxiMessage::NOTE)->where('status', MoxiMessage::DONE)->inRandomOrder()->value('body');
        if ($note !== null) {
            $out[] = ['text' => 'Defterimde yazıyor: '.$note, 'url' => null];
        }

        return array_slice($out, 0, 6);
    }

    /**
     * Writes a line in Moxi's notebook (what the operator told it, a preference, or a correction it learned).
     */
    public function learn(User $user, string $text, string $kind, ?string $page = null): ?MoxiMessage
    {
        $text = trim(mb_substr($text, 0, 300));
        if ($text === '') {
            return null;
        }
        $same = MoxiMessage::query()->where('user_id', $user->id)->where('role', MoxiMessage::NOTE)->where('status', MoxiMessage::DONE)
            ->get()->first(fn (MoxiMessage $row): bool => mb_strtolower((string) $row->body) === mb_strtolower($text));
        if ($same !== null) {
            return $same;
        }
        $note = MoxiMessage::query()->create(['user_id' => $user->id, 'role' => MoxiMessage::NOTE, 'status' => MoxiMessage::DONE, 'body' => $text,
            'meta' => ['kind' => in_array($kind, ['bilgi', 'tercih', 'ders'], true) ? $kind : 'bilgi'], 'page' => $page, 'hidden' => true]);
        $old = MoxiMessage::query()->where('user_id', $user->id)->where('role', MoxiMessage::NOTE)->where('status', MoxiMessage::DONE)
            ->orderByDesc('id')->skip(self::NOTEBOOK)->take(100)->pluck('id');
        if ($old->isNotEmpty()) {
            MoxiMessage::query()->whereIn('id', $old)->update(['status' => MoxiMessage::CANCELLED]);
        }

        return $note;
    }

    /** @return list<array{id: int, text: string, kind: string}> */
    public function notebook(int $userId): array
    {
        return MoxiMessage::query()->where('user_id', $userId)->where('role', MoxiMessage::NOTE)->where('status', MoxiMessage::DONE)
            ->orderBy('id')->get()->map(fn (MoxiMessage $row): array => ['id' => $row->id, 'text' => (string) $row->body, 'kind' => (string) data_get($row->meta, 'kind', 'bilgi')])->all();
    }
}
