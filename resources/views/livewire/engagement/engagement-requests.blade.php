<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Demandas</h2>
        @if ($visitante->isClient())
            <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-xs">Nova demanda</button>
        @endif
    </div>

    @if ($ativas->isEmpty())
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
            @if ($visitante->isClient())
                Alguma dúvida ou pedido para o seu consultor? Abra uma demanda e ele responde por aqui.
            @else
                Nenhuma demanda aberta.
            @endif
        </p>
    @else
        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($ativas as $d)
                <li class="py-3" wire:key="demanda-{{ $d->id }}">
                    <button type="button" wire:click="toggle('{{ $d->id }}')" class="flex w-full items-start justify-between gap-3 text-left">
                        <span class="min-w-0">
                            <span class="block text-sm text-slate-800 dark:text-slate-200">{{ $d->title }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">aberta em {{ $d->created_at->format('d/m') }} · {{ $d->comments->count() }} {{ $d->comments->count() === 1 ? 'mensagem' : 'mensagens' }}</span>
                        </span>
                        <span @class([
                            'badge shrink-0',
                            'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300' => $d->status === \App\Enums\EngagementRequestStatus::Open,
                            'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $d->status === \App\Enums\EngagementRequestStatus::Answered,
                        ])>{{ $d->status->label() }}</span>
                    </button>

                    @if ($openId === $d->id)
                        <div class="mt-3 space-y-3">
                            <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $d->body }}</p>

                            @foreach ($d->comments as $c)
                                <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5" wire:key="comentario-{{ $c->id }}">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $c->author?->name ?? 'Alguém' }} · {{ $c->created_at->format('d/m H:i') }}</p>
                                    <p class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $c->body }}</p>
                                </div>
                            @endforeach

                            <form wire:submit="sendReply" class="space-y-2">
                                <textarea wire:model="reply" rows="2" maxlength="3000" class="input" placeholder="Escreva uma mensagem"></textarea>
                                @error('reply') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="btn-primary px-3 py-1.5 text-xs">Enviar</button>
                                    <button type="button" wire:click="resolve('{{ $d->id }}')" class="btn-ghost px-3 py-1.5 text-xs">Marcar como resolvida</button>
                                    @if ($visitante->isConsultant() && ! $d->task_id)
                                        <button type="button" wire:click="convertToTask('{{ $d->id }}')" class="btn-ghost px-3 py-1.5 text-xs">Transformar em tarefa</button>
                                    @endif
                                </div>
                            </form>

                            @if ($d->task)
                                <p class="text-xs text-slate-400">Virou a tarefa “{{ $d->task->title }}”.</p>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($resolvidas->isNotEmpty())
        <details class="mt-4 text-xs">
            <summary class="cursor-pointer text-slate-500 dark:text-slate-400">{{ $resolvidas->count() }} resolvida{{ $resolvidas->count() === 1 ? '' : 's' }}</summary>
            <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                @foreach ($resolvidas as $d)
                    <li class="flex items-center justify-between gap-3" wire:key="resolvida-{{ $d->id }}">
                        <span class="min-w-0 truncate">{{ $d->title }}</span>
                        <span class="shrink-0 text-slate-400">{{ $d->resolved_at?->format('d/m') }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Nova demanda</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Assunto</label>
                <input type="text" wire:model="title" maxlength="160" class="input mt-1.5" placeholder="Ex.: Posso antecipar o financiamento?">
                @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conte o que você precisa</label>
                <textarea wire:model="body" rows="4" maxlength="3000" class="input mt-1.5"></textarea>
                @error('body') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Enviar ao consultor</button>
            </div>
        </form>
    </x-modal>
</section>
