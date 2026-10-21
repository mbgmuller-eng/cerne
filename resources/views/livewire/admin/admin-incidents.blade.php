@use('App\Models\SecurityIncident')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Incidentes de segurança</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                Registro de vazamento, acesso indevido ou perda de dado pessoal. Se o risco for relevante, a ANPD e as pessoas afetadas precisam ser comunicadas em até
                3 dias úteis depois que descobrimos. O roteiro passo a passo está em <span class="font-mono text-xs">INCIDENTES.md</span>.
            </p>
        </div>
        <button type="button" wire:click="novo" class="btn-primary px-3 py-1.5 text-sm">Registrar incidente</button>
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">{{ session('status') }}</p>
    @endif

    <div class="space-y-3">
        @forelse ($incidentes as $i)
            <div class="card space-y-3 p-5" wire:key="inc-{{ $i->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $i->title }}</p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Descoberto em {{ $i->detected_at->format('d/m/Y H:i') }}
                            @if ($i->occurred_at) · ocorreu em {{ $i->occurred_at->format('d/m/Y H:i') }} @endif
                            · risco {{ mb_strtolower(SecurityIncident::RISKS[$i->risk] ?? $i->risk) }}
                            @if ($i->people_affected !== null) · {{ $i->people_affected }} {{ $i->people_affected === 1 ? 'pessoa afetada' : 'pessoas afetadas' }} @endif
                        </p>
                    </div>
                    <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-white/10" role="group" aria-label="Situação">
                        @foreach (SecurityIncident::STATUSES as $valor => $rotulo)
                            <button type="button" wire:click="mudarStatus({{ $i->id }}, '{{ $valor }}')"
                                    class="rounded-md px-3 py-1 {{ $i->status === $valor ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 dark:text-slate-400' }}">{{ $rotulo }}</button>
                        @endforeach
                    </div>
                </div>

                <p class="text-sm whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $i->description }}</p>

                @if ($i->data_affected)<p class="text-xs text-slate-500 dark:text-slate-400"><span class="font-medium">Dados atingidos:</span> {{ $i->data_affected }}</p>@endif
                @if ($i->measures)<p class="text-xs text-slate-500 dark:text-slate-400"><span class="font-medium">Providências:</span> {{ $i->measures }}</p>@endif

                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-100 pt-3 text-xs dark:border-white/10">
                    <div>
                        <span class="text-slate-500 dark:text-slate-400">ANPD:</span>
                        @if ($i->anpd_notified_at)
                            <span class="text-slate-800 dark:text-slate-200">comunicada em {{ $i->anpd_notified_at->format('d/m/Y') }}</span>
                        @elseif ($i->risk === 'low')
                            <span class="text-slate-500">risco baixo: comunicação não obrigatória, registre a decisão</span>
                        @else
                            <span class="{{ $i->isOverdue() ? 'font-medium text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400' }}">
                                {{ $i->isOverdue() ? 'PRAZO VENCIDO em' : 'comunicar até' }} {{ $i->notificationDeadline()->format('d/m/Y') }}
                            </span>
                            <button type="button" wire:click="marcarAnpd({{ $i->id }})" class="ml-2 text-accent-700 hover:underline dark:text-accent-400">Marcar como comunicada</button>
                        @endif
                    </div>
                    <div>
                        <span class="text-slate-500 dark:text-slate-400">Pessoas afetadas:</span>
                        @if ($i->people_notified_at)
                            <span class="text-slate-800 dark:text-slate-200">comunicadas em {{ $i->people_notified_at->format('d/m/Y') }}</span>
                        @else
                            <span class="text-slate-500">ainda não comunicadas</span>
                            <button type="button" wire:click="marcarTitulares({{ $i->id }})" class="ml-2 text-accent-700 hover:underline dark:text-accent-400">Marcar como comunicadas</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Nenhum incidente registrado. É o que se espera, mas quando houver, registre aqui.</div>
        @endforelse
    </div>

    <x-modal wire-model="showForm" max-width="2xl">
        <form wire:submit="salvar" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar incidente</h2>
                <button type="button" wire:click="fechar" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Título</label>
                <input type="text" wire:model="title" class="input mt-1.5" maxlength="160">
                @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que aconteceu</label>
                <textarea wire:model="description" rows="4" class="input mt-1.5"></textarea>
                @error('description') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quando descobrimos</label>
                    <input type="datetime-local" wire:model="detectedAt" class="input mt-1.5">
                    @error('detectedAt') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quando ocorreu (se souber)</label>
                    <input type="datetime-local" wire:model="occurredAt" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Risco para as pessoas</label>
                    <select wire:model="risk" class="select mt-1.5 w-full">
                        @foreach (SecurityIncident::RISKS as $valor => $rotulo) <option value="{{ $valor }}">{{ $rotulo }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantas pessoas (se souber)</label>
                    <input type="number" min="0" wire:model="peopleAffected" class="input mt-1.5">
                    @error('peopleAffected') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Que dados foram atingidos</label>
                <textarea wire:model="dataAffected" rows="2" class="input mt-1.5" placeholder="Ex.: nome, e-mail e CPF; dado de saúde; extratos"></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Providências tomadas</label>
                <textarea wire:model="measures" rows="3" class="input mt-1.5"></textarea>
            </div>
            <button type="submit" class="btn-primary w-full">Registrar</button>
        </form>
    </x-modal>
</div>
