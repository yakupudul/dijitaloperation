<?php

namespace App\Panel\Services;

use App\Models\AgencySetting;
use App\Models\Brand;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelDocument;
use App\Panel\Support\PanelContext;
use App\Services\Integrations\Google\GoogleWorkspace;
use App\Services\Operator\AgencySettingService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The brand's files live in the agency's Google Drive (yakup 2026-11-27: "müşterinin gönderdiği tüm dosya Google
 * Drive'a otomatik yüklense, sunucumuz da yorulmaz"): one folder per brand under "Moximu müşteri dosyaları". The
 * Drive permission covers only what this app created, so the folders are made here; the operator may move a brand's
 * folder anywhere in his Drive, its address stays the same. Once a file stands in Drive the server copy is removed.
 */
final class PanelDrive
{
    public const string ROOT = 'Moximu müşteri dosyaları';

    private const string API = 'https://www.googleapis.com/drive/v3/files';

    private const string UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const string FOLDER = 'application/vnd.google-apps.folder';

    public function __construct(private readonly GoogleWorkspace $workspace, private readonly PanelContext $context) {}

    public function connected(): bool
    {
        try {
            return $this->workspace->has(GoogleWorkspace::DRIVE);
        } catch (Throwable) {
            return false;
        }
    }

    public static function folderUrl(?string $folderId): ?string
    {
        return filled($folderId) ? 'https://drive.google.com/drive/folders/'.$folderId : null;
    }

    /** The brand's folder id: the kept one while it stands, a new folder otherwise. */
    public function folder(Brand $brand): string
    {
        $setting = PanelBrandSetting::forBrand((int) $brand->id);
        $kept = (string) $setting->drive_folder_id;
        if ($kept !== '' && $this->stands($kept)) {
            return $kept;
        }
        $id = $this->makeFolder((string) $brand->name, $this->root());
        $setting->forceFill(['drive_folder_id' => $id])->save();

        return $id;
    }

    /** Copies one file to the brand's folder and drops the server copy. Returns false when it stays on the server. */
    public function push(PanelDocument $document): bool
    {
        if ($document->inDrive() || blank($document->path) || ! $this->connected()) {
            return false;
        }
        $disk = Storage::disk((string) config('panel.files_disk'));
        if (! $disk->exists((string) $document->path)) {
            return false;
        }
        $brand = Brand::query()->findOrFail($document->brand_id);
        try {
            $folder = $this->folder($brand);
            $bytes = (string) $disk->get((string) $document->path);
            $mime = (string) ($document->mime ?: 'application/octet-stream');
            $response = $this->request()->timeout(150)
                ->attach('metadata', (string) json_encode(['name' => $document->name, 'parents' => [$folder]]), 'metadata.json', ['Content-Type' => 'application/json; charset=UTF-8'])
                ->attach('file', $bytes, $document->name, ['Content-Type' => $mime])
                ->post(self::UPLOAD.'?uploadType=multipart&fields=id,size');
            $id = (string) $response->json('id');
            if (! $response->successful() || $id === '') {
                throw new RuntimeException('Drive dosyayı almadı (HTTP '.$response->status().'). '.mb_substr((string) $response->json('error.message'), 0, 160));
            }
            if ((int) $response->json('size') !== strlen($bytes)) {
                throw new RuntimeException('Drive\'daki kopyanın boyutu dosyayla aynı değil.');
            }
        } catch (Throwable $error) {
            $this->context->system(fn () => $document->forceFill(['drive_error' => mb_substr($error->getMessage(), 0, 300)])->save());

            return false;
        }
        $path = (string) $document->path;
        $this->context->system(fn () => $document->forceFill(['drive_file_id' => $id, 'drive_synced_at' => now(), 'drive_error' => null, 'path' => null])->save());
        $disk->delete($path);

        return true;
    }

    /** The file's bytes, from Drive or from the server copy. */
    public function bytes(PanelDocument $document): ?string
    {
        if ($document->inDrive()) {
            $response = $this->request()->timeout(120)->get(self::API.'/'.rawurlencode((string) $document->drive_file_id), ['alt' => 'media']);

            return $response->successful() ? $response->body() : null;
        }
        $disk = Storage::disk((string) config('panel.files_disk'));

        return filled($document->path) && $disk->exists((string) $document->path) ? $disk->get((string) $document->path) : null;
    }

    private function root(): string
    {
        $setting = AgencySetting::query()->orderBy('id')->first();
        $kept = (string) data_get($setting?->google_workspace, 'panel_drive_root_id', '');
        if ($kept !== '' && $this->stands($kept)) {
            return $kept;
        }
        $id = $this->makeFolder(self::ROOT, null);
        $setting?->forceFill(['google_workspace' => ['panel_drive_root_id' => $id] + (array) ($setting->google_workspace ?? [])])->save();
        rescue(fn () => app(AgencySettingService::class)->forget(), report: false);

        return $id;
    }

    private function stands(string $id): bool
    {
        $found = $this->request()->get(self::API.'/'.rawurlencode($id), ['fields' => 'id,trashed']);
        if ($found->successful()) {
            return $found->json('trashed') !== true;
        }
        if ($found->status() === 404) {
            return false;
        }
        throw new RuntimeException('Drive\'a ulaşılamadı (HTTP '.$found->status().').');
    }

    private function makeFolder(string $name, ?string $parent): string
    {
        $made = $this->request()->post(self::API.'?fields=id', array_filter(['name' => $name, 'mimeType' => self::FOLDER, 'parents' => $parent !== null ? [$parent] : null]));
        $id = (string) $made->json('id');
        if (! $made->successful() || $id === '') {
            throw new RuntimeException('Drive klasörü açılamadı (HTTP '.$made->status().').');
        }

        return $id;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->workspace->token())->acceptJson()->connectTimeout(15)->timeout(60);
    }
}
