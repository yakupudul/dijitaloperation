<?php

namespace App\Http\Controllers\Integrations;

use App\Models\Customer;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Excel'e aktar" on the WhatsApp screen: every conversation (or the open one with ?conversation=) as an .xlsx with a
 * "Mesajlar" sheet (one row per message, oldest first within each chat) and a "Görüşmeler" summary sheet.
 */
final class WhatsAppExportController
{
    private const DIRECTIONS = ['incoming' => 'Gelen', 'outgoing' => 'Giden'];

    private const TYPES = [
        'text' => 'Metin', 'image' => 'Fotoğraf', 'audio' => 'Ses', 'video' => 'Video', 'document' => 'Belge',
        'sticker' => 'Çıkartma', 'location' => 'Konum', 'contacts' => 'Kişi kartı', 'gif' => 'GIF',
    ];

    public function __invoke(Request $request, WhatsAppConnection $connection): BinaryFileResponse
    {
        $connection->authorize($request->user());
        $integration = $connection->integration();
        abort_if($integration === null, 404);
        $only = $request->integer('conversation') ?: null;
        $conversations = WhatsAppConversation::query()->where('integration_id', $integration->id)
            ->when($only, fn ($query) => $query->whereKey($only))
            ->orderByDesc('last_message_at')->orderByDesc('id')->get();
        abort_if($only !== null && $conversations->isEmpty(), 404);
        $customers = Customer::query()->whereIn('id', $conversations->pluck('customer_id')->filter()->unique())->pluck('name', 'id');
        $label = fn (WhatsAppConversation $chat): array => [
            (string) ($chat->contact_name ?? ''),
            ctype_digit((string) $chat->contact_id) ? '+'.$chat->contact_id : 'Gizli numara',
        ];
        $time = fn ($at): string => $at ? $at->timezone('Europe/Istanbul')->format('Y-m-d H:i') : '';

        $path = tempnam(sys_get_temp_dir(), 'wa-export');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Mesajlar');
        $writer->addRow(Row::fromValues(['Kişi', 'Numara', 'Müşteri', 'Tarih', 'Yön', 'Tür', 'Mesaj']));
        foreach ($conversations as $chat) {
            [$name, $number] = $label($chat);
            WhatsAppMessage::query()->where('conversation_id', $chat->id)->orderBy('sent_at')->orderBy('id')
                ->each(function (WhatsAppMessage $message) use ($writer, $name, $number, $chat, $customers, $time): void {
                    $writer->addRow(Row::fromValues([
                        $name, $number, (string) ($customers[$chat->customer_id] ?? ''), $time($message->sent_at),
                        self::DIRECTIONS[$message->direction] ?? $message->direction, self::TYPES[$message->message_type] ?? (string) $message->message_type,
                        (string) $message->body,
                    ]));
                }, 1000);
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Görüşmeler');
        $writer->addRow(Row::fromValues(['Kişi', 'Numara', 'Müşteri', 'Son mesaj', 'Son gelen mesaj', 'Mesaj sayısı', 'Mesaj istemiyor']));
        $counts = WhatsAppMessage::query()->whereIn('conversation_id', $conversations->pluck('id'))
            ->selectRaw('conversation_id, count(*) as total')->groupBy('conversation_id')->pluck('total', 'conversation_id');
        foreach ($conversations as $chat) {
            [$name, $number] = $label($chat);
            $writer->addRow(Row::fromValues([
                $name, $number, (string) ($customers[$chat->customer_id] ?? ''), $time($chat->last_message_at),
                $time($chat->last_incoming_at), (int) ($counts[$chat->id] ?? 0), $chat->opted_out_at ? 'Evet' : '',
            ]));
        }
        $writer->close();
        $file = 'whatsapp-'.($only ? 'gorusme-'.$only : 'konusmalar').'-'.now('Europe/Istanbul')->format('Y-m-d').'.xlsx';

        return response()->download($path, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
