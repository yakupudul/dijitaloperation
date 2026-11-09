<?php

namespace App\Mcp\Tools;

use App\Models\MoxiMessage;
use App\Services\Assistant\PushNotifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Moxi işleri: jobs the operator gave Moxi (the panel\'s corner assistant) that it could not do itself, handed to you. action=list shows the waiting ones (id, title, detail, brand, the panel page the operator was on, the last lines of their chat with Moxi for context). Do them like any request of the operator (read data, write drafts, propose changes through the usual tools; nothing outside MoxDOP without the operator\'s click), then action=answer with id and answer (Turkish, short Markdown, links as [Ad](url)); the answer appears in Moxi\'s chat and on the phone. action=answer with failed=true when it cannot be done, saying why. Work them before the AI queue.')]
class MoxiRequests extends Tool
{
    public function handle(Request $request, PushNotifier $push): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['list', 'answer'])],
            'id' => ['required_if:action,answer', 'nullable', 'integer'],
            'answer' => ['required_if:action,answer', 'nullable', 'string', 'min:2', 'max:8000'],
            'failed' => ['nullable', 'boolean'],
        ]);
        if ($validated['action'] === 'list') {
            $rows = MoxiMessage::query()->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::PENDING)->orderBy('id')->limit(20)->get();

            return Response::json(['waiting' => $rows->count(), 'requests' => $rows->map(fn (MoxiMessage $row): array => [
                'id' => $row->id, 'title' => $row->body, 'detail' => data_get($row->meta, 'detail'), 'brand_id' => data_get($row->meta, 'brand_id'),
                'brand' => data_get($row->meta, 'brand'), 'page' => $row->page, 'asked_at' => $row->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'chat' => MoxiMessage::query()->where('user_id', $row->user_id)->whereIn('role', [MoxiMessage::USER, MoxiMessage::MOXI])->where('id', '<', $row->id)
                    ->orderByDesc('id')->limit(6)->get()->reverse()->map(fn (MoxiMessage $line): string => ($line->role === MoxiMessage::USER ? 'Operatör: ' : 'Moxi: ').mb_substr((string) $line->body, 0, 400))->values()->all(),
            ])->all()]);
        }
        $row = MoxiMessage::query()->where('role', MoxiMessage::REQUEST)->find((int) $validated['id']);
        if ($row === null) {
            return Response::error('Moxi işi #'.$validated['id'].' yok.');
        }
        if ($row->status !== MoxiMessage::PENDING) {
            return Response::error('Moxi işi #'.$row->id.' zaten cevaplandı.');
        }
        $failed = (bool) ($validated['failed'] ?? false);
        $row->forceFill(['status' => $failed ? MoxiMessage::FAILED : MoxiMessage::DONE, 'meta' => array_merge((array) $row->meta, ['answer' => trim((string) $validated['answer']), 'answered_at' => now()->toIso8601String()])])->save();
        // A failed one is told in the chat too, as Moxi's line.
        if ($failed) {
            MoxiMessage::query()->create(['user_id' => $row->user_id, 'role' => MoxiMessage::MOXI, 'page' => $row->page,
                'body' => 'Claude "'.$row->body.'" işini yapamadı: '.trim((string) $validated['answer'])]);
        }
        $push->send('moxi-request:'.$row->id, 'Moxi: Claude cevap verdi', mb_substr($row->body.' · '.trim((string) $validated['answer']), 0, 300), 'high', route('operator.dashboard'));

        return Response::text('Moxi işi #'.$row->id.' cevaplandı; operatör Moxi kutusunda görecek.');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'answer'])->description('list | answer')->required(),
            'id' => $schema->integer()->description('The job (answer).'),
            'answer' => $schema->string()->description('What you did or found, Turkish Markdown, links as [Ad](url).'),
            'failed' => $schema->boolean()->description('true when it could not be done (answer says why).'),
        ];
    }
}
