<div class="space-y-6">
    <div>
        <a href="{{ route('health.exams.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Exames</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Novo pedido de exame</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Marque na lista o que o médico pediu. O Cerne não sugere quais exames fazer.</p>
    </div>

    <form wire:submit="save" class="grid gap-4 lg:grid-cols-2 lg:items-start">
        <section class="card min-w-0 space-y-3 p-5">
            <p class="eyebrow">O que o médico pediu</p>
            @if ($pessoas->count() > 1)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é o pedido</label>
                    <select wire:model.live="memberId" class="select mt-1.5 w-full">
                        @foreach ($pessoas as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex flex-wrap gap-2" role="group" aria-label="Tipo de exame">
                @foreach ($categorias as $chave => [$rotulo])
                    <button type="button" wire:click="chooseCategory('{{ $chave }}')" aria-pressed="{{ $category === $chave ? 'true' : 'false' }}"
                        @class(['rounded-full border px-3 py-1.5 text-xs font-medium', 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-saude-800' => $category === $chave, 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-200' => $category !== $chave])>{{ $rotulo }}</button>
                @endforeach
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar em todos os tipos</label>
                <input type="text" wire:model.live.debounce.250ms="search" class="input mt-1.5" placeholder="Ex.: eco, colesterol, esteira">
            </div>
            @if ($anterior && $anterior->items->isNotEmpty())
                <button type="button" wire:click="repeatPrevious" class="btn-secondary px-3 py-1.5 text-xs" data-repetir>
                    Repetir o pedido de {{ $anterior->requested_on?->format('m/Y') ?? 'antes' }} ({{ $anterior->items->count() }} {{ $anterior->items->count() === 1 ? 'exame' : 'exames' }})
                </button>
            @endif
            {{-- A folga dos lados (px-1) é necessária: a área com rolagem corta o que encosta na borda, e a caixa de marcação perdia o lado esquerdo. --}}
            <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto px-1 dark:divide-white/10" data-lista-exames>
                @foreach ($opcoes as $o)
                    @php $marcado = in_array(\App\Support\Health\ExamCatalog::normalize($o['name']), $marcados, true); @endphp
                    <li wire:key="opcao-{{ md5($o['name']) }}">
                        <label class="flex cursor-pointer items-start gap-3 py-2">
                            <input type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500" wire:click="toggle({{ \Illuminate\Support\Js::from($o['name']) }})" @checked($marcado)>
                            <span class="min-w-0 text-sm text-slate-800 dark:text-slate-200">{{ $o['name'] }}@if ($o['hint'])<span class="block text-xs text-slate-400">{{ $o['hint'] }}</span>@endif</span>
                        </label>
                    </li>
                @endforeach
            </ul>
            @if ($podeAdicionar)
                <div>
                    <button type="button" wire:click="addTyped" class="btn-secondary px-3 py-1.5 text-xs">+ Adicionar “{{ $search }}”</button>
                    <p class="mt-1 text-xs text-slate-400">Fica disponível nas próximas vezes.</p>
                </div>
            @endif
        </section>

        <section class="card min-w-0 space-y-3 p-5">
            <p class="eyebrow">O pedido</p>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do pedido</label>
                <input type="text" wire:model.blur="title" class="input mt-1.5">
            </div>
            <div class="grid gap-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pedido em</label>
                    <input type="date" wire:model="requestedOn" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quem pediu (opcional)</label>
                    <input type="text" wire:model="requestedBy" class="input mt-1.5">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações do pedido</label>
                <textarea wire:model="notes" rows="2" class="input mt-1.5" placeholder="Ex.: jejum de 12 horas"></textarea>
            </div>
            @if ($rotinas->isNotEmpty() || $acompanhamentos->isNotEmpty())
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Check-up ou rotina (opcional)</label>
                        <select wire:model="routineId" class="select mt-1.5 w-full">
                            <option value="">Nenhum</option>
                            @foreach ($rotinas as $r)
                                <option value="{{ $r->id }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Acompanhamento (opcional)</label>
                        <select wire:model="followupId" class="select mt-1.5 w-full">
                            <option value="">Nenhum</option>
                            @foreach ($acompanhamentos as $a)
                                <option value="{{ $a->id }}">{{ $a->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif

            <p class="eyebrow pt-2">Exames marcados: {{ count($selected) }}</p>
            @if ($selected === [])
                <p class="text-xs text-slate-400">Nenhum exame marcado.</p>
            @else
                <ul class="space-y-3" data-marcados>
                    @foreach ($selected as $i => $s)
                        <li class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="marcado-{{ md5($s['name']) }}">
                            <div class="flex items-start justify-between gap-3">
                                <span class="min-w-0 text-sm font-medium text-slate-800 dark:text-slate-200">{{ $s['name'] }}</span>
                                <button type="button" wire:click="remove({{ $i }})" class="shrink-0 text-xs text-slate-400 hover:text-red-700">remover</button>
                            </div>
                            <label class="mt-2 block text-xs font-medium text-slate-500 dark:text-slate-400">Detalhe do pedido (opcional)</label>
                            <input type="text" wire:model="selected.{{ $i }}.detail" class="input mt-1.5" placeholder="Ex.: região, lado, com contraste">
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <button type="submit" class="btn-primary px-4 py-2 text-sm">Guardar pedido</button>
        </section>
    </form>
</div>
