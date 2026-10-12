@if ($item['kind'] === 'Güncelleme' && $item['target_url'])
    <p class="mt-0.5 truncate text-xs text-gray-500" data-target="{{ $item['id'] }}">Güncellenecek sayfa: <a href="{{ $item['target_url'] }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ $item['target_title'] ?? $item['target_url'] }}</a>@if ($item['target_title']) <span class="text-gray-400">· {{ parse_url($item['target_url'], PHP_URL_PATH) ?: '/' }}</span>@endif</p>
@endif
