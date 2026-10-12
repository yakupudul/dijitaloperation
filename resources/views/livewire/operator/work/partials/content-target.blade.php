@php($targetTitle = $item['target_title'] ?? null)
@if (($item['kind'] ?? null) === 'Güncelleme' && filled($item['target_url'] ?? null))
    <p class="mt-0.5 truncate text-xs text-gray-500" data-target="{{ $item['id'] }}">Güncellenecek sayfa: <a href="{{ $item['target_url'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ $targetTitle ?? $item['target_url'] }}</a>@if ($targetTitle) <span class="text-gray-400">· {{ parse_url($item['target_url'], PHP_URL_PATH) ?: '/' }}</span>@endif</p>
@endif
