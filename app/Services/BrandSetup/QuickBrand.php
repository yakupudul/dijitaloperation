<?php

namespace App\Services\BrandSetup;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\Customer;
use App\Models\User;
use App\Services\Access\AccessLinks;
use App\Services\Portfolio\UnassignedWebsites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Yeni marka sihirbazı (Bağlantı Düzeni 4.3). The operator types the domain and nothing else: the customer and the
 * brand are opened, Otomatik kur scans the connected accounts, and when the proposal is ready the matches it is sure
 * of (the ones it would tick by itself) are linked without a second screen. For the channels where no account was
 * found an access link is prepared, so the operator only sends it. Nothing is written to an outside platform and
 * nothing is sent to the customer; every link made here can be changed on the brand's Otomatik kur page.
 */
final class QuickBrand
{
    public function __construct(private readonly UnassignedWebsites $websites, private readonly AccessLinks $access) {}

    public function start(string $domain, string $name, User $actor): Brand
    {
        $host = BrandSetupMatcher::host($domain);
        if ($host === '' || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['domain' => 'Geçerli bir alan adı yaz (ör. ornek.com.tr).']);
        }
        $site = $this->websites->findByHost($domain);
        if ($site !== null && $site->brand_id !== null) {
            throw ValidationException::withMessages(['domain' => 'Bu site zaten '.(Brand::query()->whereKey($site->brand_id)->value('name') ?? 'bir marka').' markasında.']);
        }
        $name = trim($name) !== '' ? trim($name) : Str::title(str_replace(['-', '_'], ' ', explode('.', $host)[0]));

        $brand = DB::transaction(function () use ($name, $site, $actor): Brand {
            $customer = Customer::query()->create(['name' => $name, 'type' => CustomerType::Company, 'status' => CustomerStatus::Active,
                'hq_country' => 'TR', 'service_started_at' => now()->toDateString()]);
            $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => $name, 'primary_country' => 'TR']);
            $brand->responsibleUsers()->sync([$actor->id]);
            if ($site !== null) {
                $this->websites->assign($site, $brand);
            }

            return $brand;
        });
        app(BrandSetupAssistant::class)->queue($brand, $site !== null ? (string) ($site->primary_url ?: $site->domain) : trim($domain), $actor, auto: true);

        return $brand;
    }

    /** Called when a proposal becomes ready: a wizard proposal applies its own ticks and prepares the access link. */
    public function finish(BrandSetupProposal $proposal): void
    {
        if (data_get($proposal->summary, 'auto') !== true || $proposal->status !== BrandSetupProposal::STATUS_READY) {
            return;
        }
        $actor = User::query()->find($proposal->created_by);
        $brand = $proposal->brand;
        if (! $actor instanceof User || ! $brand instanceof Brand) {
            return;
        }
        $items = $proposal->itemRows();
        $keys = collect($items)->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))->pluck('key')->map(fn ($key): string => (string) $key)->values()->all();
        $ticked = fn (array $rows): array => array_keys(array_filter($rows, fn (array $row): bool => (bool) ($row['selected'] ?? false)));
        app(BrandSetupApplier::class)->apply($proposal, $actor, $keys, $ticked($proposal->serviceRows()), true, $ticked(array_values((array) data_get($proposal->summary, 'areas', []))));

        $have = collect($items)->filter(fn (array $item): bool => ($item['status'] ?? '') === 'already' || (bool) ($item['selected'] ?? false))->pluck('group')->unique()->all();
        $missing = array_values(array_diff(array_keys(AccessLinks::CHANNELS), $have));
        if ($missing === []) {
            return;
        }
        try {
            $this->access->ask($brand, $missing, $actor);
        } catch (Throwable) {
            // The agency identity is not written yet: the link is made from the Harita once it is.
        }
    }
}
