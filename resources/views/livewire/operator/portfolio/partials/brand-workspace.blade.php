{{-- Brand workspace: "Bu hafta yapılacaklar" across channels, then the selected channel tab (Arama · Harita · Google Ads · Meta). --}}
<section aria-labelledby="week-heading" class="space-y-3" data-workspace-week>
    <h2 id="week-heading" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bu hafta yapılacaklar</h2>
    @if (! $operational)
        <p class="text-sm text-gray-500">{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @elseif ($weekTop === [])
        <p class="text-sm text-gray-500">Açık iş yok.</p>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($weekTop as $decision)
                <x-workspace.decision-card :decision="$decision" :show-channel="true" />
            @endforeach
        </div>
    @endif
</section>

<section aria-label="{{ $workspaceTabs[$mainTab] }}">
    @if ($channelComponent !== null)
        @livewire($channelComponent, ['brandId' => $brandModel->id], key('workspace-'.$mainTab.'-'.$brandModel->id))
    @else
        <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-white/[0.03] dark:text-gray-400" data-workspace-pending>Hazırlanıyor</p>
    @endif
</section>
