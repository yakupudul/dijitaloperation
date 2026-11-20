<?php

namespace App\Panel\Services;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelTicket;
use App\Panel\Models\PanelTicketMessage;
use App\Panel\Support\PanelContext;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Support requests between a brand and the agency, and the agency's announcements. */
final class PanelSupport
{
    public function __construct(private readonly PanelContext $context, private readonly PanelNotifier $notifier) {}

    /** A brand's user opens a request; it also lands on the operator's Yapılacaklar. */
    public function open(int $brandId, int $panelUserId, string $subject, string $category, string $body, ?string $path = null, ?string $name = null): PanelTicket
    {
        $ticket = $this->context->system(fn (): PanelTicket => DB::transaction(function () use ($brandId, $panelUserId, $subject, $category, $body, $path, $name): PanelTicket {
            $ticket = new PanelTicket;
            $ticket->brand_id = $brandId;
            $ticket->fill(['panel_user_id' => $panelUserId, 'subject' => trim($subject), 'category' => $category, 'status' => PanelTicket::OPEN, 'last_message_at' => now()])->save();
            $this->message($ticket, $body, $panelUserId, null, $path, $name);

            return $ticket;
        }));
        $this->tellOperator($ticket);

        return $ticket;
    }

    /** The brand's user writes again: the request is open once more. */
    public function replyFromBrand(PanelTicket $ticket, int $panelUserId, string $body, ?string $path = null, ?string $name = null): void
    {
        $this->context->system(function () use ($ticket, $panelUserId, $body, $path, $name): void {
            $this->message($ticket, $body, $panelUserId, null, $path, $name);
            $ticket->forceFill(['status' => PanelTicket::OPEN, 'last_message_at' => now()])->save();
        });
    }

    /** The agency answers: the one who asked and the brand's managers hear the bell. */
    public function replyFromOperator(PanelTicket $ticket, int $operatorId, string $body): void
    {
        $this->context->system(function () use ($ticket, $operatorId, $body): void {
            $this->message($ticket, $body, null, $operatorId);
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
            $sent += $this->notifier->brand((int) $brandId, 'announcement', 'Duyuru: '.$announcement->title, route('panel.support', ['sekme' => 'duyurular']), 'menu.support');
        }

        return $sent;
    }

    private function message(PanelTicket $ticket, string $body, ?int $panelUserId, ?int $operatorId, ?string $path = null, ?string $name = null): void
    {
        $row = new PanelTicketMessage;
        $row->brand_id = $ticket->brand_id;
        $row->fill(['ticket_id' => $ticket->id, 'panel_user_id' => $panelUserId, 'operator_id' => $operatorId, 'body' => trim($body), 'attachment_path' => $path, 'attachment_name' => $name])->save();
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
