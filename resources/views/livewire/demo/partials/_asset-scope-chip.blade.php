@props([
    'assetType' => null,
])

@php
    use App\Support\Demo\CommercialContextFixtures;

    $type = is_string($assetType) ? $assetType : null;
    $scope = $type ? CommercialContextFixtures::scopeForAssetType($type) : null;
    $statusLabel = match ($scope['status'] ?? null) {
        'active' => __('operator.service_scope.status_active'),
        'planned' => __('operator.service_scope.status_planned'),
        'outside_scope' => __('operator.service_scope.status_outside'),
        default => null,
    };
@endphp

@if ($type === 'instagram')
    <p class="text-xs text-amber-700 dark:text-amber-300">
        <span class="font-medium">{{ __('operator.commercial.outside_scope') }}</span>
    </p>
@elseif ($scope)
    <p class="text-xs text-gray-500 dark:text-gray-400">
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('operator.commercial.managed_under') }}</span>
        · {{ $scope['service_label'] ?? '' }}
        @if ($statusLabel)
            · {{ $statusLabel }}
        @endif
    </p>
@endif
