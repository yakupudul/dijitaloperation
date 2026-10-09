<?php

namespace App\Services\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpPhoto;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fotoğraflar (ADR-079): profiles with fresh, real photos rank and convert better. Per location the desk shows what the
 * profile has (photo count, last photo, logo / cover) and proposes photos from the brand's own WordPress media
 * (JPG / PNG, large enough, service-page images first), or the operator uploads branch photos in MoxDOP. The Admin
 * sends the chosen ones; Google fetches each file from its https address. A photo goes to a profile once; one already
 * sent to another branch of the brand is marked so branches do not all show the same pictures; a picture whose file
 * name / title names another branch's district is not offered to this one.
 */
final class PhotoPlan
{
    public const int MIN_SIDE = 480;

    public const int MAX_BYTES = 5_000_000;

    public const int CANDIDATES = 24;

    /** A profile whose newest photo is older than this needs one. */
    public const int STALE_DAYS = 30;

    public const array CATEGORIES = ['ADDITIONAL' => 'Genel', 'EXTERIOR' => 'Dış mekân', 'INTERIOR' => 'İç mekân', 'AT_WORK' => 'İş başında', 'TEAMS' => 'Ekip', 'PRODUCT' => 'Ürün / hizmet', 'LOGO' => 'Logo', 'COVER' => 'Kapak'];

    public function __construct(private readonly GbpDesk $desk) {}

    /**
     * What each profile has on Google (from the collected media).
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return array<int, array{photos: int, last: ?string, days: ?int, logo: bool, cover: bool, stale: bool}>
     */
    public function status(array $resources): array
    {
        $rows = DB::table('gbp_media')->whereIn('external_resource_id', array_values($resources))->where(fn ($q) => $q->whereNull('media_format')->orWhere('media_format', 'PHOTO'))
            ->get(['external_resource_id', 'category', 'create_time'])->groupBy('external_resource_id');
        $sent = GbpPhoto::query()->whereIn('digital_asset_id', array_keys($resources))->where('status', GbpPhoto::UPLOADED)
            ->selectRaw('digital_asset_id, max(updated_at) as last')->groupBy('digital_asset_id')->pluck('last', 'digital_asset_id');
        $out = [];
        foreach ($resources as $assetId => $resourceId) {
            $media = $rows->get($resourceId, collect());
            $last = collect([$media->max('create_time'), $sent->get($assetId)])->filter()->map(fn ($d): string => substr((string) $d, 0, 10))->max();
            $days = $last !== null ? (int) CarbonImmutable::parse($last)->diffInDays(now(), true) : null;
            $categories = $media->pluck('category')->map(fn ($c): string => strtoupper((string) $c));
            $out[$assetId] = ['photos' => $media->count(), 'last' => $last, 'days' => $days, 'logo' => $categories->contains('LOGO'), 'cover' => $categories->contains('COVER'),
                'stale' => $days === null || $days > self::STALE_DAYS];
        }

        return $out;
    }

    /**
     * Photos from the brand's WordPress media that this profile does not have yet, best first.
     *
     * @return list<array{url: string, title: string, width: ?int, height: ?int, category: string, page: ?string, used_by: int}>
     */
    public function candidates(DigitalAsset $location): array
    {
        $sites = DigitalAsset::query()->where('brand_id', $location->brand_id)->where('type', 'website')->pluck('id');
        if ($sites->isEmpty()) {
            return [];
        }
        $snapshots = DB::table('website_cms_object_snapshot')->whereIn('digital_asset_id', $sites)->whereIn('object_type', ['attachment', 'page', 'post'])
            ->orderByDesc('id')->limit(5000)->get(['digital_asset_id', 'object_type', 'object_id', 'parent_id', 'permalink', 'title', 'featured_media_id', 'metadata', 'slug']);
        $servicePaths = DB::table('pages')->whereIn('website_asset_id', $sites)->where('category', 'hizmet')->whereNotNull('wp_post_id')->pluck('title', 'wp_post_id');
        $featured = [];
        foreach ($snapshots->whereIn('object_type', ['page', 'post']) as $row) {
            if ($row->featured_media_id !== null) {
                $featured[$row->digital_asset_id.':'.$row->featured_media_id] = (string) $row->object_id;
            }
        }
        $own = GbpPhoto::query()->where('digital_asset_id', $location->id)->whereIn('status', [GbpPhoto::SENDING, GbpPhoto::UPLOADED])->pluck('source_hash')->flip();
        $siblings = GbpPhoto::query()->where('brand_id', $location->brand_id)->where('digital_asset_id', '!=', $location->id)->where('status', GbpPhoto::UPLOADED)
            ->selectRaw('source_hash, count(*) as n')->groupBy('source_hash')->pluck('n', 'source_hash');
        $otherDistricts = $this->siblingDistricts($location);
        $seen = [];
        $list = [];
        foreach ($snapshots->where('object_type', 'attachment') as $row) {
            $url = trim((string) $row->permalink);
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $mime = strtolower((string) ($meta['mime_type'] ?? ''));
            $width = isset($meta['width']) ? (int) $meta['width'] : null;
            $height = isset($meta['height']) ? (int) $meta['height'] : null;
            $bytes = isset($meta['file_size']) ? (int) $meta['file_size'] : null;
            $hash = hash('sha256', $url);
            if ($url === '' || isset($seen[$hash]) || isset($own[$hash]) || preg_match('~^https://\S+\.(jpe?g|png)$~i', $url) !== 1
                || ($mime !== '' && ! in_array($mime, ['image/jpeg', 'image/png'], true))
                || ($width !== null && $width < self::MIN_SIDE) || ($height !== null && $height < self::MIN_SIDE)
                || ($bytes !== null && ($bytes < 10_000 || $bytes > self::MAX_BYTES))
                || self::namesAny($url.' '.$row->title, $otherDistricts)) {
                continue;
            }
            $seen[$hash] = true;
            $parent = $featured[$row->digital_asset_id.':'.$row->object_id] ?? (string) ($row->parent_id ?? '');
            $service = $parent !== '' && isset($servicePaths[$parent]);
            $list[] = [
                'url' => $url, 'title' => (string) ($row->title ?: basename($url)), 'width' => $width, 'height' => $height,
                'category' => self::guessCategory($url.' '.$row->title), 'page' => $service ? (string) $servicePaths[$parent] : null,
                'used_by' => (int) ($siblings[$hash] ?? 0),
                'rank' => ($service ? 0 : 2) + (isset($featured[$row->digital_asset_id.':'.$row->object_id]) ? 0 : 1) + ((int) ($siblings[$hash] ?? 0) > 0 ? 4 : 0),
                'size' => ($width ?? 0) * ($height ?? 0),
            ];
        }
        usort($list, fn (array $a, array $b): int => [$a['rank'], -$a['size']] <=> [$b['rank'], -$b['size']]);

        return array_map(fn (array $c): array => array_diff_key($c, ['rank' => 1, 'size' => 1]), array_slice($list, 0, self::CANDIDATES));
    }

    /**
     * The photo's kind from its file name / title, whole words only ("ekipman" is not "ekip", "kombinasyon" not "bina").
     */
    public static function guessCategory(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $word = static fn (string $words): string => '/(?<![a-z])('.$words.')(?:s|ler|lar|imiz|umuz|lerimiz|larimiz)?(?![a-z])/';

        return match (true) {
            preg_match($word('logo'), $text) === 1 => 'LOGO',
            preg_match($word('ekip|ekibimiz|team|doktor|hekim|kadro|staff'), $text) === 1 => 'TEAMS',
            preg_match($word('dis[-_ ]?cephe|bina|binasi|exterior|tabela|giris|facade'), $text) === 1 => 'EXTERIOR',
            preg_match($word('ic[-_ ]?mekan|bekleme|interior|resepsiyon|lobi|salon|oda|odasi'), $text) === 1 => 'INTERIOR',
            default => 'ADDITIONAL',
        };
    }

    /**
     * Folded districts of the brand's other profiles that are not this profile's own (a photo named after another
     * branch is that branch's photo).
     *
     * @return list<string>
     */
    private function siblingDistricts(DigitalAsset $location): array
    {
        $locations = $this->desk->locations((int) $location->brand_id);
        if ($locations->count() <= 1) {
            return [];
        }
        $snapshots = $this->desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $address = (array) ($snapshots[$location->id]['address'] ?? []);
        $own = array_filter([SeoText::fold((string) ($address['sublocality'] ?? '')), SeoText::fold((string) ($address['locality'] ?? ''))]);
        $out = [];
        foreach ($locations as $sibling) {
            $district = SeoText::fold((string) ($snapshots[$sibling->id]['address']['sublocality'] ?? ''));
            if ((int) $sibling->id !== (int) $location->id && $district !== '' && ! in_array($district, $own, true)) {
                $out[$district] = $district;
            }
        }

        return array_values($out);
    }

    /** @param  list<string>  $phrases  folded */
    private static function namesAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (SeoText::containsPhrase($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin: sends the chosen photos to one profile (each its own write; at most 10 per click).
     *
     * @param  list<array{url: string, category?: string, title?: string, source?: string}>  $photos
     */
    public function send(User $user, DigitalAsset $location, array $photos): int
    {
        $this->guard($user);
        if ($photos === [] || count($photos) > 10) {
            throw ValidationException::withMessages(['photos' => 'Bir seferde 1–10 fotoğraf seçin.']);
        }
        $sent = 0;
        foreach ($photos as $photo) {
            $url = trim((string) ($photo['url'] ?? ''));
            $hash = hash('sha256', $url);
            if (GbpPhoto::query()->where('digital_asset_id', $location->id)->where('source_hash', $hash)->whereIn('status', [GbpPhoto::SENDING, GbpPhoto::UPLOADED])->exists()) {
                continue;
            }
            $category = isset(self::CATEGORIES[$photo['category'] ?? '']) ? (string) $photo['category'] : 'ADDITIONAL';
            $row = GbpPhoto::query()->updateOrCreate(['digital_asset_id' => $location->id, 'source_hash' => $hash], [
                'brand_id' => $location->brand_id, 'source_url' => $url, 'source' => ($photo['source'] ?? 'site') === 'upload' ? 'upload' : 'site', 'category' => $category,
                'title' => mb_substr((string) ($photo['title'] ?? ''), 0, 200), 'status' => GbpPhoto::SENDING, 'created_by' => $user->id, 'external_write_action_id' => null,
            ]);
            try {
                $action = app(ExternalWriteService::class)->requestPhoto($user, $location, $url, $category, (int) $row->id);
            } catch (ValidationException $exception) {
                $row->delete();

                throw $exception;
            }
            GbpPhoto::query()->whereKey($row->id)->update(['external_write_action_id' => $action->id]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Admin: one fresh photo to every profile of the brand whose newest photo is older than 30 days (its best candidate
     * that no other branch has).
     *
     * @return array{sent: int, skipped: int}
     */
    public function sendMonthly(User $user, int $brandId): array
    {
        $this->guard($user);
        $locations = $this->desk->locations($brandId);
        $resources = app(GbpDailyWorkspace::class)->resourceIds($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $status = $this->status($resources);
        $sent = 0;
        $skipped = 0;
        foreach ($locations as $location) {
            if (! ($status[$location->id]['stale'] ?? false)) {
                continue;
            }
            $pick = collect($this->candidates($location))->first(fn (array $c): bool => $c['used_by'] === 0 && ! in_array($c['category'], ['LOGO', 'COVER'], true));
            if ($pick === null) {
                $skipped++;

                continue;
            }
            $sent += $this->send($user, $location, [['url' => $pick['url'], 'category' => $pick['category'], 'title' => $pick['title']]]);
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** Operator upload (branch photos the site does not have): stored publicly so Google can fetch it, then sent. */
    public function upload(User $user, DigitalAsset $location, UploadedFile $file, string $category): int
    {
        $this->guard($user);
        $extension = strtolower($file->getClientOriginalExtension() === 'png' ? 'png' : 'jpg');
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png'], true) || $file->getSize() > self::MAX_BYTES || $file->getSize() < 10_000) {
            throw ValidationException::withMessages(['photos' => 'JPG ya da PNG, 10 KB – 5 MB arası bir fotoğraf seçin.']);
        }
        [$width, $height] = @getimagesize((string) $file->getRealPath()) ?: [0, 0];
        if ($width < 250 || $height < 250) {
            throw ValidationException::withMessages(['photos' => 'Fotoğraf en az 250 × 250 piksel olmalı (önerilen 720 × 720).']);
        }
        $path = $file->storeAs('gbp-photos/'.$location->id, Str::uuid().'.'.$extension, 'public');
        $url = Storage::disk('public')->url((string) $path);
        if (! str_starts_with($url, 'https://')) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/');
        }

        return $this->send($user, $location, [['url' => $url, 'category' => $category, 'title' => $file->getClientOriginalName(), 'source' => 'upload']]);
    }

    /** Keeps the photo row in step with its write (uploaded / failed / removed by undo). */
    public function writeFinished(ExternalWriteAction $action): void
    {
        $row = GbpPhoto::query()->where('external_write_action_id', $action->id)->first()
            ?? GbpPhoto::query()->where('digital_asset_id', $action->digital_asset_id)->find((int) data_get($action->request_payload, 'photo_id'));
        if ($row === null) {
            return;
        }
        $row->forceFill(['status' => match ($action->status) {
            'succeeded', 'partial', 'undo_failed' => GbpPhoto::UPLOADED,
            'undone' => GbpPhoto::REMOVED,
            default => GbpPhoto::FAILED,
        }])->save();
    }

    /** @return Collection<int, GbpPhoto> photos MoxDOP sent to a profile, newest first */
    public function history(DigitalAsset $location): Collection
    {
        return GbpPhoto::query()->where('digital_asset_id', $location->id)->with('writeAction:id,status,error')->latest('id')->limit(30)->get();
    }

    private function guard(User $user): void
    {
        abort_unless(ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GBP), 403, 'Fotoğrafları yalnız Admin gönderir.');
    }
}
