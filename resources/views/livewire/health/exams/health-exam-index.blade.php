<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Exames</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O que o médico pediu, as datas e os resultados para comparar.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('health.exams.results') }}" wire:navigate class="btn-secondary px-3 py-2 text-sm">Comparar resultados</a>
            <a data-write href="{{ route('health.exams.create') }}" wire:navigate class="btn-primary px-3 py-2 text-sm">+ Novo pedido</a>
        </div>
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-100 px-4 py-2 text-sm text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @include('livewire.health.exams.partials.open-readings', ['leituras' => $leituras, 'variasPessoas' => $variasPessoas])

    <section class="card space-y-3 p-5" data-resultado-avulso>
        <p class="eyebrow">Já tem um laudo?</p>
        <p class="text-sm text-slate-600 dark:text-slate-300">Guarde também os de anos anteriores: eles entram na comparação.</p>
        <div class="flex flex-wrap items-start gap-2">
            <livewire:health.exams.health-exam-upload />
            <a data-write href="{{ route('health.exams.manual') }}" wire:navigate class="btn-ghost px-3 py-2 text-sm">Digitar à mão</a>
        </div>
    </section>

    @if ($pedidos->isEmpty())
        <section class="card p-6 text-center" data-sem-pedidos>
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum pedido de exame.</p>
            <p class="mt-1 text-xs text-slate-400">Quando o médico pedir exames, crie o pedido e marque na lista o que foi pedido.</p>
        </section>
    @else
        <ul class="space-y-3">
            @foreach ($pedidos as $p)
                @php
                    $com = $p->items->filter(fn ($i) => $i->status() === \App\Enums\HealthExamItemStatus::Result)->count();
                    $sem = $p->items->filter(fn ($i) => $i->status() === \App\Enums\HealthExamItemStatus::Pending)->count();
                @endphp
                <li wire:key="pedido-{{ $p->id }}">
                    <a href="{{ route('health.exams.show', $p->id) }}" wire:navigate class="card block p-4 hover:ring-2 hover:ring-saude-800 dark:hover:ring-saude-200">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0 text-sm font-semibold text-slate-900 dark:text-white">{{ $p->title }}</span>
                            <span class="flex flex-wrap gap-2">
                                @if ($sem > 0)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-300">{{ $sem }} {{ $sem === 1 ? 'falta agendar' : 'faltam agendar' }}</span>
                                @endif
                                <span class="rounded-full bg-saude-200/40 px-2 py-0.5 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">{{ $com }} de {{ $p->items->count() }} com resultado</span>
                            </span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                            {{ $variasPessoas ? $p->member->name.' · ' : '' }}pedido em {{ $p->requested_on?->format('d/m/Y') ?? 'data não informada' }}{{ $p->requested_by ? ' por '.$p->requested_by : '' }}{{ $p->routine ? ' · '.$p->routine->name : '' }}{{ $p->followup ? ' · '.$p->followup->title : '' }}
                        </span>
                        <span class="mt-1 block truncate text-sm text-slate-600 dark:text-slate-300">{{ $p->items->pluck('name')->implode(', ') }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
