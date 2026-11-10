<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Calendar\QuickCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Hızlı not (yakup 2026-11-10): the browser extension's "+" (any tab, Alt+N). It reads Yapılacaklar, adds one line
 * and ticks an item. The extension carries the user's capture key (users.capture_token) in the Authorization header;
 * renewing the key on Takvim cuts an old copy off. The panel itself never takes this key.
 */
final class QuickCaptureController extends Controller
{
    public function __construct(private readonly QuickCapture $capture) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $board = $this->capture->board((int) $user->id, 5);
        $pick = fn (array $rows): array => array_map(fn (array $r): array => Arr::only($r, ['id', 'kind', 'title', 'time', 'done', 'late', 'brand', 'url']), $rows);

        return response()->json([
            'count' => $board['count'], 'today' => $pick($board['today']), 'late' => $pick($board['late']), 'open' => $pick($board['open']),
            'notes' => array_map(fn (array $r): array => Arr::only($r, ['id', 'title', 'created']), $board['notes']),
            'takvim' => route('operator.calendar'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $text = mb_substr(trim((string) $request->input('text', '')), 0, 5000);
        try {
            $row = $this->capture->capture($user, $text, $request->input('url'));
        } catch (InvalidArgumentException) {
            return response()->json(['ok' => false, 'message' => 'Bir şey yaz.'], 422);
        }

        return response()->json(['ok' => true, 'id' => $row->id, 'message' => QuickCapture::said($row)], 201);
    }

    public function toggle(Request $request, int $reminder): JsonResponse
    {
        $row = $this->capture->toggle((int) $this->user($request)->id, $reminder);

        return response()->json(['ok' => true, 'done' => $row->done_at !== null]);
    }

    /**
     * The extension as a ZIP for "Paketlenmemiş öğe yükle", with this panel's address and the user's key inside.
     */
    public function download(Request $request): Response
    {
        $dir = resource_path('quick-capture-extension');
        $config = 'const MOXDOP = '.json_encode(['base' => rtrim(url('/'), '/'), 'token' => QuickCapture::token($request->user())], JSON_UNESCAPED_SLASHES).";\n";
        $path = tempnam(sys_get_temp_dir(), 'moxdop-ext');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach (['manifest.json', 'popup.html', 'popup.css', 'popup.js'] as $file) {
            $zip->addFile($dir.'/'.$file, 'moxdop-hizli-not/'.$file);
        }
        $icon = public_path('images/app/icon-192.png');
        if (is_file($icon)) {
            $zip->addFile($icon, 'moxdop-hizli-not/icon.png');
        }
        $manifest = (string) file_get_contents($dir.'/manifest.json');
        $host = parse_url(url('/'), PHP_URL_SCHEME).'://'.parse_url(url('/'), PHP_URL_HOST).'/*';
        $zip->addFromString('moxdop-hizli-not/manifest.json', str_replace('__HOST__', $host, $manifest));
        $zip->addFromString('moxdop-hizli-not/config.js', $config);
        $zip->close();

        return response()->download($path, 'moxdop-hizli-not.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }

    private function user(Request $request): User
    {
        $user = QuickCapture::userFor($request->bearerToken());
        abort_if($user === null, 401, 'Anahtar geçersiz; eklentiyi Takvim\'den yeniden indir.');

        return $user;
    }
}
