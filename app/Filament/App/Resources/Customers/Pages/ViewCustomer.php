<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Edit customer'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return $customer->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        $type = $customer->type !== null
            ? str($customer->type->value)->replace('_', ' ')->title()->toString()
            : null;
        $status = $customer->status !== null
            ? str($customer->status->value)->replace('_', ' ')->title()->toString()
            : null;

        $parts = array_values(array_filter([$type, $status]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function getBreadcrumb(): string
    {
        return 'Overview';
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
