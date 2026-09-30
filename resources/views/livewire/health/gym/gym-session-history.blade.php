<div class="space-y-6">

    <div>
        <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Histórico de treinos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Todo treino finalizado — toque num pra ver os exercícios e séries daquele dia.</p>
    </div>

    @if ($sessoes->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum treino finalizado ainda.</p>
        </div>
    @else
        <ul class="card divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($sessoes as $sessao)
                <li>
                    <a href="{{ route('health.gym.session', $sessao->id) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-slate-50 dark:hover:bg-white/5">
                        <div class="min-w-0">
                            <p class="truncate text-slate-800 dark:text-slate-200">{{ $sessao->workout?->name ?? 'Treino' }}</p>
                            <p class="text-xs text-slate-400">{{ $sessao->set_logs_count }} {{ \Illuminate\Support\Str::plural('série', $sessao->set_logs_count) }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-slate-400">{{ $sessao->performed_on->format('d/m/Y') }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
