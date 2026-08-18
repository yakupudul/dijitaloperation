<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\ServiceScope\CustomerServiceScopeService;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function afterCreate(): void
    {
        /** @var Customer $customer */
        $customer = $this->record;
        $codes = is_array($customer->services) ? $customer->services : [];

        app(CustomerServiceScopeService::class)->syncActiveCustomerWideFromCodes($customer, $codes);
    }
}
