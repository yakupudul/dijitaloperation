<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Models\Brand;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ViewBrand extends ViewRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'mox-workspace-page',
    ];

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Edit brand'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Brand $brand */
        $brand = $this->getRecord();

        return $brand->name;
    }

    public function getHeading(): string|Htmlable
    {
        /** @var Brand $brand */
        $brand = $this->getRecord();

        $name = e($brand->name);
        $initial = e(mb_strtoupper(mb_substr($brand->name, 0, 1)));
        $logo = is_string($brand->logo_url) ? trim($brand->logo_url) : '';
        $safeLogo = preg_match('#^https?://#i', $logo) === 1 ? e($logo) : null;

        if ($safeLogo !== null) {
            return new HtmlString(
                '<span class="mox-brand-heading">'.
                '<img class="mox-brand-logo" src="'.$safeLogo.'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\'">'.
                '<span class="mox-brand-initial" style="display:none" aria-hidden="true">'.$initial.'</span>'.
                '<span>'.$name.'</span>'.
                '</span>'
            );
        }

        return new HtmlString(
            '<span class="mox-brand-heading">'.
            '<span class="mox-brand-initial" aria-hidden="true">'.$initial.'</span>'.
            '<span>'.$name.'</span>'.
            '</span>'
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Brand $brand */
        $brand = $this->getRecord();

        $parts = array_values(array_filter([
            $brand->sector,
            $brand->primary_country,
            $brand->customer?->name !== null ? 'Customer: '.$brand->customer->name : null,
        ], fn (?string $part): bool => filled($part)));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        /** @var Brand $brand */
        $brand = $this->getRecord();
        /** @var Customer|null $customer */
        $customer = $this->getParentRecord();

        $crumbs = [];

        if ($customer instanceof Customer) {
            $crumbs[CustomerResource::getUrl('view', ['record' => $customer])] = $customer->name;
        }

        $crumbs[] = $brand->name;

        return $crumbs;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }
}
