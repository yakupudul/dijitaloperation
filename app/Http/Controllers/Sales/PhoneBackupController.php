<?php

namespace App\Http\Controllers\Sales;

use App\Models\WhatsAppBackupLine;
use App\Services\Sales\PhoneBackupSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * A very small WebDAV folder for the phone (FolderSync): it lists one folder, takes a PUT and answers the few other
 * verbs a sync app sends. Nothing is ever served back (the backup stays private); the password is the backup row's
 * phone-sync password. Large files are limited by the web server's request size (nginx client_max_body_size).
 */
final class PhoneBackupController
{
    public function __invoke(Request $request, PhoneBackupSync $sync, ?string $path = null): Response
    {
        $method = strtoupper($request->getMethod());
        if ($method === 'OPTIONS') {
            return $this->reply(200, '', ['Allow' => 'OPTIONS, GET, HEAD, PUT, PROPFIND, MKCOL, DELETE, MOVE, LOCK, UNLOCK', 'DAV' => '1, 2']);
        }
        $line = $sync->authenticate($request);
        if ($line === null) {
            return $this->reply(401, 'Yetki gerekli.', ['WWW-Authenticate' => 'Basic realm="MoxDOP telefon yedegi"']);
        }
        $path = trim((string) $path, '/');

        return match ($method) {
            'PROPFIND' => $this->propfind($request, $sync, $line, $path),
            'PUT' => $this->put($request, $sync, $line, $path),
            'MKCOL', 'LOCK' => $this->reply($method === 'LOCK' ? 200 : 201, $method === 'LOCK' ? $this->lockBody() : '', $method === 'LOCK' ? ['Lock-Token' => '<opaquelocktoken:moxdop>', 'Content-Type' => 'application/xml; charset=utf-8'] : []),
            'UNLOCK', 'DELETE' => $this->reply(204),
            'MOVE' => $this->reply(201),
            'HEAD', 'GET' => $this->head($sync, $line, $path),
            default => $this->reply(405, 'Desteklenmeyen istek.', ['Allow' => 'OPTIONS, GET, HEAD, PUT, PROPFIND, MKCOL, DELETE, MOVE, LOCK, UNLOCK']),
        };
    }

    private function propfind(Request $request, PhoneBackupSync $sync, WhatsAppBackupLine $line, string $path): Response
    {
        $base = '/api/telefon-yedek/';
        $last = $sync->lastReceived($line);
        $entries = [];
        if ($path === '' || $path === 'Databases') {
            $entries[] = $this->entry($base.($path === '' ? '' : $path.'/'), null, now()->toRfc7231String());
            if ((string) $request->header('Depth', '1') !== '0') {
                if ($path === '') {
                    $entries[] = $this->entry($base.'Databases/', null, now()->toRfc7231String());
                }
                if ($last !== null && isset($last['name'])) {
                    $entries[] = $this->entry($base.($path === '' ? '' : $path.'/').$last['name'], (int) ($last['size'] ?? 0), $this->lastModified($last));
                }
            }
        } elseif ($last !== null && basename($path) === ($last['name'] ?? null)) {
            $entries[] = $this->entry($base.$path, (int) ($last['size'] ?? 0), $this->lastModified($last));
        } else {
            return $this->reply(404);
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">'.implode('', $entries).'</D:multistatus>';

        return $this->reply(207, $xml, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function entry(string $href, ?int $size, string $modified): string
    {
        $href = htmlspecialchars($href, ENT_XML1);
        $modified = htmlspecialchars($modified, ENT_XML1);
        $type = $size === null ? '<D:resourcetype><D:collection/></D:resourcetype>' : '<D:resourcetype/><D:getcontentlength>'.$size.'</D:getcontentlength><D:getcontenttype>application/octet-stream</D:getcontenttype>';

        return '<D:response><D:href>'.$href.'</D:href><D:propstat><D:prop>'.$type.'<D:getlastmodified>'.$modified.'</D:getlastmodified><D:displayname>'.htmlspecialchars(basename(rtrim($href, '/')) ?: 'telefon-yedek', ENT_XML1).'</D:displayname></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
    }

    private function lastModified(array $last): string
    {
        try {
            return CarbonImmutable::parse((string) ($last['at'] ?? 'now'))->toRfc7231String();
        } catch (\Throwable) {
            return now()->toRfc7231String();
        }
    }

    private function put(Request $request, PhoneBackupSync $sync, WhatsAppBackupLine $line, string $path): Response
    {
        $name = basename($path) ?: 'msgstore.db.crypt15';
        if (! preg_match('/\.crypt1[0-9]$|\.db$|\.tmp$|\.part$/iu', $name) && str_contains($name, '.')) {
            // FolderSync may name its temporary upload freely; a clearly foreign file (an image) is refused.
            if (preg_match('/\.(jpe?g|png|gif|mp4|opus|pdf|vcf|txt)$/iu', $name)) {
                return $this->reply(415, 'Yalnız WhatsApp yedeği (msgstore.db.crypt15) alınır.');
            }
        }
        $temp = storage_path('app/private/whatsapp-backups/phone-'.$line->id.'-'.uniqid('', true).'.tmp');
        File::ensureDirectoryExists(dirname($temp), 0700);
        $in = fopen('php://input', 'rb');
        $out = fopen($temp, 'wb');
        if ($in === false || $out === false) {
            return $this->reply(500, 'Dosya yazılamadı.');
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        try {
            // The body can also arrive through the framework (tests, some servers): fall back to the request content.
            clearstatcache(true, $temp);
            if ((int) @filesize($temp) === 0 && $request->getContent() !== '') {
                file_put_contents($temp, $request->getContent());
            }
            $result = $sync->receive($line, $temp, $name);
        } finally {
            @unlink($temp);
        }

        return $this->reply($result['status'], $result['message']);
    }

    private function head(PhoneBackupSync $sync, WhatsAppBackupLine $line, string $path): Response
    {
        $last = $sync->lastReceived($line);
        if ($path === '' || $path === 'Databases') {
            return $this->reply(200);
        }
        if ($last !== null && basename($path) === ($last['name'] ?? null)) {
            // Known by size only: the backup itself is never served back.
            return $this->reply(200, '', ['Content-Length' => (string) (int) ($last['size'] ?? 0), 'Content-Type' => 'application/octet-stream']);
        }

        return $this->reply(404);
    }

    private function lockBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><D:prop xmlns:D="DAV:"><D:lockdiscovery><D:activelock><D:locktype><D:write/></D:locktype><D:lockscope><D:exclusive/></D:lockscope><D:depth>0</D:depth><D:timeout>Second-600</D:timeout><D:locktoken><D:href>opaquelocktoken:moxdop</D:href></D:locktoken></D:activelock></D:lockdiscovery></D:prop>';
    }

    private function reply(int $status, string $body = '', array $headers = []): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', ...$headers]);
    }
}
