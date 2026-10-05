<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * İşletme Profili "Kategori ve hizmetler" (operation `gbp.profile_plan`): the operator's category / service lines →
 * Google categories (ids from Google's own category list) and service items (Google's predefined service type or a
 * free-form service with a short description). Ids and texts are checked by GbpProfilePlanner; the profile changes
 * only on the Admin's "Gönder".
 */
final class GbpProfilePlanAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GBP_PROFILE_PLAN;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'categories' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'line' => $row->string()->required(),
                'category_id' => $row->string()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'services' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'line' => $row->string()->required(),
                'category_id' => $row->string()->required(),
                'service_type_id' => $row->string()->required(),
                'name' => $row->string()->required(),
                'description' => $row->string()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
