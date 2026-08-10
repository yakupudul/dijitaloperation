<?php

namespace App\Support\Integrations\Presentation;

/**
 * Safe card DTO for the Integrations hub. Never carries secrets.
 *
 * @phpstan-type CardArray array{
 *     provider: string,
 *     label: string,
 *     description: string,
 *     group: string,
 *     group_label: string,
 *     icon: string,
 *     status: string,
 *     status_label: string,
 *     status_color: string,
 *     status_css: string,
 *     summary_lines: list<string>,
 *     last_checked_label: string|null,
 *     action: string,
 *     action_label: string,
 *     manage_url: string|null,
 *     integration_id: int|null,
 *     supports_resources: bool
 * }
 */
final class IntegrationCardViewModel
{
    /**
     * @param  list<string>  $summaryLines
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $label,
        public readonly string $description,
        public readonly string $group,
        public readonly string $groupLabel,
        public readonly string $icon,
        public readonly string $status,
        public readonly array $summaryLines,
        public readonly ?string $lastCheckedLabel,
        public readonly string $action,
        public readonly ?string $manageUrl,
        public readonly ?int $integrationId,
        public readonly bool $supportsResources,
    ) {}

    /**
     * @return CardArray
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'description' => $this->description,
            'group' => $this->group,
            'group_label' => $this->groupLabel,
            'icon' => $this->icon,
            'status' => $this->status,
            'status_label' => IntegrationOperatorStatus::label($this->status),
            'status_color' => IntegrationOperatorStatus::color($this->status),
            'status_css' => IntegrationOperatorStatus::cssClass($this->status),
            'summary_lines' => $this->summaryLines,
            'last_checked_label' => $this->lastCheckedLabel,
            'action' => $this->action,
            'action_label' => $this->action === 'manage' ? 'Manage' : 'Set up',
            'manage_url' => $this->manageUrl,
            'integration_id' => $this->integrationId,
            'supports_resources' => $this->supportsResources,
        ];
    }
}
