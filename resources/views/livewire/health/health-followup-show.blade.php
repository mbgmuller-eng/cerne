<div class="space-y-6">
    <div>
        <a href="{{ route('health.followups.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Acompanhamentos</a>
        <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $acompanhamento->title }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $acompanhamento->member->name }} · desde {{ $acompanhamento->started_on->format('d/m/Y') }} <x-registered-by :model="$acompanhamento" /></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($podeEscrever)
                    <label class="sr-only" for="situacao">Situação</label>
                    <select id="situacao" wire:change="changeStatus($event.target.value)" class="select w-auto">
                        @foreach ($situacoes as $s)
                            <option value="{{ $s->value }}" @selected($acompanhamento->status === $s)>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="rounded-full bg-saude-200/40 px-3 py-1 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200" data-situacao>{{ $acompanhamento->status->label() }}</span>
                @endif
                <a href="{{ route('health.followups.pdf', $acompanhamento->id) }}" class="btn-secondary px-3 py-2 text-sm" data-pdf-medico>PDF para o médico</a>
            </div>
        </div>
    </div>

    <section class="card min-w-0 space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="eyebrow">Linha do tempo</p>
            <div data-write class="flex flex-wrap gap-2">
                <a href="{{ route('health.appointments.index', ['acompanhamento' => $acompanhamento->id]) }}" class="btn-primary px-3 py-1.5 text-xs">+ Consulta</a>
                <a href="{{ route('health.exams.create', ['acompanhamento' => $acompanhamento->id]) }}" wire:navigate class="btn-secondary px-3 py-1.5 text-xs">+ Pedido de exames</a>
                <button type="button" wire:click="open('document')" class="btn-secondary px-3 py-1.5 text-xs">+ Documento</button>
                <button type="button" wire:click="open('note')" class="btn-secondary px-3 py-1.5 text-xs">+ Anotação</button>
                <button type="button" wire:click="open('link')" class="btn-ghost px-2 py-1.5 text-xs">Ligar da Agenda</button>
            </div>
        </div>

        @if ($panel === 'note')
            <form wire:submit="saveNote" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                <div class="grid gap-3 sm:grid-cols-[11rem_minmax(0,1fr)]">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                        <input type="date" wire:model="noteDate" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que aconteceu</label>
                        <input type="text" wire:model="noteText" class="input mt-1.5" placeholder="Ex.: doeu ao subir escada">
                    </div>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Dor de 0 a 10 (opcional)</p>
                    @include('livewire.health.partials.pain-scale', ['modelo' => 'notePain', 'valor' => $notePain])
                </div>
                <x-form-consequence :lines="['A anotação entra na linha do tempo e no PDF para o médico.']" />
                <x-form-errors />
                <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar anotação</button>
            </form>
        @endif

        @if ($panel === 'document')
            <div class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                @if ($consultas->isNotEmpty())
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">É de qual compromisso? (opcional)</label>
                        <select wire:model="documentAppointmentId" class="select mt-1.5 w-full">
                            <option value="">De nenhum em especial</option>
                            @foreach ($consultas as $c)
                                <option value="{{ $c->id }}">{{ $c->scheduled_at->format('d/m/Y') }} · {{ $c->title }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <form wire:submit="uploadDocument" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Título</label>
                            <input type="text" wire:model="documentTitle" class="input mt-1.5" placeholder="Ex.: Laudo da ressonância">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Arquivo (PDF, JPG ou PNG)</label>
                            <input type="file" wire:model="arquivo" accept=".pdf,.jpg,.jpeg,.png" class="mt-1.5 w-full text-sm">
                            <p wire:loading wire:target="arquivo" class="mt-1 text-xs text-slate-400">Enviando o arquivo...</p>
                        </div>
                    </div>
                    <x-form-consequence :lines="['O arquivo vai para os Documentos, como exame de saúde desta pessoa. Quem pode vê-lo segue a regra dos Documentos.']" />
                    <x-form-errors />
                    <button type="submit" class="btn-primary px-3 py-1.5 text-sm" wire:loading.attr="disabled" wire:target="arquivo, uploadDocument">Enviar e ligar</button>
                </form>
                @if ($documentosLigaveis->isNotEmpty())
                    <div class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3 dark:border-white/10">
                        <div class="min-w-0 flex-1">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ou ligue um que já está guardado</label>
                            <select wire:model="existingDocumentId" class="select mt-1.5 w-full">
                                <option value="">Escolha</option>
                                @foreach ($documentosLigaveis as $d)
                                    <option value="{{ $d->id }}">{{ $d->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="linkDocument" class="btn-secondary px-3 py-2 text-sm">Ligar</button>
                    </div>
                @endif
            </div>
        @endif

        @if ($panel === 'link')
            <div class="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                <div class="min-w-0 flex-1">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Consulta ou exame da Agenda</label>
                    <select wire:model="linkAppointmentId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($ligaveis as $c)
                            <option value="{{ $c->id }}">{{ $c->scheduled_at->format('d/m/Y') }} · {{ $c->title }}</option>
                        @endforeach
                    </select>
                    @error('linkAppointmentId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="linkAppointment" class="btn-primary px-3 py-2 text-sm">Ligar</button>
            </div>
        @endif

        @if ($linhas === [])
            <p class="text-xs text-slate-400" data-linha-vazia>Nada na linha do tempo ainda.</p>
        @else
            <ol class="space-y-4" data-linha-do-tempo>
                @foreach ($linhas as $linha)
                    <li class="relative border-l-2 border-slate-200 pl-4 dark:border-white/10" wire:key="linha-{{ $linha['type'] }}-{{ $linha['id'] }}">
                        <span @class(['absolute -left-[7px] top-1.5 h-3 w-3 rounded-full border-[3px] bg-white dark:bg-slate-900', 'border-amber-500' => $linha['pending'], 'border-saude-800 dark:border-saude-200' => ! $linha['pending']])></span>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $linha['date']?->format('d/m/Y') ?? 'Sem data' }} · {{ $linha['label'] }}</p>
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $linha['title'] }}</p>
                        @if ($linha['text'])
                            <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $linha['text'] }}</p>
                        @endif
                        @if ($linha['routine'])
                            <span class="mt-1 inline-block rounded-full bg-saude-200/40 px-2 py-0.5 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">Também em: {{ $linha['routine'] }}</span>
                        @endif
                        @foreach ($linha['documents'] as $d)
                            <span class="mr-2 mt-1 inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-0.5 text-xs dark:border-white/10">
                                <a href="{{ route('documents.vault.file', $d->id) }}" target="_blank" rel="noopener" class="text-brand-800 hover:underline dark:text-brand-300">{{ $d->title }}</a>
                                <button type="button" data-write wire:click="unlinkDocument('{{ $d->id }}')" aria-label="Tirar {{ $d->title }} do acompanhamento" class="text-slate-400 hover:text-red-700">×</button>
                            </span>
                        @endforeach
                        @if ($linha['by'])
                            <p><x-registered-by :model="$linha['by']" /></p>
                        @endif
                        @if ($linha['type'] === 'appointment')
                            <button type="button" data-write wire:click="detachAppointment('{{ $linha['id'] }}')" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">tirar do acompanhamento</button>
                        @elseif ($linha['type'] === 'note')
                            <button type="button" data-write wire:click="deleteNote('{{ $linha['id'] }}')" wire:confirm="Excluir esta anotação?" class="text-xs text-slate-400 hover:text-red-700">excluir</button>
                        @elseif ($linha['type'] === 'request')
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-300">Falta agendar</span>
                                <a href="{{ route('health.exams.show', $linha['id']) }}" wire:navigate class="btn-secondary px-3 py-1 text-xs">Abrir pedido</a>
                            </div>
                        @elseif ($linha['type'] === 'result' && $linha['by']?->request_id)
                            <a href="{{ route('health.exams.show', $linha['by']->request_id) }}" wire:navigate class="text-xs font-medium text-saude-800 hover:underline dark:text-saude-200">Ver o resultado no pedido</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <livewire:health.limitation-panel :followup-id="$acompanhamento->id" :key="'limitacoes-'.$acompanhamento->id" />

    <livewire:health.health-program-panel :followup-id="$acompanhamento->id" :key="'programa-'.$acompanhamento->id" />

    <div data-write class="flex flex-wrap items-center gap-3 text-xs">
        <button type="button" wire:click="open('edit')" class="text-slate-500 hover:underline dark:text-slate-400">Editar nome e data</button>
        @if ($confirmingDelete)
            <span class="text-slate-500 dark:text-slate-400">Anotações, pedidos sem data e o programa saem junto.</span>
            <button type="button" wire:click="delete" class="font-medium text-red-700 hover:underline dark:text-red-400">Excluir mesmo</button>
            <button type="button" wire:click="$set('confirmingDelete', false)" class="text-slate-400">Não</button>
        @else
            <button type="button" wire:click="$set('confirmingDelete', true)" class="text-slate-400 hover:text-red-700">Excluir acompanhamento</button>
        @endif
    </div>

    @if ($panel === 'edit')
        <form wire:submit="saveEdit" class="card space-y-3 p-5">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do problema</label>
                    <input type="text" wire:model="title" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Desde quando</label>
                    <input type="date" wire:model="startedOn" class="input mt-1.5">
                </div>
            </div>
            <x-form-errors />
            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar alterações</button>
        </form>
    @endif
</div>
