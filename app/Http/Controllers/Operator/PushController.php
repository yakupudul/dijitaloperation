<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Assistant\PushNotifier;
use App\Services\Inbox\InboxAlerts;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phone / browser notifications (Genel işler › Telefona bildirim aç): the VAPID key, subscribe / unsubscribe of this
 * device, the text of the latest push (read by public/sw.js when an empty push arrives) and a test push.
 */
final class PushController extends Controller
{
    /** A push older than this is not shown when a late push wakes the device. */
    public const int LATEST_MINUTES = 60;

    public function key(WebPush $push): JsonResponse
    {
        return response()->json(['key' => $push->publicKey()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['nullable', 'string', 'max:200'],
            'keys.auth' => ['nullable', 'string', 'max:100'],
        ]);
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'] ?? null, 'auth_token' => $data['keys']['auth'] ?? null,
            'device' => Str::limit((string) $request->userAgent(), 150, ''), 'failures' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The newest push (top-level fields, as before) and, since 2026-11-09, `items`: every push newer than `after` (the
     * last one this device showed), oldest first, so two chats that write in the same second both show. Inbox pushes of
     * one chat share a tag (the later one replaces the earlier), and a 'low' one comes silently (night, a chat's update).
     */
    public function latest(Request $request): JsonResponse
    {
        $rows = DB::table('push_notifications')->where('channel', 'browser')->where('created_at', '>=', now()->subMinutes(self::LATEST_MINUTES))
            ->orderByDesc('id')->limit(5)->get(['id', 'dedupe_key', 'title', 'body', 'url', 'severity', 'created_at']);
        if ($rows->isEmpty()) {
            return response()->json(['title' => 'MoxDOP', 'body' => 'Yeni önemli iş var.', 'url' => route('operator.work'), 'tag' => 'moxdop', 'items' => []]);
        }
        $after = (int) $request->query('after', 0);
        $items = $rows->filter(fn ($row): bool => $after > 0 && (int) $row->id > $after)->reverse()->values()->map(fn ($row): array => $this->item($row))->all();

        return response()->json([...$this->item($rows->first()), 'items' => $items]);
    }

    /** @return array<string, mixed> */
    private function item(object $row): array
    {
        $key = (string) $row->dedupe_key;

        return [
            'id' => (int) $row->id, 'title' => $row->title, 'body' => $row->body, 'url' => $row->url ?: route('operator.work'),
            'tag' => str_starts_with($key, InboxAlerts::PREFIX) ? 'inbox-'.InboxAlerts::chatOf($key) : 'moxdop-'.$row->id,
            'severity' => (string) $row->severity, 'silent' => $row->severity === 'low',
            'at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    public function test(Request $request, PushNotifier $notifier): JsonResponse
    {
        $sent = $notifier->browser('test:'.$request->user()->id.':'.now()->format('YmdHis'), 'MoxDOP bildirimi açık',
            'Bu cihaza yalnız önemli işler gelecek: site kapandı, reklam hesabı durdu, kötü yorum gibi.', 'high', route('operator.work'));

        return response()->json(['sent' => $sent]);
    }
}
