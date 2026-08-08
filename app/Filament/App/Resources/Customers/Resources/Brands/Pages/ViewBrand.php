<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Widgets\BrandWorkspaceSummaryWidget;
use App\Models\Brand;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewBrand extends ViewRecord
{
    protected static string $resource = BrandResource::class;

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
        return $this->getTitle();
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

    public function getBreadcrumb(): string
    {
        return 'Workspace';
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            BrandWorkspaceSummaryWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'record' => $this->getRecord(),
        ];
    }
}
