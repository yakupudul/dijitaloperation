<?php

namespace App\Panel\Services;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Jobs\PushPanelDocumentToDrive;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelDocument;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelTicket;
use App\Panel\Models\PanelTicketMessage;
use App\Panel\Support\PanelContext;
use App\Panel\Support\RichText;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Support requests between a brand and the agency, and the agency's announcements. */
final class PanelSupport
{
    /** File kinds taken on a message or on the shelf: documents, pictures, short videos, archives. */
    public const string FILE_KINDS = 'pdf,jpg,jpeg,png,webp,heic,gif,svg,doc,docx,xls,xlsx,csv,ppt,pptx,txt,zip,rar,mp4,mov,ai,psd,eps';

    public function __construct(private readonly PanelContext $context, private readonly PanelNotifier $notifier, private readonly PanelDrive $drive) {}

    /** A brand's user opens a request; it also lands on the operator's Yapılacaklar. */
    public function open(int $brandId, int $panelUserId, string $subject, string $category, string $body, ?string $path = null, ?string $name = null, array $files = []): PanelTicket
    {
        $ticket = $this->context->system(fn (): PanelTicket => DB::transaction(function () use ($brandId, $panelUserId, $subject, $category, $body, $path, $name, $files): PanelTicket {
            $ticket = new PanelTicket;
            $ticket->brand_id = $brandId;
            $ticket->fill(['panel_user_id' => $panelUserId, 'subject' => trim($subject), 'category' => $category, 'status' => PanelTicket::OPEN, 'last_message_at' => now()])->save();
            $message = $this->message($ticket, $body, $panelUserId, null, $path, $name);
            $this->keep($brandId, $files, $panelUserId, null, $ticket, $message);

            return $ticket;
        }));
        $this->tellOperator($ticket);

        return $ticket;
    }

    /**
     * The operator writes down what a client asked for on the phone or over WhatsApp, so it is followed like any request.
     *
     * @param  list<UploadedFile>  $files
     */
    public function openByOperator(int $brandId, int $operatorId, string $subject, string $category, string $body, string $origin = 'phone', array $files = []): PanelTicket
    {
        return $this->context->system(fn (): PanelTicket => DB::transaction(function () use ($brandId, $operatorId, $subject, $category, $body, $origin, $files): PanelTicket {
            $ticket = new PanelTicket;
            $ticket->brand_id = $brandId;
            $ticket->fill(['subject' => trim($subject), 'category' => $category, 'status' => PanelTicket::OPEN, 'last_message_at' => now()]);
            $ticket->origin = in_array($origin, array_keys(PanelTicket::ORIGINS), true) ? $origin : 'phone';
            $ticket->opened_by_operator_id = $operatorId;
            $ticket->save();
            $message = $this->message($ticket, $body, null, $operatorId);
            $this->keep($brandId, $files, null, $operatorId, $ticket, $message);

            return $ticket;
        }));
    }

    /**
     * Keeps uploaded files for a brand (on a message, or on the shelf when no ticket is given) and sends each to Drive.
     *
     * @param  list<UploadedFile>  $files
     * @return list<PanelDocument>
     */
    public function keep(int $brandId, array $files, ?int $panelUserId, ?int $operatorId, ?PanelTicket $ticket = null, ?PanelTicketMessage $message = null): array
    {
        $kept = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $name = mb_substr((string) $file->getClientOriginalName(), 0, 190);
            $path = $file->store('panel/'.$brandId.'/documents', (string) config('panel.files_disk'));
            $row = new PanelDocument;
            $row->brand_id = $brandId;
            $row->fill(['ticket_id' => $ticket?->id, 'ticket_message_id' => $message?->id, 'panel_user_id' => $panelUserId, 'operator_id' => $operatorId,
                'name' => $name !== '' ? $name : 'dosya', 'mime' => mb_substr((string) $file->getMimeType(), 0, 120), 'size' => (int) $file->getSize(), 'path' => $path]);
            $this->context->system(fn () => $row->save());
            $kept[] = $row;
        }
        if ($kept !== [] && $this->drive->connected()) {
            foreach ($kept as $row) {
                DB::afterCommit(fn () => PushPanelDocumentToDrive::dispatch((int) $row->id));
            }
        }

        return $kept;
    }

    /** The brand's user writes again: the request is open once more. */
    public function replyFromBrand(PanelTicket $ticket, int $panelUserId, string $body, ?string $path = null, ?string $name = null, array $files = []): void
    {
        $this->context->system(function () use ($ticket, $panelUserId, $body, $path, $name, $files): void {
            $message = $this->message($ticket, $body, $panelUserId, null, $path, $name);
            $this->keep((int) $ticket->brand_id, $files, $panelUserId, null, $ticket, $message);
            $ticket->forceFill(['status' => PanelTicket::OPEN, 'last_message_at' => now()])->save();
        });
    }

    /** The agency answers: the one who asked and the brand's managers hear the bell. */
    public function replyFromOperator(PanelTicket $ticket, int $operatorId, string $body, array $files = []): void
    {
        $this->context->system(function () use ($ticket, $operatorId, $body, $files): void {
            $message = $this->message($ticket, $body, null, $operatorId);
            $this->keep((int) $ticket->brand_id, $files, null, $operatorId, $ticket, $message);
            $ticket->forceFill(['status' => PanelTicket::ANSWERED, 'last_message_at' => now()])->save();
        });
        $url = route('panel.support', ['talep' => $ticket->id]);
        $to = PanelMembership::query()->where('brand_id', $ticket->brand_id)->where('is_active', true)
            ->where(fn ($q) => $q->where('is_manager', true)->orWhere('panel_user_id', (int) $ticket->panel_user_id))->pluck('panel_user_id')->unique();
        foreach ($to as $userId) {
            $this->notifier->user((int) $ticket->brand_id, (int) $userId, 'ticket', 'Talebiniz cevaplandı: '.$ticket->subject, $url);
        }
    }

    public function setStatus(PanelTicket $ticket, string $status): void
    {
        abort_unless(isset(PanelTicket::STATUSES[$status]), 422);
        $this->context->system(fn () => $ticket->forceFill(['status' => $status])->save());
    }

    /** Publishes the agency's announcement and rings the bell of every user it is meant for. */
    public function announce(PanelAnnouncement $announcement): int
    {
        $announcement->forceFill(['published_at' => now()])->save();
        $brands = Brand::query()
            ->when($announcement->brand_id, fn ($q) => $q->whereKey($announcement->brand_id))
            ->when(! $announcement->brand_id && $announcement->sector_id, fn ($q) => $q->where('sector_id', $announcement->sector_id))
            ->pluck('id');
        $sent = 0;
        foreach ($brands as $brandId) {
            $sent += $this->notifier->brand((int) $brandId, 'announcement', 'Duyuru: '.$announcement->title, route('panel.announcements'), 'menu.support');
        }

        return $sent;
    }

    /** A message may come as plain text or from the editor; it is kept as clean HTML with its plain text beside it. */
    private function message(PanelTicket $ticket, string $body, ?int $panelUserId, ?int $operatorId, ?string $path = null, ?string $name = null): PanelTicketMessage
    {
        $html = RichText::clean($body);
        $row = new PanelTicketMessage;
        $row->brand_id = $ticket->brand_id;
        $row->fill(['ticket_id' => $ticket->id, 'panel_user_id' => $panelUserId, 'operator_id' => $operatorId, 'body' => RichText::plain($html) ?: trim(strip_tags($body)),
            'body_html' => $html !== '' ? $html : null, 'attachment_path' => $path, 'attachment_name' => $name])->save();

        return $row;
    }

    /** One line on the operator's Yapılacaklar per open request; a failure here never loses the request. */
    private function tellOperator(PanelTicket $ticket): void
    {
        try {
            $operator = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
            if ($operator === null) {
                return;
            }
            $brand = Brand::query()->find($ticket->brand_id);
            $row = app(QuickCapture::class)->capture($operator, 'Panel talebini yanıtla: '.($brand?->name ?? 'Marka').', '.$ticket->subject, route('operator.settings.panel-support', ['talep' => $ticket->id]));
            if ($brand !== null && $row instanceof Reminder && (int) $row->brand_id !== (int) $brand->id) {
                $row->forceFill(['brand_id' => $brand->id, 'customer_id' => $brand->customer_id])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
