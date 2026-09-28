<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Academia</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $plan ? $plan->name.($plan->started_on ? ' · desde '.$plan->started_on->format('d/m/Y') : '') : 'Seu plano de treino.' }}
                Só você vê estes dados.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('health.gym.progress') }}" wire:navigate class="btn-secondary">Evolução</a>
            <a href="{{ route('health.gym.plan') }}" wire:navigate class="btn-secondary">
                {{ $plan ? 'Editar plano' : 'Montar plano' }}
            </a>
        </div>
    </div>

    @if ($plan?->notes)
        <details class="card px-5 py-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-300">Orientações da fase</summary>
            <div class="mt-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $plan->notes }}</div>
        </details>
    @endif

    @if ($inProgress)
        <div class="card flex flex-wrap items-center justify-between gap-3 border-l-4 border-l-saude-800 p-5">
            <div>
                <p class="eyebrow">Treino em andamento</p>
                <p class="mt-1 text-sm font-medium text-slate-900 dark:text-white">{{ $inProgress->workout?->name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Iniciado {{ $inProgress->started_at?->format('d/m \à\s H:i') }}</p>
            </div>
            <a href="{{ route('health.gym.session', $inProgress->id) }}" wire:navigate class="btn-primary">Retomar treino</a>
        </div>
    @endif

    @if (! $plan || $workouts->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                {{ $plan ? 'Sua fase ainda não tem treinos.' : 'Você ainda não montou um plano de treino.' }}
            </p>
            <p class="mt-1 text-xs text-slate-400">Crie a fase, os treinos (A, B, C…) e os exercícios de cada um.</p>
            <a href="{{ route('health.gym.plan') }}" wire:navigate class="btn-primary mt-4 inline-flex">
                {{ $plan ? 'Adicionar treinos' : 'Montar plano' }}
            </a>
        </div>
    @else
        @if ($next)
            <div class="card border-l-4 border-l-saude-800 p-5">
                <p class="eyebrow">Próximo treino</p>
                <p class="mt-2 text-lg font-medium text-slate-900 dark:text-white">{{ $next->name }}</p>
                @if ($next->focus)
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $next->focus }}</p>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    @if (! $inProgress)
                        <button type="button" wire:click="startSession('{{ $next->id }}')" class="btn-primary">Iniciar treino</button>
                    @endif
                    <a href="{{ route('health.gym.workout', $next->id) }}" wire:navigate class="btn-secondary">Ver treino</a>
                </div>
            </div>
        @endif

        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($workouts as $treino)
                <li class="card overflow-hidden {{ $next?->id === $treino->id ? 'ring-1 ring-saude-800/40 dark:ring-saude-200/40' : '' }}">
                    <a href="{{ route('health.gym.workout', $treino) }}" wire:navigate class="block p-5 hover:bg-slate-50 dark:hover:bg-white/5">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $treino->name }}</p>
                        @if ($treino->focus)
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $treino->focus }}</p>
                        @endif
                        <p class="mt-3 text-xs text-slate-400">
                            {{ $treino->workout_exercises_count }} {{ $treino->workout_exercises_count === 1 ? 'exercício' : 'exercícios' }}
                        </p>
                    </a>
                    {{-- Fazer fora da ordem da rotação (ex.: pulou um dia): a sequência continua a partir do último concluído. --}}
                    @if (! $inProgress && $treino->workout_exercises_count > 0 && $next?->id !== $treino->id)
                        <div class="border-t border-slate-100 px-5 py-2 dark:border-white/10">
                            <button type="button" wire:click="startSession('{{ $treino->id }}')" class="btn-ghost px-2 py-1 text-xs">Iniciar este</button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($attention->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Para olhar</h2>
            <ul class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($attention as $linha)
                    <li>
                        <a href="{{ route('health.gym.exercise', $linha['exercise']->id) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-slate-50 dark:hover:bg-white/5">
                            <span class="min-w-0 truncate text-slate-800 dark:text-slate-200">{{ $linha['exercise']->name }}</span>
                            @if ($linha['status'] === 'raise')
                                <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">Subir a carga</span>
                            @else
                                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Platô</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Últimas sessões</h2>
            <ul class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($recent as $sessao)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="text-slate-800 dark:text-slate-200">{{ $sessao->workout?->name }}</span>
                        <span class="text-xs text-slate-400">{{ $sessao->performed_on->format('d/m/Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
