<div class="space-y-5">
    <x-activity-header title="Evolução" subtitle="As últimas 8 semanas. A semana atual ainda está em andamento.">
        <a href="{{ route('health.gym.progress') }}" wire:navigate class="btn-secondary">Evolução da academia</a>
    </x-activity-header>

    @if (! $dados['has_data'])
        <div class="card p-5" data-sem-dados><p class="text-sm text-slate-600 dark:text-slate-300">Ainda não há atividades no período. <a href="{{ route('health.activities.log') }}" wire:navigate class="text-saude-800 underline dark:text-saude-200">Registrar a primeira</a></p></div>
    @else
        <div class="card min-w-0 space-y-3 p-5">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Carga por semana</h2>
                <p class="text-xs text-slate-400">Minutos × esforço. É um sinal para observar como o corpo responde, não prevê lesão.@if ($dados['missing_effort'] > 0) {{ $dados['missing_effort'] }} {{ $dados['missing_effort'] === 1 ? 'registro está sem esforço e fica' : 'registros estão sem esforço e ficam' }} fora da conta.@endif</p>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                @foreach ($legenda as [$nome, $cor])
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $cor }}"></span>{{ $nome }}</span>
                @endforeach
            </div>
            <div class="overflow-x-auto"><div class="min-w-[520px]">{!! $grafico !!}</div></div>
            <details>
                <summary class="cursor-pointer text-xs font-medium text-saude-800 dark:text-saude-200">Ver em tabela</summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead><tr class="text-left text-xs text-slate-500 dark:text-slate-400"><th class="py-1 pr-3">Semana</th>@foreach ($grupos as $g) <th class="whitespace-nowrap py-1 pr-3">{{ $g }}</th> @endforeach<th class="py-1 pr-3">Total</th><th class="py-1 pr-3">Vezes</th><th class="py-1 pr-3">Minutos</th><th class="whitespace-nowrap py-1">Dor média</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($dados['weeks'] as $s)
                                <tr><td class="py-1.5 pr-3">{{ $s['start']->format('d/m') }}</td>@foreach (array_keys($grupos) as $g)<td class="py-1.5 pr-3">{{ number_format($s['load'][$g], 0, ',', '.') }}</td>@endforeach<td class="py-1.5 pr-3 font-semibold">{{ number_format($s['total'], 0, ',', '.') }}</td><td class="py-1.5 pr-3">{{ $s['times'] }}</td><td class="py-1.5 pr-3">{{ $s['minutes'] }}</td><td class="py-1.5">{{ $s['pain'] !== null ? number_format($s['pain'], 1, ',', '') : '-' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Nas últimas 4 semanas</p>
            <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($dados['last_four'] as $a)
                    <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ $a['name'] }}</p><p class="font-display text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ $a['times'] }} {{ $a['times'] === 1 ? 'vez' : 'vezes' }}</p><p class="text-xs text-slate-400">{{ $a['minutes'] }} min</p></div>
                @endforeach
            </div>
        </div>
    @endif
</div>
