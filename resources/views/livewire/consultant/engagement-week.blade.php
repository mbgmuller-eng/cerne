@php
    // Cada linha leva ao acompanhamento do cliente: entra no perfil dele e abre a tela.
    $abrir = function (string $profileId, string $rotulo, string $classe = 'btn-ghost px-3 py-1 text-xs') {
        return view('livewire.consultant.partials.engagement-open', compact('profileId', 'rotulo', 'classe'))->render();
    };
@endphp

<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Minha semana</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O que pede atenção nos acompanhamentos dos seus clientes.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
            <p class="eyebrow">Reuniões nos próximos 7 dias</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $dados['counts']['meetings'] }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Tarefas vencidas</p>
            <p class="figure mt-2 text-2xl font-medium {{ $dados['counts']['overdue'] > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">{{ $dados['counts']['overdue'] }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Demandas para responder</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $dados['counts']['requests'] }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Clientes sem reunião marcada</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $dados['counts']['without_meeting'] }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2 *:min-w-0">
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Próximas reuniões</h2>
            @forelse ($dados['meetings'] as $m)
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 py-2.5 first:border-t-0 dark:border-white/10">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $m['client'] }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $m['at']->translatedFormat('D d/m \à\s H:i') }} · {{ $m['agenda_ready'] ? 'pauta pronta' : 'sem pauta' }}</p>
                    </div>
                    {!! $abrir($m['profile_id'], 'Abrir') !!}
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma reunião marcada.</p>
            @endforelse

            @if ($dados['awaiting_summary'])
                <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Aguardando ata</h3>
                @foreach ($dados['awaiting_summary'] as $m)
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 py-2.5 first:border-t-0 dark:border-white/10">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $m['client'] }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $m['at']->format('d/m/Y \à\s H:i') }}</p>
                        </div>
                        {!! $abrir($m['profile_id'], 'Registrar ata') !!}
                    </div>
                @endforeach
            @endif
        </section>

        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Demandas e pendências</h2>
            @foreach ($dados['open_requests'] as $d)
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 py-2.5 first:border-t-0 dark:border-white/10">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $d['client'] }} · {{ $d['title'] }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">aguardando você desde {{ $d['since']->format('d/m') }}</p>
                    </div>
                    {!! $abrir($d['profile_id'], 'Responder') !!}
                </div>
            @endforeach
            @foreach ($dados['overdue_tasks'] as $t)
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 py-2.5 first:border-t-0 dark:border-white/10">
                    <div class="min-w-0">
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $t['client'] }} · {{ $t['title'] }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">tarefa do {{ $t['owner']->value === 'client' ? 'cliente' : 'consultor' }}, venceu em {{ $t['due']->format('d/m') }}</p>
                    </div>
                    {!! $abrir($t['profile_id'], 'Abrir') !!}
                </div>
            @endforeach
            @if (! $dados['open_requests'] && ! $dados['overdue_tasks'])
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nada pendente por enquanto.</p>
            @endif
        </section>
    </div>

    @if ($dados['clients_without_meeting'])
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Clientes sem reunião marcada</h2>
            <div class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($dados['clients_without_meeting'] as $c)
                    <div class="flex items-center justify-between gap-3 py-2.5">
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $c['client'] }}</p>
                        {!! $abrir($c['profile_id'], 'Marcar reunião') !!}
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
