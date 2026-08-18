<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Task;
use App\Models\User;

/**
 * Seed production Tasks that replace retired Demo task titles used by frozen UX tests.
 */
trait SeedsCanonicalWorkTasks
{
    protected Customer $workCustomer;

    protected Brand $workBrand;

    protected DigitalAsset $workAsset;

    protected User $workAssigneeAyse;

    protected function seedCanonicalWorkTasks(): void
    {
        $this->workCustomer = Customer::factory()->create(['name' => 'Atlas Health Group']);
        $this->workBrand = Brand::factory()->create([
            'customer_id' => $this->workCustomer->id,
            'name' => 'Atlas Dental Ankara',
        ]);
        $this->workAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'name' => 'Atlas Dental Website',
            'type' => 'website',
        ]);
        $this->workAssigneeAyse = User::factory()->create(['name' => 'Ayşe Demir']);

        $base = [
            'customer_id' => $this->workCustomer->id,
            'brand_id' => $this->workBrand->id,
            'digital_asset_id' => $this->workAsset->id,
            'scope_kind' => 'digital_asset',
            'source_kind' => 'direct',
            'recommendation_id' => null,
            'client_request_id' => null,
            'status' => 'open',
            'priority' => 'high',
        ];

        Task::factory()->create(array_merge($base, [
            'title' => 'Investigate lead measurement',
            'assignee_id' => $this->workAssigneeAyse->id,
            'due_date' => now()->toDateString(),
            'priority' => 'critical',
        ]));

        Task::factory()->create(array_merge($base, [
            'title' => 'Replace PB-Video-03 creative',
            'assignee_id' => $this->workAssigneeAyse->id,
            'due_date' => now()->addDay()->toDateString(),
        ]));

        Task::factory()->create(array_merge($base, [
            'title' => 'Improve /implant mobile LCP',
            'assignee_id' => User::factory()->create(['name' => 'Can Öztürk'])->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => 'in_progress',
        ]));

        Task::factory()->create(array_merge($base, [
            'title' => 'Update positioning language for medical travel',
            'assignee_id' => $this->workAssigneeAyse->id,
            'due_date' => now()->addWeek()->toDateString(),
        ]));

        Task::factory()->create(array_merge($base, [
            'title' => 'Clear unanswered GBP review backlog',
            'assignee_id' => $this->workAssigneeAyse->id,
            'status' => 'blocked',
            'due_date' => null,
        ]));
    }
}
