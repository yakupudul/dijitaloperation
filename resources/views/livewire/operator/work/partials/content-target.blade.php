@php($targetTitle = $item['target_title'] ?? null)
@if (($item['kind'] ?? null) === 'Güncelleme' && filled($item['target_url'] ?? null))
    <p class="mt-0.5 truncate text-xs text-gray-500" data-target="{{ $item['id'] }}">Güncellenecek sayfa: <a href="{{ $item['target_url'] }}" target="_blank" rel="noopener" class="font-medium text-gray-700 hover:underline dark:text-gray-300">{{ $targetTitle ?? (parse_url($item['target_url'], PHP_URL_PATH) ?: '/') }}</a></p>
@endif
