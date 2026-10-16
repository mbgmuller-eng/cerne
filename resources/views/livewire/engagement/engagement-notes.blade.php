<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Notas</h2>

    <form wire:submit="save" class="mt-3 space-y-2">
        <textarea wire:model="body" rows="2" maxlength="3000" class="input" placeholder="Escreva uma nota"></textarea>
        @error('body') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if ($visitante->isConsultant())
                <label class="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="private" class="rounded border-slate-300">
                    Nota privada (só você vê)
                </label>
            @else
                <span class="text-xs text-slate-400">Visível para você e para o consultor.</span>
            @endif
            <button type="submit" class="btn-secondary px-3 py-1.5 text-xs">Adicionar nota</button>
        </div>
    </form>

    @if ($compartilhadas->isNotEmpty())
        <ul class="mt-4 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($compartilhadas as $n)
                <li class="py-3" wire:key="nota-{{ $n->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $n->author?->name ?? 'Alguém' }} · {{ $n->created_at->format('d/m/Y H:i') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $n->body }}</p>
                        </div>
                        @if ($n->author_user_id === $visitante->userId)
                            <button type="button" wire:click="delete('{{ $n->id }}')" wire:confirm="Apagar esta nota?" class="btn-ghost shrink-0 px-2 py-1 text-xs text-red-700 dark:text-red-400">Apagar</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($visitante->isConsultant() && $privadas->isNotEmpty())
        <h3 class="mt-5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            Notas privadas <span class="badge bg-slate-100 font-normal normal-case text-slate-600 dark:bg-white/10 dark:text-slate-300">o cliente não vê</span>
        </h3>
        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($privadas as $n)
                <li class="py-3" wire:key="nota-privada-{{ $n->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $n->created_at->format('d/m/Y H:i') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $n->body }}</p>
                        </div>
                        <button type="button" wire:click="delete('{{ $n->id }}')" wire:confirm="Apagar esta nota privada?" class="btn-ghost shrink-0 px-2 py-1 text-xs text-red-700 dark:text-red-400">Apagar</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
