<?php

namespace App\Services\Site\Backlinks;

use App\Mail\LinkOutreachMail;
use App\Models\BacklinkSource;
use App\Models\LinkMessage;
use App\Services\Operator\OperatorMailConfigService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-posta ve takip (Backlink hizmeti mimarisi, katman 4): a ready row's e-mail leaves from the agency's mail settings
 * (Ayarlar › E-posta) only when the operator clicks "Gönder" or "Hepsini gönder"; nothing is sent by a schedule.
 * FOLLOW_UP_DAYS after the first mail with no link, one short follow-up is prepared (again sent by a click); a row with
 * no answer GIVE_UP_DAYS after the first mail leaves the desk by itself, so the month's room goes to another source.
 */
final class LinkMailer
{
    public const int GIVE_UP_DAYS = 21;

    public const int FORM_GIVE_UP_DAYS = 45;

    public function __construct(private readonly OperatorMailConfigService $mail, private readonly LinkDesk $desk) {}

    public function canSend(): bool
    {
        try {
            return $this->mail->operatorSmtpIsComplete() || $this->mail->deploymentMailerCanSend();
        } catch (Throwable) {
            return false;
        }
    }

    /** The address a row's mail goes to: the one the operator typed, else the pool's. */
    public static function recipient(BacklinkSource $row): ?string
    {
        $to = trim((string) (data_get($row->material, 'to') ?: $row->pool?->contact_email));

        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false ? $to : null;
    }

    /** Whether the row has a first mail to send now. */
    public static function mailReady(BacklinkSource $row): bool
    {
        return $row->status === BacklinkSource::NONE && $row->material_status === 'ready' && trim((string) data_get($row->material, 'email_body')) !== ''
            && self::recipient($row) !== null;
    }

    /** Whether the row waits for its one follow-up. */
    public static function followUpDue(BacklinkSource $row): bool
    {
        return $row->status === BacklinkSource::APPLIED && $row->followup_at === null && $row->sent_at !== null
            && $row->sent_at->lt(now()->subDays(BacklinkSource::FOLLOW_UP_DAYS)) && self::recipient($row) !== null
            && $row->messages->contains(fn (LinkMessage $m): bool => $m->kind === 'ilk' && $m->sent_at !== null);
    }

    /** The follow-up text, by rule: short, polite, the same ask. */
    public static function followUp(BacklinkSource $row): array
    {
        $first = $row->messages()->where('kind', 'ilk')->whereNotNull('sent_at')->latest('id')->first();
        $date = $first?->sent_at?->timezone('Europe/Istanbul')->format('d.m.Y') ?? '';
        $brand = (string) $row->brand?->name;

        return [
            'subject' => 'Ynt: '.($first?->subject ?? ($row->name.' hakkında')),
            'body' => "Merhaba,\n\n".$date." tarihinde size ".$brand." için yazmıştım. Yoğunluğunuz arasında gözden kaçmış olabilir diye kısaca hatırlatmak istedim. "
                ."Uygun görürseniz dönüşünüzü memnuniyetle bekleriz; uygun değilse de haber vermeniz yeterli.\n\nTeşekkürler,\n".$brand.' adına',
        ];
    }

    /** @return string|null error */
    public function send(BacklinkSource $row, string $kind, ?int $userId): ?string
    {
        $to = self::recipient($row);
        if ($to === null) {
            return 'Alıcı e-posta adresi yok.';
        }
        if (! $this->canSend()) {
            return 'E-posta ayarı yok (Ayarlar › E-posta). "Postada aç" ile kendi postanızdan gönderebilirsiniz.';
        }
        [$subject, $body] = $kind === 'takip'
            ? array_values(self::followUp($row))
            : [trim((string) data_get($row->material, 'email_subject')) ?: $row->name, trim((string) data_get($row->material, 'email_body'))];
        $message = LinkMessage::query()->create(['backlink_source_id' => $row->id, 'kind' => $kind, 'to' => $to, 'subject' => mb_substr($subject, 0, 200), 'body' => $body, 'sent_by' => $userId]);
        try {
            $this->mail->applyToRuntime();
            Mail::to($to)->send(new LinkOutreachMail($subject, $body));
        } catch (Throwable $error) {
            Log::warning('Link outreach mail failed.', ['row' => $row->id, 'error' => $error->getMessage()]);
            $message->forceFill(['error' => mb_substr($error->getMessage(), 0, 500)])->save();

            return 'Gönderilemedi: '.mb_substr($error->getMessage(), 0, 160);
        }
        $message->forceFill(['sent_at' => now()])->save();
        if ($kind === 'takip') {
            $row->forceFill(['followup_at' => now(), 'next_step_at' => now()->addDays(LinkMailer::GIVE_UP_DAYS - BacklinkSource::FOLLOW_UP_DAYS)])->save();
        } else {
            $this->desk->sent($row);
        }

        return null;
    }

    /**
     * Rows that brought nothing leave the desk so the month's room goes to another source: an e-mailed row
     * GIVE_UP_DAYS after the first mail, a form / listing application FORM_GIVE_UP_DAYS after it was sent (directories
     * approve slowly).
     */
    public static function giveUp(): int
    {
        $drop = fn ($q, string $note): int => $q->update(['status' => BacklinkSource::DROPPED, 'next_step_at' => null, 'note' => $note, 'updated_at' => now()]);
        $mailed = fn ($q) => $q->whereNotNull('sent_at');
        $base = fn () => BacklinkSource::query()->where('status', BacklinkSource::APPLIED);

        return $drop($base()->where('sent_at', '<', now()->subDays(self::GIVE_UP_DAYS))->whereHas('messages', $mailed), 'Cevap gelmedi ('.self::GIVE_UP_DAYS.' gün)')
            + $drop($base()->where('sent_at', '<', now()->subDays(self::FORM_GIVE_UP_DAYS))->whereDoesntHave('messages', $mailed), 'Başvuru '.self::FORM_GIVE_UP_DAYS.' günde sonuç vermedi');
    }
}
