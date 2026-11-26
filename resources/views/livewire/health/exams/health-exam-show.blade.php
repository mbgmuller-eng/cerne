<div class="space-y-6">
    <div>
        <a href="{{ route('health.exams.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Exames</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $pedido->title }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $pedido->member->name }} · pedido em {{ $pedido->requested_on?->format('d/m/Y') ?? 'data não informada' }}{{ $pedido->requested_by ? ' por '.$pedido->requested_by : '' }}
            @if ($pedido->routine) · <a href="{{ route('health.routines.index', ['rotina' => $pedido->routine->id]) }}" wire:navigate class="underline">{{ $pedido->routine->name }}</a> @endif
            @if ($pedido->followup) · <a href="{{ route('health.followups.show', $pedido->followup->id) }}" wire:navigate class="underline">{{ $pedido->followup->title }}</a> @endif
            <x-registered-by :model="$pedido" />
        </p>
        @if ($pedido->notes)
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200"><strong>Observações:</strong> {{ $pedido->notes }}</p>
        @endif
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-100 px-4 py-2 text-sm text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @include('livewire.health.exams.partials.open-readings', ['leituras' => $leituras, 'variasPessoas' => false])

    @if ($separacao && $podeEscrever)
        <section class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" data-separar>
            <p class="font-medium">Este pedido tem exames de {{ count($separacao['groups']) }} áreas.</p>
            @if (! $splitting)
                <button type="button" wire:click="$set('splitting', true)" class="btn-secondary px-3 py-1.5 text-xs">Separar por área</button>
            @else
                <ul class="space-y-2">
                    @foreach ($separacao['groups'] as $area => $doGrupo)
                        <li>
                            <strong>{{ $area === $separacao['primary'] ? $pedido->title : $areas[$area][1] }}</strong>
                            ({{ $areas[$area][0] }}, {{ $doGrupo->count() }}{{ $area === $separacao['primary'] ? ', continua neste pedido' : ', pedido novo' }}):
                            {{ $doGrupo->pluck('name')->implode(', ') }}
                        </li>
                    @endforeach
                </ul>
                <x-form-consequence :lines="\App\Support\Forms\FormConsequence::examSplit(count($separacao['groups']))" />
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="split" class="btn-primary px-3 py-1.5 text-sm">Confirmar separação</button>
                    <button type="button" wire:click="$set('splitting', false)" class="text-xs underline">Cancelar</button>
                </div>
            @endif
        </section>
    @endif

    <section class="card min-w-0 space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="eyebrow">Exames pedidos</p>
            <button type="button" data-write wire:click="open('add')" class="btn-ghost px-2 py-1 text-xs">+ Exame</button>
        </div>

        @if ($panel === 'add')
            <form wire:submit="addItem" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Exame</label>
                        <input type="text" wire:model="newName" list="exames-sugeridos" class="input mt-1.5" placeholder="Digite o nome">
                        <datalist id="exames-sugeridos">
                            @foreach ($sugestoes as $s)
                                <option value="{{ $s }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Detalhe do pedido (opcional)</label>
                        <input type="text" wire:model="newDetail" class="input mt-1.5">
                    </div>
                </div>
                <x-form-errors />
                <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Adicionar exame</button>
            </form>
        @endif

        <ul class="divide-y divide-slate-100 dark:divide-white/10" data-itens>
            @foreach ($pedido->items as $item)
                @php $situacao = $item->status(); @endphp
                <li class="flex flex-wrap items-start justify-between gap-2 py-3" wire:key="item-{{ $item->id }}">
                    <label class="flex min-w-0 flex-1 basis-60 items-start gap-3">
                        @if ($situacao === \App\Enums\HealthExamItemStatus::Pending && $podeEscrever)
                            <input type="checkbox" wire:model.live="selected" value="{{ $item->id }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        @else
                            <span class="w-4 shrink-0"></span>
                        @endif
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ $item->name }}</span>
                            @if ($item->detail)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->detail }}</span>
                            @endif
                            @if ($item->appointment && in_array($situacao, [\App\Enums\HealthExamItemStatus::Scheduled, \App\Enums\HealthExamItemStatus::Done], true))
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->appointment->scheduled_at->format('d/m/Y H:i') }}{{ $item->appointment->location ? ' · '.$item->appointment->location : '' }}</span>
                            @endif
                        </span>
                    </label>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $situacao->chip() }}">{{ $situacao->label() }}</span>
                        @if ($situacao !== \App\Enums\HealthExamItemStatus::Result)
                            <button type="button" data-write wire:click="removeItem('{{ $item->id }}')" wire:confirm="Tirar este exame do pedido?" class="text-xs text-slate-400 hover:text-red-700">remover</button>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
        @error('selected') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

        @if ($scheduling)
            <form wire:submit="schedule" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                <div class="grid gap-3 sm:grid-cols-[10rem_8rem_minmax(0,1fr)]">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                        <input type="date" wire:model="date" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Hora</label>
                        <input type="time" wire:model="time" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Local (opcional)</label>
                        <input type="text" wire:model="place" class="input mt-1.5">
                    </div>
                </div>
                <x-form-consequence :lines="$consequencia" />
                <x-form-errors />
                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar na Agenda</button>
                    <button type="button" wire:click="$set('scheduling', false)" class="text-xs text-slate-400">Cancelar</button>
                </div>
            </form>
        @elseif ($pedido->items->contains(fn ($i) => $i->status() === \App\Enums\HealthExamItemStatus::Pending))
            <div data-write class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="startScheduling" class="btn-primary px-3 py-1.5 text-sm">Marcar data{{ count($selected) > 0 ? ' ('.count($selected).')' : '' }}</button>
                <span class="text-xs text-slate-400">Marque juntos os que serão feitos no mesmo dia e local.</span>
            </div>
        @endif
    </section>

    <section class="card min-w-0 space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="eyebrow">Resultados</p>
            <a href="{{ route('health.exams.results') }}" wire:navigate class="text-xs font-medium text-saude-800 hover:underline dark:text-saude-200">Comparar com os anteriores</a>
        </div>
        <div class="flex flex-wrap items-start gap-2">
            <livewire:health.exams.health-exam-upload :pedido-id="$pedido->id" :key="'envio-'.$pedido->id" />
            <a data-write href="{{ route('health.exams.manual', ['pedido' => $pedido->id]) }}" wire:navigate class="btn-ghost px-3 py-2 text-sm">Digitar à mão</a>
        </div>
        @if ($pedido->reports->isEmpty())
            <p class="text-xs text-slate-400">Nenhum resultado guardado neste pedido.</p>
        @else
            <ul class="divide-y divide-slate-100 dark:divide-white/10" data-laudos>
                @foreach ($pedido->reports as $laudo)
                    <li class="py-3" wire:key="laudo-{{ $laudo->id }}">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $laudo->performed_on->format('d/m/Y') }} · {{ $laudo->exam_name ?? 'Resultados de exames' }}{{ $laudo->lab ? ' · '.$laudo->lab : '' }}</p>
                        @if ($laudo->isValues())
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                {{ $laudo->values->count() }} {{ $laudo->values->count() === 1 ? 'resultado' : 'resultados' }}:
                                @foreach ($laudo->values as $v)
                                    @php $fora = \App\Support\Health\ReferenceRange::flag($v->value, $v->reference); @endphp
                                    <span class="whitespace-nowrap">{{ $v->name }} {{ $v->value }}@if ($fora !== '') <span class="font-semibold text-orange-700 dark:text-orange-300" title="{{ \App\Support\Health\ReferenceRange::flagLabel($fora) }}">{{ $fora === 'above' ? '▲' : '▼' }}</span>@endif</span>@if (! $loop->last);@endif
                                @endforeach
                            </p>
                        @else
                            <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $laudo->conclusion }}</p>
                            @if ($laudo->highlight)
                                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $laudo->highlight }}</p>
                            @endif
                        @endif
                        <p class="mt-1 flex flex-wrap items-center gap-3 text-xs">
                            @if ($laudo->document)
                                <a href="{{ route('documents.vault.file', $laudo->document->id) }}" target="_blank" rel="noopener" class="text-brand-800 hover:underline dark:text-brand-300">Abrir o PDF</a>
                            @endif
                            <button type="button" data-write wire:click="deleteReport('{{ $laudo->id }}')" wire:confirm="Excluir este resultado? O PDF continua nos Documentos." class="text-slate-400 hover:text-red-700">excluir resultado</button>
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div data-write class="flex flex-wrap items-center gap-3 text-xs">
        <button type="button" wire:click="open('edit')" class="text-slate-500 hover:underline dark:text-slate-400">Editar nome e observações</button>
        @if ($confirmingDelete)
            <span class="text-slate-500 dark:text-slate-400">Os resultados guardados e os compromissos continuam.</span>
            <button type="button" wire:click="delete" class="font-medium text-red-700 hover:underline dark:text-red-400">Excluir mesmo</button>
            <button type="button" wire:click="$set('confirmingDelete', false)" class="text-slate-400">Não</button>
        @else
            <button type="button" wire:click="$set('confirmingDelete', true)" class="text-slate-400 hover:text-red-700">Excluir pedido</button>
        @endif
    </div>

    @if ($panel === 'edit')
        <form wire:submit="saveEdit" class="card space-y-3 p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do pedido</label>
                    <input type="text" wire:model="title" class="input mt-1.5">
                </div>
                <x-health.professional-field model="requestedBy" label="Quem pediu (opcional)" :field="$campoProfissional" />
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações do pedido</label>
                <textarea wire:model="notes" rows="2" class="input mt-1.5"></textarea>
            </div>
            <x-form-errors />
            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar alterações</button>
        </form>
    @endif
</div>
