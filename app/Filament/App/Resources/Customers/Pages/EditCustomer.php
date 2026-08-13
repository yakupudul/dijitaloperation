<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\ServiceScope\CustomerServiceScopeService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Customer $customer */
        $customer = $this->record;
        $codes = is_array($customer->services) ? $customer->services : [];

        app(CustomerServiceScopeService::class)->syncActiveCustomerWideFromCodes($customer, $codes);
    }
}
