@php
    $infra = $infrastructure;
@endphp

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Infrastructure</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $infra['subtitle'] }}</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($infra['attention'] as $item)
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ $item['severity'] }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $item['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Domain</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-xs text-gray-400">Hostname</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $infra['domain']['hostname'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Registrar</dt><dd>{{ $infra['domain']['registrar'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Registered</dt><dd>{{ $infra['domain']['registered_at'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Expiry</dt><dd>{{ $infra['domain']['expires_at'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Auto-renew</dt><dd>{{ $infra['domain']['auto_renew'] ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-xs text-gray-400">Provenance</dt><dd class="text-xs text-gray-500">{{ $infra['domain']['provenance'] }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">DNS</h3>
            <p class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">{{ $infra['dns']['state'] }}</p>
            <p class="mt-2 text-xs text-gray-400">Nameservers</p>
            <ul class="mt-1 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($infra['dns']['nameservers'] as $ns)
                    <li class="font-mono text-xs">{{ $ns }}</li>
                @endforeach
            </ul>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <caption class="sr-only">DNS records</caption>
                    <thead class="text-gray-400"><tr><th class="py-1 pr-3">Type</th><th class="py-1 pr-3">Host</th><th class="py-1">Value</th></tr></thead>
                    <tbody>
                        @foreach ($infra['dns']['records'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1.5 pr-3 font-medium">{{ $row['type'] }}</td>
                                <td class="py-1.5 pr-3">{{ $row['host'] }}</td>
                                <td class="py-1.5 font-mono">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Hosting</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-xs text-gray-400">Provider</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $infra['hosting']['provider'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Platform</dt><dd>{{ $infra['hosting']['platform'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Region</dt><dd>{{ $infra['hosting']['region'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Environment</dt><dd>{{ $infra['hosting']['environment'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Renewal</dt><dd>{{ $infra['hosting']['renewal_at'] }}</dd></div>
                <div><dt class="text-xs text-gray-400">Provenance</dt><dd class="text-xs text-gray-500">{{ $infra['hosting']['provenance'] }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">CDN · SSL / TLS · CMS</h3>
            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-gray-400">CDN</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $infra['cdn']['provider'] }} · {{ $infra['cdn']['state'] }}</dd>
                    <dd class="text-xs text-gray-500">{{ $infra['cdn']['note'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">SSL / TLS</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $infra['ssl']['https'] }} · {{ $infra['ssl']['issuer'] }} · grade {{ $infra['ssl']['grade'] }}</dd>
                    <dd class="text-xs text-gray-500">Expires {{ $infra['ssl']['expires_at'] }} · {{ $infra['ssl']['days_remaining'] }} days · {{ $infra['ssl']['provenance'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">CMS</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $infra['cms']['name'] }}</dd>
                    <dd class="text-xs text-gray-500">{{ $infra['cms']['version'] }} · {{ $infra['cms']['provenance'] }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Infrastructure Findings</h3>
        <ul class="mt-3 space-y-2">
            @foreach ($infra['findings'] as $finding)
                <li class="flex justify-between gap-2 text-sm">
                    <span class="text-gray-800 dark:text-white/90">{{ $finding['title'] }}</span>
                    <span class="text-xs text-gray-500">{{ $finding['severity'] }} · {{ $finding['state'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-gray-400">{{ $infra['legacy_note'] }}</p>
    </section>
</div>
