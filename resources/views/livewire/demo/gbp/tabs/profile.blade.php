@php
    $profile = $data['profile'];
    $coverage = $data['profile_coverage'];
@endphp

<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Profile</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $profile['subtitle'] }}</p>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Profile coverage</h3>
        <p class="mt-1 text-sm text-gray-800 dark:text-white/90">{{ $coverage['present'] }} of {{ $coverage['total_reviewed'] }} reviewed fields present</p>
        <p class="mt-0.5 text-xs text-gray-500">{{ $coverage['need_attention'] }} need attention · {{ $coverage['unavailable'] }} unavailable</p>
        <p class="mt-2 text-xs text-gray-400">{{ $coverage['note'] }}</p>
    </section>

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <table class="min-w-full text-left text-sm">
            <caption class="sr-only">Profile field audit</caption>
            <thead class="border-b border-gray-100 text-xs uppercase text-gray-500 dark:border-gray-700">
                <tr>
                    <th scope="col" class="px-4 py-2.5 font-medium">Area</th>
                    <th scope="col" class="px-4 py-2.5 font-medium">Current value</th>
                    <th scope="col" class="px-4 py-2.5 font-medium">State</th>
                    <th scope="col" class="px-4 py-2.5 font-medium">Evidence</th>
                    <th scope="col" class="px-4 py-2.5 font-medium">Suggested action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($profile['fields'] as $row)
                    <tr>
                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['area'] }}</td>
                        <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $row['value'] }}</td>
                        <td class="px-4 py-2.5">
                            <span @class([
                                'text-xs font-medium',
                                'text-emerald-700 dark:text-emerald-400' => $row['state'] === 'Present',
                                'text-amber-700 dark:text-amber-400' => in_array($row['state'], ['Review', 'Needs attention'], true),
                            ])>{{ $row['state'] }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $row['evidence'] }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $row['action'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Categories</h3>
            <p class="mt-2 text-sm"><span class="text-xs text-gray-400">Primary</span><br><span class="font-medium text-gray-900 dark:text-white">{{ $profile['categories']['primary'] }}</span></p>
            <p class="mt-2 text-sm"><span class="text-xs text-gray-400">Additional</span><br>{{ implode(', ', $profile['categories']['additional']) }}</p>
            <p class="mt-3 text-xs text-gray-400">{{ $profile['categories']['note'] }}</p>
            <table class="mt-3 w-full text-sm">
                <caption class="sr-only">Brand offerings vs GBP category support</caption>
                <thead class="text-xs text-gray-500"><tr><th class="py-1 text-left">Brand priority offering</th><th class="py-1 text-left">GBP support</th></tr></thead>
                <tbody>
                    @foreach ($profile['categories']['offering_map'] as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-1.5 text-gray-800 dark:text-white/90">{{ $row['offering'] }}</td>
                            <td class="py-1.5 text-xs font-medium">{{ $row['support'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Service coverage</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs text-gray-500">
                        <tr>
                            <th class="py-1 pr-3">GBP service</th>
                            <th class="py-1 pr-3">Brand offering</th>
                            <th class="py-1 pr-3">Website</th>
                            <th class="py-1">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($profile['services'] as $svc)
                            <tr>
                                <td class="py-1.5 pr-3 font-medium text-gray-900 dark:text-white">{{ $svc['service'] }}</td>
                                <td class="py-1.5 pr-3 text-gray-600 dark:text-gray-300">{{ $svc['offering'] }}</td>
                                <td class="py-1.5 pr-3 text-xs text-gray-500">{{ $svc['website'] }} · {{ $svc['gbp'] }}</td>
                                <td class="py-1.5 text-xs font-medium">{{ $svc['state'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-400">External GBP service edits remain disabled. Create internal Tasks only.</p>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Location</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-xs text-gray-400">Address</dt><dd class="text-gray-800 dark:text-white/90">{{ $profile['location']['address'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Coordinates</dt><dd class="tabular-nums text-gray-700 dark:text-gray-300">{{ $profile['location']['lat'] }}, {{ $profile['location']['lng'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Service area</dt><dd>{{ $profile['location']['service_area'] }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-400">Website location page</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $profile['location']['website_location_page'] }} · {{ $profile['location']['website_location_state'] }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-gray-500">{{ $profile['location']['note'] }}</p>
            @php
                $profileMapPayload = [
                    'mode' => 'rank',
                    'business' => [
                        'name' => $identity['title'],
                        'lat' => $profile['location']['lat'],
                        'lng' => $profile['location']['lng'],
                        'address' => $profile['location']['address'],
                    ],
                    'points' => [],
                ];
            @endphp
            <div wire:key="gbp-profile-location-map">
                <div class="gbp-map-shell gbp-map-mini mt-3" data-gbp-rank-map='@json($profileMapPayload)' role="img" aria-label="Business location map"></div>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Entity consistency</h3>
            <p class="mt-1 text-xs text-gray-400">Sources · GBP · Website · Brand Context</p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($data['website_consistency'] as $row)
                    <li class="flex items-center justify-between gap-2">
                        <span>{{ $row['field'] }}</span>
                        <span @class([
                            'text-xs font-medium',
                            'text-emerald-700 dark:text-emerald-400' => $row['state'] === 'Match' || str_starts_with($row['state'], 'Matched'),
                            'text-rose-700 dark:text-rose-400' => $row['state'] === 'Mismatch',
                            'text-amber-700 dark:text-amber-400' => in_array($row['state'], ['Partial', 'Needs review'], true) || str_contains($row['state'], 'Partial'),
                        ])>{{ $row['state'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-500">Trivial formatting differences are normalized before mismatch. No percentage score is assigned.</p>

            <h3 class="mt-5 text-sm font-semibold text-gray-900 dark:text-white">Media</h3>
            <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-400">Profile photo</dt><dd>{{ $profile['media']['profile_photo'] ? 'Present' : 'Not collected' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Cover photo</dt><dd>{{ $profile['media']['cover_photo'] ? 'Present' : 'Not collected' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Merchant media</dt><dd>{{ $profile['media']['merchant_count'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Customer media</dt><dd>{{ $profile['media']['customer_count'] }}</dd></div>
            </dl>
            <p class="mt-2 text-xs text-gray-400">{{ $profile['media']['note'] }}</p>
        </section>
    </div>
</div>
