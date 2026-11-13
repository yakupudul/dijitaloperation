@php
    use App\Models\Agreement;

    $isNew = $jobId < 0;
    $kind = $job['kind'] ?? 'one_time';
    $canSchedule = in_array($kind, ['term', 'one_time'], true);
    $schedule = $canSchedule && ($job['plan'] ?? 'auto') === 'schedule';
    $kindHelp = [
        'monthly' => 'Her ay aynı ücret; ödeme gününde hatırlatılır.',
        'term' => 'Belli ay sayısı için anlaşma (6 ay gibi); ne ödendiyse kalan hesaplanır.',
        'one_time' => 'Bir kez ödenen iş: web sitesi, kurulum, denetim.',
        'yearly' => 'Yılda bir yenilenen iş: alan adı, sunucu, bakım. Günü gelmeden 35 gün önce haber verilir.',
        'partnership' => 'Ücreti sabit olmayan ortaklık; ödeme satırı açılmaz.',
    ];
    $feeLabel = ['monthly' => 'Aylık ücret', 'term' => 'Aylık ücret', 'one_time' => 'Ücret', 'yearly' => 'Yıllık ücret', 'partnership' => 'Ücret (varsa)'][$kind] ?? 'Ücret';
    $scheduleSum = collect($job['installments'] ?? [])->sum(fn ($r) => (int) preg_replace('/\D+/', '', (string) ($r['amount'] ?? '')));
@endphp
<form wire:submit="saveJob" class="card mt-4 p-4 sm:p-5" data-finance-job-form>
    <div class="flex items-center justify-between gap-3">
        <h2 class="section-title">{{ $isNew ? 'Yeni iş' : 'İşi düzenle' }}</h2>
        <button type="button" wire:click="closeDoc" class="btn-quiet">Vazgeç</button>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <label class="{{ $label }}" for="job-brand">Marka</label>
            <select id="job-brand" wire:model.live="job.brand_id" class="{{ $input }}" @disabled(! $isNew)>
                <option value="">Panelde yok (elle yazacağım)</option>
                @foreach ($brands as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        @if (blank($job['brand_id'] ?? ''))
            <div>
                <label class="{{ $label }}" for="job-party">Kimden alınacak</label>
                <input id="job-party" type="text" wire:model="job.party" class="{{ $input }}" placeholder="Kişi ya da işletme adı">
                @if ($err('job.party'))<p class="mt-1 text-xs text-red-600">{{ $err('job.party') }}</p>@endif
            </div>
            <div>
                <label class="{{ $label }}" for="job-phone">Telefonu</label>
                <input id="job-phone" type="tel" wire:model="job.party_phone" class="{{ $input }}" placeholder="05xx xxx xx xx">
            </div>
        @endif
        <div class="lg:col-span-2">
            <label class="{{ $label }}" for="job-title">İşin adı</label>
            <input id="job-title" type="text" wire:model="job.title" class="{{ $input }}" placeholder="Web sitesi, alan adı yenileme, SEO…">
        </div>
        <div>
            <label class="{{ $label }}" for="job-kind">Ödeme şekli</label>
            <select id="job-kind" wire:model.live="job.kind" class="{{ $input }}">
                @foreach (Agreement::KINDS as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
            @if ($err('job.kind'))<p class="mt-1 text-xs text-red-600">{{ $err('job.kind') }}</p>@endif
        </div>
        @if ($kind === 'term')
            <div>
                <label class="{{ $label }}" for="job-term">Kaç ay</label>
                <input id="job-term" type="number" min="1" max="36" wire:model="job.term_months" class="{{ $input }}">
                @if ($err('job.term_months'))<p class="mt-1 text-xs text-red-600">{{ $err('job.term_months') }}</p>@endif
            </div>
        @endif
        <p class="text-xs text-gray-500 sm:col-span-2 lg:col-span-4">{{ $kindHelp[$kind] ?? '' }}</p>

        @if ($canSchedule)
            <div class="sm:col-span-2 lg:col-span-4">
                <span class="{{ $label }}">Ödeme planı</span>
                <div class="flex flex-wrap gap-2">
                    <label @class(['btn cursor-pointer', '!border-gray-900' => ! $schedule])><input type="radio" class="sr-only" wire:model.live="job.plan" value="auto">{{ $kind === 'term' ? 'Her ay eşit' : 'Tek ödeme' }}</label>
                    <label @class(['btn cursor-pointer', '!border-gray-900' => $schedule])><input type="radio" class="sr-only" wire:model.live="job.plan" value="schedule">Taksit tarihlerini ben yazacağım</label>
                </div>
                @if ($err('job.plan'))<p class="mt-1 text-xs text-red-600">{{ $err('job.plan') }}</p>@endif
            </div>
        @endif

        @if (! $schedule)
            <div>
                <label class="{{ $label }}" for="job-fee">{{ $feeLabel }} (₺, {{ ($job['no_invoice'] ?? false) ? 'faturasız, elime geçen' : 'KDV hariç' }})</label>
                <input id="job-fee" type="text" inputmode="numeric" wire:model="job.fee" class="{{ $input }}" placeholder="14.000">
                @if ($err('job.fee'))<p class="mt-1 text-xs text-red-600">{{ $err('job.fee') }}</p>@endif
            </div>
        @endif
        <div>
            <label class="{{ $label }}" for="job-start">{{ $kind === 'yearly' ? 'İlk ödeme günü' : 'Başlangıç' }}</label>
            <input id="job-start" type="date" wire:model="job.starts_on" class="{{ $input }}">
            @if ($err('job.starts_on'))<p class="mt-1 text-xs text-red-600">{{ $err('job.starts_on') }}</p>@endif
        </div>
        @if (in_array($kind, ['monthly', 'term'], true) && ! $schedule)
            <div>
                <label class="{{ $label }}" for="job-due">Ayın kaçında ödenir</label>
                <input id="job-due" type="number" min="1" max="28" wire:model="job.due_day" class="{{ $input }}">
                @if ($err('job.due_day'))<p class="mt-1 text-xs text-red-600">{{ $err('job.due_day') }}</p>@endif
            </div>
        @endif
        @if (! $isNew && ! $schedule && $kind !== 'one_time')
            <div>
                <label class="{{ $label }}" for="job-since">Yeni ücret ne zamandan beri</label>
                <input id="job-since" type="date" wire:model="job.fee_since" class="{{ $input }}">
                <p class="mt-1 text-xs text-gray-500">Ücreti değiştiriyorsan doldur; boşsa bugün sayılır.</p>
            </div>
        @endif
    </div>

    @if ($schedule)
        <div class="mt-5" data-finance-installments>
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">Taksitler</span>
                <span class="text-sm text-gray-600">Toplam {{ $tlf($scheduleSum) }} ({{ ($job['no_invoice'] ?? false) ? 'faturasız' : 'KDV hariç' }})</span>
            </div>
            @if (! $isNew)
                <p class="mt-1 text-xs text-gray-500">Ödenmiş taksitler yerinde durur; burada yalnız bekleyenler görünür.</p>
            @endif
            <div class="mt-2 space-y-2">
                @foreach ($job['installments'] ?? [] as $i => $row)
                    <div class="flex flex-wrap items-center gap-2" wire:key="inst-{{ $i }}">
                        <span class="w-6 text-right text-xs text-gray-500">{{ $i + 1 }}.</span>
                        <input type="date" wire:model="job.installments.{{ $i }}.due_on" class="{{ $input }} !w-40" aria-label="{{ $i + 1 }}. taksit tarihi">
                        <input type="text" inputmode="numeric" wire:model.blur="job.installments.{{ $i }}.amount" class="{{ $input }} !w-32" placeholder="Tutar ₺" aria-label="{{ $i + 1 }}. taksit tutarı">
                        <input type="text" wire:model="job.installments.{{ $i }}.label" class="{{ $input }} !w-44" placeholder="Not (peşinat, teslimde…)" aria-label="{{ $i + 1 }}. taksit notu">
                        <button type="button" wire:click="removeInstallment({{ $i }})" class="btn-quiet" aria-label="{{ $i + 1 }}. taksiti çıkar">Çıkar</button>
                    </div>
                @endforeach
            </div>
            @if ($err('job.installments'))<p class="mt-1 text-xs text-red-600">{{ $err('job.installments') }}</p>@endif
            <button type="button" wire:click="addInstallment" class="btn mt-2">Taksit ekle</button>
        </div>
    @endif

    <div class="mt-5 grid gap-4 sm:grid-cols-3">
        <div>
            <span class="{{ $label }}">Hizmetler</span>
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                @foreach ($services as $key => $name)
                    <label class="flex items-center gap-1.5 text-sm text-gray-800 dark:text-gray-200"><input type="checkbox" wire:model="job.services" value="{{ $key }}" class="rounded border-gray-300">{{ $name }}</label>
                @endforeach
            </div>
        </div>
        <div>
            <span class="{{ $label }}">Fatura</span>
            <label class="flex items-center gap-1.5 text-sm text-gray-800 dark:text-gray-200"><input type="checkbox" wire:model.live="job.no_invoice" class="rounded border-gray-300" data-finance-no-invoice>Fatura kesilmiyor</label>
            <p class="mt-1 text-xs text-gray-500">İşaretliyse ücret elime geçen tutardır: KDV eklenmez, fatura eksik diye uyarılmaz.</p>
        </div>
        <div>
            <label class="{{ $label }}" for="job-tax">Vergi / TC kimlik no</label>
            <input id="job-tax" type="text" inputmode="numeric" wire:model="job.tax_no" class="{{ $input }}" placeholder="Faturalar bu numarayla kendiliğinden eşleşir">
        </div>
        <div class="sm:col-span-3">
            <label class="{{ $label }}" for="job-note">Not</label>
            <textarea id="job-note" wire:model="job.note" rows="2" class="{{ $input }} !h-auto py-2" placeholder="Ne konuşuldu, neler dahil"></textarea>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="saveJob">Kaydet</button>
        <button type="button" wire:click="closeDoc" class="btn">Vazgeç</button>
    </div>
</form>
