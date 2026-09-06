<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Web Sitesi Standartları</h1>
        <p class="mt-2 text-sm text-gray-500">Web sitesi ve rakip analizlerinde kullanılan değerlendirme ölçütleri. Her kriterin kapsamı, kanıtı ve inceleme yöntemi ayrı tanımlıdır.</p>
        <p class="mt-1 text-xs text-gray-500">Bu kütüphane tüm markalar için ortaktır. Değişiklikler sonraki değerlendirmelerde uygulanır.</p>
    </div>
    @if($message)<p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ $message }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="flex flex-wrap gap-3">
        <label><span class="sr-only">Standart ara</span><input wire:model.live.debounce.300ms="search" placeholder="Standart ara" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white" /></label>
        <label><span class="sr-only">Kategori</span><select wire:model.live="group" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white"><option value="">Tüm kategoriler</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    </div>
    @foreach($standards as $standard)
        <article wire:key="standard-{{ $standard['id'] }}" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs text-gray-500">{{ $groups[$standard['group']] }} · v{{ $standard['version'] }} · {{ $standard['method'] === 'expert_review' ? 'Uzman incelemesi' : 'Kodla değerlendirme' }}</p><h2 class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $standard['title'] }}</h2></div>
                @if(auth()->user()->hasRole(\App\Support\Roles::ADMIN))
                    <button type="button" wire:click="setEnabled('{{ $standard['id'] }}', {{ $standard['enabled'] ? 'false' : 'true' }})" wire:loading.attr="disabled" class="rounded-lg border px-3 py-2 text-sm {{ $standard['enabled'] ? 'border-emerald-300 text-emerald-700' : 'border-gray-300 text-gray-500' }}">{{ $standard['enabled'] ? 'Etkin — devre dışı bırak' : 'Devre dışı — etkinleştir' }}</button>
                @else <span class="text-sm text-gray-500">{{ $standard['enabled'] ? 'Etkin' : 'Devre dışı' }}</span> @endif
            </div>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">{{ $standard['criterion'] }}</p>
            <details class="mt-3 text-sm text-gray-600 dark:text-gray-400"><summary class="cursor-pointer font-medium text-brand-600">Kapsam, kanıt ve öneri</summary>
                <dl class="mt-3 space-y-2"><div><dt class="font-semibold">Uygulanacağı sayfalar</dt><dd>{{ ['site' => 'Site genelindeki gözlem', 'content_page' => 'İçerik sayfaları', 'public_page' => 'Herkese açık sayfalar', 'search_target' => 'Aramada görünmesi amaçlanan hedefler; amaç bilinmiyorsa inceleme', 'local_content' => 'Yerel hizmet niyeti bulunan içerikler'][$standard['applicability']] ?? $standard['applicability'] }}</dd></div>
                <div><dt class="font-semibold">Gerekli kanıt</dt><dd>{{ implode(', ', $standard['required_evidence']) }}</dd></div><div><dt class="font-semibold">İyileştirme</dt><dd>{{ $standard['action'] }}</dd></div><div><dt class="font-semibold">Doğrulama</dt><dd>{{ $standard['verification'] }}</dd></div></dl>
                <p class="mt-3 text-xs">{{ ['verified' => 'Doğrudan gözlem kontrolü', 'advisory' => 'Tavsiye; evrensel sıralama şartı değildir', 'heuristic' => 'Yaklaşık inceleme ölçütü; kesin kural değildir', 'expert_judgment' => 'Kanıta dayalı uzman yorumu', 'agency_practice' => 'Ajans tarafından tanımlanmış uzman kriteri'][$standard['classification']] ?? '' }}</p>
                @if($standard['source_url'])<a href="{{ $standard['source_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-brand-600 underline">Referans kaynağı</a>@endif
            </details>
        </article>
    @endforeach
    @if(auth()->user()->hasRole(\App\Support\Roles::ADMIN))
        <details class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900"><summary class="cursor-pointer font-semibold text-brand-600">Uzman kriteri ekle</summary>
            <form wire:submit="addCriterion" class="mt-4 grid gap-3">
                <label class="text-sm text-gray-600">Başlık<input wire:model="draft.title" required maxlength="160" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-gray-900" /></label>
                <label class="text-sm text-gray-600">Kategori<select wire:model="draft.group" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-gray-900">@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label class="text-sm text-gray-600">Hangi soruyu değerlendirecek?<textarea wire:model="draft.criterion" required minlength="20" maxlength="1500" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
                <label class="text-sm text-gray-600">Eksik varsa önerilecek yaklaşım<textarea wire:model="draft.action" required minlength="10" maxlength="1000" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
                <label class="text-sm text-gray-600">Referans bağlantısı (isteğe bağlı)<input wire:model="draft.source_url" type="url" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-gray-900" /></label>
                <button type="submit" wire:loading.attr="disabled" class="justify-self-start rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">Kriteri kaydet</button>
            </form>
        </details>
    @endif
</div>
