<?php

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\User;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Panel audit, first round finding 9 (yakup 2026-10-21):
 *  - "Tevka Hurda Metal" is a separate business the agency does not serve: its Business Profile is "Kullanılmıyor"
 *    (metadata.not_served_at), never suggested for a brand (it was being offered to Bornova Hurda) and no brand is made.
 *  - "Özel Demiderm Saç Ekimi" belongs to the Demiderm brand: bound to it (undoable from the brand's Varlıklar tab).
 * Each part only acts when exactly what is expected is there; anything else is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('core_external_resources') || ! Schema::hasTable('core_asset_bindings')) {
            return;
        }
        $profiles = fn (string $like) => CoreExternalResource::query()->where('resource_type', 'google_business_profile')
            ->whereRaw('lower(display_name) like ?', [$like]);
        $bound = fn (CoreExternalResource $resource): bool => CoreAssetBinding::query()->where('external_resource_id', $resource->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists();

        foreach ($profiles('tevka hurda%')->get() as $resource) {
            if ($bound($resource)) {
                continue;
            }
            $meta = is_array($resource->metadata) ? $resource->metadata : [];
            $resource->forceFill(['metadata' => $meta + ['not_served_at' => now()->toIso8601String()]])->save();
        }

        $brands = Brand::query()->whereRaw('lower(name) like ?', ['%demiderm%'])->get();
        $actor = User::query()->where('is_active', true)->get()->first(fn (User $user): bool => $user->hasRole(Roles::ADMIN));
        if ($brands->count() !== 1 || $actor === null) {
            return;
        }
        foreach ($profiles('%demiderm sa%ekim%')->get() as $resource) {
            if ($bound($resource)) {
                continue;
            }
            try {
                app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan($resource, $brands->first(),
                    ResourceBindingPlan::MODE_CREATE_ASSET, null, 'İşletme Profili · '.$resource->display_name, $actor));
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    public function down(): void
    {
        // Data fix; the binding is undone from the brand's Varlıklar tab, the "Kullanılmıyor" mark stays.
    }
};
