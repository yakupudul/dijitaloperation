{{-- Durum (sade panel 2026-10-20): nokta + düz metin. Taslak ve Arşiv gri, Onaylandı yeşil, Uygulanıyor canlı nokta. $status, $label --}}
<x-ta.pill :tone="['approved' => 'ok', 'active' => 'live'][$status] ?? 'info'">{{ $label }}</x-ta.pill>
