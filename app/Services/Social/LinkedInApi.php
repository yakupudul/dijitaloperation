<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * LinkedIn şirket sayfası (Community Management API): the agency's own LinkedIn page gets its posts through the REST
 * Posts API with the token of a page admin. Image, several images (multiImage), video (uploaded in parts, published on
 * a later run once LinkedIn says AVAILABLE). Text is LinkedIn's "little text": reserved characters are escaped and
 * hashtags become real hashtags. Errors are SocialGraphException so the publisher's retry rules apply unchanged.
 */
class LinkedInApi
{
    public const string AUTH_BASE = 'https://www.linkedin.com/oauth/v2';

    public const string API_BASE = 'https://api.linkedin.com/rest';

    /** Renew the token this long before it ends. */
    private const int REFRESH_BEFORE_DAYS = 7;

    /** @return array{id: string, secret: string}|null */
    public static function app(): ?array
    {
        try {
            $setting = AgencySetting::query()->first();
        } catch (Throwable) {
            return null;
        }
        $id = trim((string) ($setting?->social_linkedin_client_id ?? ''));
        $secret = trim((string) ($setting?->social_linkedin_client_secret ?? ''));

        return $id !== '' && $secret !== '' ? ['id' => $id, 'secret' => $secret] : null;
    }

    /**
     * LinkedIn-Version header (YYYYMM). LinkedIn keeps each monthly version about a year; two months back is always
     * live and never "not yet released". config moxdop.linkedin.version pins one when needed.
     */
    public static function version(): string
    {
        $pinned = (string) config('moxdop.linkedin.version', '');

        return preg_match('/^\d{6}$/', $pinned) ? $pinned : now('UTC')->subMonthsNoOverflow(2)->format('Ym');
    }

    public function request(string $token): PendingRequest
    {
        return Http::timeout(60)->acceptJson()->withToken($token)->withHeaders([
            'LinkedIn-Version' => self::version(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }

    /** @param  array<string, mixed>  $query  @return array<string, mixed> */
    public function get(string $path, string $token, array $query = []): array
    {
        return $this->json($this->send(fn () => $this->request($token)->get(self::API_BASE.$path, $query)));
    }

    /** @param  array<string, mixed>  $body */
    public function post(string $path, string $token, array $body): Response
    {
        return $this->send(fn () => $this->request($token)->asJson()->post(self::API_BASE.$path, $body));
    }

    /** Token exchange / refresh at www.linkedin.com. @param  array<string, string>  $form  @return array<string, mixed> */
    public function token(array $form): array
    {
        $app = self::app();
        if ($app === null) {
            throw new SocialGraphException('LinkedIn uygulaması tanımlı değil (Sosyal › Hesaplar).', 'auth');
        }

        return $this->json($this->send(fn () => Http::timeout(30)->acceptJson()->asForm()
            ->post(self::AUTH_BASE.'/accessToken', $form + ['client_id' => $app['id'], 'client_secret' => $app['secret']])));
    }

    /** A token that ends within a week is renewed with the refresh token; returns the usable access token. */
    public function freshToken(SocialAccount $account): string
    {
        $token = (string) $account->access_token;
        $ends = $account->token_expires_at;
        if ($ends === null || $ends->isAfter(now()->addDays(self::REFRESH_BEFORE_DAYS))) {
            return $token;
        }
        $refresh = (string) $account->refresh_token;
        if ($refresh === '') {
            if ($ends->isPast()) {
                throw new SocialGraphException('LinkedIn bağlantısının süresi doldu; Sosyal › Hesaplar › “LinkedIn’i bağla” ile yenileyin.', 'auth');
            }

            return $token;
        }
        $data = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        $new = (string) ($data['access_token'] ?? '');
        if ($new === '') {
            throw new SocialGraphException('LinkedIn bağlantısı yenilenemedi; Hesaplar’dan yeniden bağlayın.', 'auth');
        }
        $account->forceFill([
            'access_token' => $new,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 5184000)),
        ] + (is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? ['refresh_token' => $data['refresh_token']] : []))->save();

        return $new;
    }

    /** @return array{status: string, id?: string, permalink?: ?string, container?: string} */
    public function publish(SocialPost $post, SocialPostTarget $target, SocialAccount $account): array
    {
        $org = (string) ($account->meta['org_urn'] ?? '');
        if ($org === '') {
            throw new SocialGraphException('LinkedIn sayfası seçili değil; Hesaplar’dan yeniden bağlayın.', 'auth');
        }
        $text = trim((string) $post->linkedin_text);
        if ($text === '') {
            throw new SocialGraphException('LinkedIn metni yok.');
        }
        $token = $this->freshToken($account);
        $media = $post->mediaItems();

        $video = null;
        foreach ($media as $item) {
            if ($item['type'] === 'video') {
                $video = $item;
                break;
            }
        }

        if ($post->kind === 'reels' || ($video !== null && $post->kind !== 'carousel')) {
            if ($video === null) {
                throw new SocialGraphException('Video yok.');
            }
            $urn = (string) $target->container_id;
            if ($urn === '') {
                $urn = $this->uploadVideo($video['path'], $org, $token);
            }
            $status = (string) ($this->get('/videos/'.rawurlencode($urn), $token)['status'] ?? 'AVAILABLE');
            if ($status === 'PROCESSING_FAILED') {
                $target->update(['container_id' => null]);

                throw new SocialGraphException('LinkedIn videoyu işleyemedi (biçim ya da süre uygun değil).');
            }
            if ($status !== 'AVAILABLE') {
                return ['status' => 'processing', 'container' => $urn];
            }
            $content = ['media' => ['id' => $urn, 'title' => Str::limit((string) ($post->title ?? 'Moximu'), 190, '')]];
        } else {
            $images = array_values(array_filter($media, fn (array $m): bool => $m['type'] === 'image'));
            $images = array_slice($images, 0, 20);
            $urns = array_map(fn (array $m): string => $this->uploadImage($m['path'], $org, $token), $images);
            $content = match (count($urns)) {
                0 => null,
                1 => ['media' => ['id' => $urns[0], 'altText' => Str::limit((string) ($post->title ?? ''), 4000, '')]],
                default => ['multiImage' => ['images' => array_map(fn (string $u): array => ['id' => $u, 'altText' => ''], $urns)]],
            };
        }

        $body = [
            'author' => $org,
            'commentary' => self::littleText($text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ] + ($content !== null ? ['content' => $content] : []);

        $response = $this->post('/posts', $token, $body);
        $id = (string) ($response->header('x-restli-id') ?: $response->header('x-linkedin-id') ?: '');

        return ['status' => 'published', 'id' => $id, 'permalink' => $id !== '' ? 'https://www.linkedin.com/feed/update/'.$id.'/' : null];
    }

    /**
     * LinkedIn "little text": \ | { } @ [ ] ( ) < > # * _ ~ are reserved and must be escaped, otherwise the post is
     * refused or cut. A #word becomes a real hashtag.
     */
    public static function littleText(string $text): string
    {
        $parts = preg_split('/#([\p{L}\p{N}_]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1
                ? '{hashtag|\#|'.self::escape($part).'}'
                : self::escape($part);
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
    }

    private function uploadImage(string $path, string $org, string $token): string
    {
        $init = $this->json($this->post('/images?action=initializeUpload', $token, ['initializeUploadRequest' => ['owner' => $org]]));
        $url = (string) ($init['value']['uploadUrl'] ?? '');
        $urn = (string) ($init['value']['image'] ?? '');
        if ($url === '' || $urn === '') {
            throw new SocialGraphException('LinkedIn görsel yükleme adresi vermedi.');
        }
        $bytes = Storage::disk('public')->get($path);
        if (! is_string($bytes) || $bytes === '') {
            throw new SocialGraphException('Görsel dosyası okunamadı.');
        }
        $this->send(fn () => Http::timeout(120)->withToken($token)->withBody($bytes, 'application/octet-stream')->put($url));

        return $urn;
    }

    private function uploadVideo(string $path, string $org, string $token): string
    {
        $disk = Storage::disk('public');
        $size = (int) $disk->size($path);
        if ($size <= 0) {
            throw new SocialGraphException('Video dosyası okunamadı.');
        }
        $init = $this->json($this->post('/videos?action=initializeUpload', $token, ['initializeUploadRequest' => [
            'owner' => $org, 'fileSizeBytes' => $size, 'uploadCaptions' => false, 'uploadThumbnail' => false,
        ]]));
        $urn = (string) ($init['value']['video'] ?? '');
        $instructions = (array) ($init['value']['uploadInstructions'] ?? []);
        if ($urn === '' || $instructions === []) {
            throw new SocialGraphException('LinkedIn video yükleme adresi vermedi.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new SocialGraphException('Video dosyası okunamadı.');
        }
        $etags = [];
        try {
            foreach ($instructions as $part) {
                $first = (int) ($part['firstByte'] ?? 0);
                $last = (int) ($part['lastByte'] ?? $size - 1);
                fseek($stream, $first);
                $chunk = (string) stream_get_contents($stream, $last - $first + 1);
                $response = $this->send(fn () => Http::timeout(180)->withToken($token)->withBody($chunk, 'application/octet-stream')->put((string) $part['uploadUrl']));
                $etags[] = trim((string) $response->header('ETag'), '"');
            }
        } finally {
            fclose($stream);
        }
        $this->post('/videos?action=finalizeUpload', $token, ['finalizeUploadRequest' => [
            'video' => $urn, 'uploadToken' => (string) ($init['value']['uploadToken'] ?? ''), 'uploadedPartIds' => $etags,
        ]]);

        return $urn;
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new SocialGraphException('LinkedIn’e ulaşılamadı.', 'transport', 0, $e);
        }
        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();
        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? $body['error_description'] ?? $body['error'] ?? '') : '';
        $message = Str::limit($message !== '' ? $message : 'HTTP '.$status, 300);

        throw match (true) {
            $status === 401 => new SocialGraphException('LinkedIn bağlantısı geçersiz ('.$message.'); Hesaplar’dan yeniden bağlayın.', 'auth', $status),
            $status === 403 => new SocialGraphException('LinkedIn izin vermedi ('.$message.').', 'auth', $status),
            $status === 429 => new SocialGraphException('LinkedIn sınırı doldu; sonra yeniden denenecek.', 'rate', $status),
            $status >= 500 => new SocialGraphException('LinkedIn geçici hata verdi ('.$message.').', 'transport', $status),
            default => new SocialGraphException('LinkedIn: '.$message, 'provider', $status),
        };
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
