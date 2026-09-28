<div class="space-y-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $treino->name }}</h1>
            @if ($treino->focus)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $treino->focus }}</p>
            @endif
        </div>
        <a href="{{ route('health.gym.plan') }}" wire:navigate class="btn-ghost shrink-0 text-xs">Editar plano</a>
    </div>

    @if ($inProgress && $inProgress->workout_id === $treino->id)
        <a href="{{ route('health.gym.session', $inProgress->id) }}" wire:navigate class="btn-primary inline-flex">Retomar treino</a>
    @elseif ($inProgress)
        <p class="text-xs text-amber-700 dark:text-amber-400">Você tem um treino em andamento em outro lugar — finalize ou descarte ele antes de começar este.</p>
    @else
        <button type="button" wire:click="startSession" class="btn-primary">Iniciar treino</button>
    @endif

    @if ($itens->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Este treino ainda não tem exercícios.</p>
            <a href="{{ route('health.gym.plan') }}" wire:navigate class="btn-primary mt-4 inline-flex">Adicionar exercícios</a>
        </div>
    @else
        <ul class="card divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($itens as $item)
                @php $exercicio = $item->exercise; @endphp
                <li class="flex items-start gap-4 p-5" wire:key="ex-{{ $item->id }}">
                    <x-gym.exercise-photo :exercise="$exercicio" size="h-16 w-16 rounded-xl" />

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $exercicio->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ $exercicio->muscle_group->label() }}
                            · {{ $item->target_sets }}×
                            @if ($item->target_duration_seconds)
                                {{ $item->target_duration_seconds }}s
                            @elseif ($item->target_reps_min && $item->target_reps_max && $item->target_reps_min !== $item->target_reps_max)
                                {{ $item->target_reps_min }}–{{ $item->target_reps_max }}
                            @elseif ($item->target_reps_min || $item->target_reps_max)
                                {{ $item->target_reps_min ?? $item->target_reps_max }}
                            @else
                                livre
                            @endif
                            @if ($item->rest_seconds) · pausa {{ $item->rest_seconds }}s @endif
                            @if ($item->defaultEquipment) · {{ $item->defaultEquipment->name }} @endif
                        </p>
                        @if ($item->notes)
                            <p class="mt-1 text-xs text-slate-400">{{ $item->notes }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
