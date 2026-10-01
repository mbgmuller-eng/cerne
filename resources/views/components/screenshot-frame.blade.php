@props(['src', 'alt'])

<div {{ $attributes->class(['overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-brand-950/10 dark:bg-slate-800 dark:ring-white/10']) }}>
    <div class="flex items-center gap-1.5 border-b border-slate-200/70 bg-slate-50 px-4 py-2.5 dark:border-white/10 dark:bg-slate-900">
        <span class="h-2.5 w-2.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
        <span class="h-2.5 w-2.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
        <span class="h-2.5 w-2.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
    </div>
    <img src="{{ $src }}" alt="{{ $alt }}" class="w-full" loading="lazy">
</div>
