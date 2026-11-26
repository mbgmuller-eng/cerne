@php $variasPessoas = $membros->count() > 1; @endphp
<div class="space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Acompanhamentos</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Um problema de cada vez, com consultas, exames, documentos, anotações e o programa de atividades.</p>
        </div>
        <button type="button" data-write wire:click="newFollowup" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Novo</button>
    </div>

    @foreach ([['Em andamento', $ativos], ['Resolvidos', $resolvidos]] as [$rotulo, $lista])
        @if ($lista->isNotEmpty() || $rotulo === 'Em andamento')
            <section class="card space-y-3 p-5">
                <p class="eyebrow">{{ $rotulo }}</p>
                @if ($lista->isEmpty())
                    <p class="text-xs text-slate-400" data-sem-acompanhamentos>Nenhum acompanhamento em andamento. Abra um quando surgir um problema, daqui ou a partir de uma consulta da Agenda.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($lista as $a)
                            <li wire:key="acomp-{{ $a->id }}">
                                <a href="{{ route('health.followups.show', $a->id) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 p-3 hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/5">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $a->title }}</span>
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $variasPessoas ? $a->member->name.' · ' : '' }}desde {{ $a->started_on->format('d/m/Y') }}@if ($a->resolved_on), resolvido em {{ $a->resolved_on->format('d/m/Y') }}@endif <x-registered-by :model="$a" /></span>
                                    </span>
                                    <span class="rounded-full bg-saude-200/40 px-2.5 py-0.5 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">{{ $a->status->label() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endforeach

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Novo acompanhamento</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            @if ($variasPessoas)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model.live="memberId" class="select mt-1.5 w-full">
                        @foreach ($membros as $m)
                            <option value="{{ $m->id }}">{{ $m->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do problema</label>
                <input type="text" wire:model="title" class="input mt-1.5" placeholder="Ex.: Dor no ombro direito">
            </div>
            <div class="grid gap-3 @sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                    <select wire:model="status" class="select mt-1.5 w-full">
                        @foreach ($situacoes as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Desde quando</label>
                    <input type="date" wire:model="startedOn" class="input mt-1.5">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Consulta de origem (opcional)</label>
                <select wire:model.live="appointmentId" class="select mt-1.5 w-full">
                    <option value="">Nenhuma</option>
                    @foreach ($origens as $c)
                        <option value="{{ $c->id }}">{{ $c->scheduled_at->format('d/m/Y') }} · {{ $c->title }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-400">A consulta continua no check-up dela, se estiver em um: não é preciso cadastrar de novo.</p>
            </div>
            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <button type="submit" class="btn-primary w-full px-3 py-2 text-sm">Criar acompanhamento</button>
        </form>
    </x-modal>
</div>
