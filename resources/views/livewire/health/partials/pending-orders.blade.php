{{-- Pedidos de exame que ainda têm algo sem data: a Agenda só guarda o que tem dia e hora, então eles ficam avisados aqui. $pedidos com items.appointment. --}}
<ul class="mt-2 space-y-2">
    @foreach ($pedidos as $p)
        @php $esperando = $p->items->filter(fn ($i) => $i->status() === \App\Enums\HealthExamItemStatus::Pending)->pluck('name'); @endphp
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 p-3 dark:border-amber-500/30" wire:key="pedido-{{ $p->id }}">
            <span class="min-w-0 flex-1 basis-60">
                <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ ($comPessoa ?? false) ? $p->member->name.' · ' : '' }}{{ $p->title }}</span>
                <span class="block text-xs text-slate-500 dark:text-slate-400">Falta agendar: {{ $esperando->implode(', ') }}</span>
            </span>
            <a href="{{ route('health.exams.show', $p->id) }}" wire:navigate class="btn-secondary shrink-0 px-3 py-1 text-xs">Abrir pedido</a>
        </li>
    @endforeach
</ul>
