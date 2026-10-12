{{-- Durum rozeti: Taslak gri, Onaylandı yeşil, Uygulanıyor turuncu (ana iş), Arşiv soluk. $status, $label --}}
<span @class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
    'bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-300' => $status === 'draft',
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $status === 'approved',
    'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $status === 'active',
    'bg-gray-50 text-gray-400 dark:bg-white/[0.03] dark:text-gray-500' => $status === 'archived'])>{{ $label }}</span>
