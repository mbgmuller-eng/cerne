@php
    $chips = [
        'ok' => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
        'due' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
        'late' => 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300',
        'never' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
    ];
    $estados = [
        'done' => ['Feito', $chips['ok']],
        'progress' => ['Em andamento', $chips['due']],
        'open' => ['Em aberto', $chips['never']],
        'missed' => ['Não registrado', $chips['late']],
    ];
    $variasPessoas = $membros->count() > 1;
@endphp
<div class="space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Check-ups e rotinas</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O que repetir de tempos em tempos, se está em dia e o histórico. Avisamos 60 dias antes e no dia.</p>
        </div>
        <button type="button" data-write wire:click="newRoutine" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Nova rotina</button>
    </div>

    @if ($rotinas->isEmpty())
        <section class="card p-6 text-center" data-sem-rotinas>
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma rotina cadastrada.</p>
            <p class="mt-1 text-xs text-slate-400">Exemplos: check-up geral todo ano, dentista a cada 6 meses.</p>
        </section>
    @else
        <div class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start">
            <div class="space-y-2">
                @foreach ($rotinas as $r)
                    <button type="button" wire:click="select('{{ $r->id }}')" wire:key="rotina-{{ $r->id }}" aria-pressed="{{ $escolhida?->id === $r->id ? 'true' : 'false' }}"
                        @class(['card block w-full p-3 text-left', 'ring-2 ring-saude-800 dark:ring-saude-200' => $escolhida?->id === $r->id])>
                        <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $r->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $variasPessoas ? $r->member->name.' · ' : '' }}{{ $r->frequencyLabel() }}{{ $r->next_due_on ? ' · próxima: '.$r->next_due_on->format('d/m/Y') : '' }}</span>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $chips[$situacoes[$r->id]->value] }}">{{ $situacoes[$r->id]->label() }}</span>
                    </button>
                @endforeach
            </div>

            @if ($escolhida)
                <section class="card min-w-0 space-y-5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ $escolhida->name }}</h2>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $escolhida->member->name }} · {{ $escolhida->frequencyLabel() }} · última vez: {{ $ultimaVez?->format('d/m/Y') ?? 'sem registro' }} · próxima: {{ $escolhida->next_due_on?->format('d/m/Y') ?? 'a definir' }}
                                <x-registered-by :model="$escolhida" />
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $chips[$situacoes[$escolhida->id]->value] }}">{{ $situacoes[$escolhida->id]->label() }}</span>
                            <button type="button" data-write wire:click="editRoutine('{{ $escolhida->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            @if ($confirmingDeleteId === $escolhida->id)
                                <button type="button" data-write wire:click="delete('{{ $escolhida->id }}')" class="text-xs font-medium text-red-700 hover:underline dark:text-red-400">Excluir mesmo</button>
                                <button type="button" wire:click="$set('confirmingDeleteId', null)" class="text-xs text-slate-400">Não</button>
                            @else
                                <button type="button" data-write wire:click="$set('confirmingDeleteId', '{{ $escolhida->id }}')" class="text-xs text-slate-400 hover:text-red-700">Excluir</button>
                            @endif
                        </div>
                    </div>

                    <div>
                        <p class="eyebrow">Histórico por ciclo</p>
                        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10" data-ciclos>
                            @foreach ($ciclos as $ciclo)
                                {{-- No celular o resumo desce para a linha de baixo, em vez de se espremer numa terceira coluna. --}}
                                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                    <span class="min-w-[9rem] text-sm text-slate-800 dark:text-slate-200">{{ $ciclo['label'] }}</span>
                                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $estados[$ciclo['state']][1] }}">{{ $estados[$ciclo['state']][0] }}</span>
                                    <span class="w-full text-xs text-slate-500 dark:text-slate-400 sm:w-auto">{{ $ciclo['summary'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a data-write href="{{ route('health.appointments.index', ['rotina' => $escolhida->id]) }}" class="btn-primary px-3 py-1.5 text-sm">+ Consulta neste ciclo</a>
                        <button type="button" data-write wire:click="openRequest" class="btn-secondary px-3 py-1.5 text-sm">+ Exame pedido</button>
                    </div>

                    @if ($showRequest)
                        <form wire:submit="saveRequest" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                                    <select wire:model="requestKind" class="select mt-1.5 w-full"><option value="exam">Exame</option><option value="consultation">Consulta</option></select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que foi pedido</label>
                                    <input type="text" wire:model="requestTitle" class="input mt-1.5" placeholder="Ex.: Hemograma">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quem pediu (opcional)</label>
                                    <input type="text" wire:model="requestBy" class="input mt-1.5">
                                </div>
                            </div>
                            <x-form-consequence :lines="\App\Support\Forms\FormConsequence::healthRequest()" />
                            <x-form-errors />
                            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Guardar pedido</button>
                        </form>
                    @endif

                    @if ($pedidos->isNotEmpty())
                        <div>
                            <p class="eyebrow">Pedidos sem data</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($pedidos as $p)
                                    <li class="rounded-xl border border-amber-200 p-3 dark:border-amber-500/30" wire:key="pedido-{{ $p->id }}">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="min-w-0 text-sm text-slate-800 dark:text-slate-200">{{ $p->kind->label() }}: {{ $p->title }}</span>
                                            <span class="flex items-center gap-2">
                                                <button type="button" data-write wire:click="startScheduling('{{ $p->id }}')" class="btn-secondary px-3 py-1 text-xs">Marcar data</button>
                                                <button type="button" data-write wire:click="deleteRequest('{{ $p->id }}')" wire:confirm="Excluir este pedido?" class="text-xs text-slate-400 hover:text-red-700">Excluir</button>
                                            </span>
                                        </div>
                                        @if ($schedulingId === $p->id)
                                            @include('livewire.health.partials.schedule-request')
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <p class="eyebrow">Consultas e exames desta rotina</p>
                        @if ($consultas->isEmpty())
                            <p class="mt-2 text-xs text-slate-400">Nada ligado ainda.</p>
                        @else
                            <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                                @foreach ($consultas as $c)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="ligada-{{ $c->id }}">
                                        <span class="min-w-0 text-sm text-slate-800 dark:text-slate-200">{{ $c->scheduled_at->format('d/m/Y') }} · {{ $c->title }} <span class="text-xs text-slate-400">({{ mb_strtolower($c->status->label()) }})</span></span>
                                        <button type="button" data-write wire:click="unlinkAppointment('{{ $c->id }}')" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">tirar desta rotina</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($ligaveis->isNotEmpty())
                            <div data-write class="mt-3 flex flex-wrap items-end gap-2">
                                <div class="min-w-0 flex-1">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ligar o que já está na Agenda</label>
                                    <select wire:model="linkAppointmentId" class="select mt-1.5 w-full">
                                        <option value="">Escolha</option>
                                        @foreach ($ligaveis as $c)
                                            <option value="{{ $c->id }}">{{ $c->scheduled_at->format('d/m/Y') }} · {{ $c->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button" wire:click="linkAppointment" class="btn-secondary px-3 py-2 text-sm">Ligar</button>
                            </div>
                            @error('linkAppointmentId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </section>
            @endif
        </div>
    @endif

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Editar rotina' : 'Nova rotina' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            @if ($variasPessoas && ! $editingId)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model="memberId" class="select mt-1.5 w-full">
                        @foreach ($membros as $m)
                            <option value="{{ $m->id }}">{{ $m->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                <input type="text" wire:model="name" class="input mt-1.5" placeholder="Ex.: Check-up geral">
            </div>
            <div class="grid gap-3 @sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Repete</label>
                    <select wire:model.live="interval" class="select mt-1.5 w-full">
                        @foreach (\App\Models\HealthRoutine::INTERVALS as $meses => $rotulo)
                            <option value="{{ $meses }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Última vez (opcional)</label>
                    <input type="date" wire:model.live="lastDoneOn" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">O que aconteceu antes do Cerne. Depois, vale a última consulta realizada que você ligar.</p>
                </div>
            </div>
            @if (! $editingId)
                <x-form-consequence :lines="$consequencia" />
            @endif
            <x-form-errors />
            <button type="submit" class="btn-primary w-full px-3 py-2 text-sm">{{ $editingId ? 'Salvar alterações' : 'Salvar rotina' }}</button>
        </form>
    </x-modal>
</div>
