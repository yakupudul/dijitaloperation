<?php

use App\Models\SalesLead;
use App\Services\Sales\SalesLeads;
use Illuminate\Database\Migrations\Migration;

/**
 * Cards opened from the phone backup show only a number (the backup has no contact names). Give them the name we
 * greeted them with in our own messages ("Aslı Hocam merhaba"); the address book (.vcf) still fills the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        SalesLead::query()->whereNull('name')->orWhere('name', '')->whereNotNull('conversation_id')
            ->orderBy('id')->each(function (SalesLead $lead): void {
                if ($lead->conversation_id === null) {
                    return;
                }
                $name = SalesLeads::nameFromGreeting((int) $lead->conversation_id);
                if ($name !== null) {
                    SalesLead::query()->whereKey($lead->id)->update(['name' => mb_substr($name, 0, 255)]);
                }
            });
    }

    public function down(): void {}
};
