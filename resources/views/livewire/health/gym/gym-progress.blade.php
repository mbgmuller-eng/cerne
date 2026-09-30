@php
    $unidade = ['kg' => 'kg', 'stack' => 'posição', 'reps' => 'reps', 'duration' => 's', 'speed' => 'km/h'];
@endphp

<div class="space-y-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Evolução</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Cada exercício, treino a treino. Só treinos finalizados entram.</p>
        </div>

        <form wire:submit="baixarRelatorio" class="card flex flex-wrap items-end gap-2 p-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De</label>
                <input type="date" wire:model="inicioRelatorio" class="input mt-1 text-sm">
                @error('inicioRelatorio') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Até</label>
                <input type="date" wire:model="fimRelatorio" class="input mt-1 text-sm">
                @error('fimRelatorio') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-secondary px-3 py-2 text-sm" wire:loading.attr="disabled" wire:target="baixarRelatorio">
                <span wire:loading.remove wire:target="baixarRelatorio">Baixar relatório PDF</span>
                <span wire:loading wire:target="baixarRelatorio">Gerando...</span>
            </button>
        </form>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Ainda não há treinos finalizados.</p>
            <p class="mt-1 text-xs text-slate-400">Depois do primeiro treino concluído, a evolução de cada exercício aparece aqui.</p>
        </div>
    @else
        <ul class="card divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($rows as $linha)
                @php
                    $melhor = $linha['best'];
                    $un = $unidade[$linha['metric']] ?? '';
                @endphp
                <li wire:key="p-{{ $linha['exercise']->id }}">
                    <a href="{{ route('health.gym.exercise', $linha['exercise']->id) }}" wire:navigate
                       class="flex items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50 dark:hover:bg-white/5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $linha['exercise']->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $linha['exercise']->muscle_group->label() }}
                                · {{ $linha['sessions_count'] }} {{ $linha['sessions_count'] === 1 ? 'treino' : 'treinos' }}
                                · último em {{ $linha['last_date']?->format('d/m/Y') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            @if ($melhor)
                                <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">
                                    {{ $progress->fmt($melhor['value']) }} {{ $un }}
                                    @if (in_array($linha['metric'], ['kg', 'stack']) && $melhor['tiebreak'])
                                        <span class="text-xs text-slate-400">× {{ $melhor['tiebreak'] }}</span>
                                    @endif
                                </p>
                            @endif
                            @if ($linha['status'] === 'raise')
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">Subir a carga</span>
                            @elseif ($linha['status'] === 'plateau')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Platô · {{ $linha['plateau'] }} treinos</span>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-slate-400">O número à direita é a sua melhor marca. Platô = {{ \App\Services\GymProgressService::PLATEAU_AFTER }} treinos seguidos sem superá-la (na mesma máquina).</p>
    @endif
</div>
