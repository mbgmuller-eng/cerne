{{--
    O editor da regra (a tela do cliente e o modelo do consultor usam este mesmo bloco). Variáveis: $draft (a estrutura em edição), $presets,
    $modelos (modelos do consultor, ou vazio), $categoryOptions, $editorErros (o que impede de salvar), $previa (o relatório do mês com a regra em
    edição, quando válida), $saveAction (o método de salvar), $showStart (mostra "vale a partir de"), $ano e $mes (o mês da prévia).
--}}
@use('App\Support\Money')
@use('App\Support\Budget\BudgetStructure')

@php
    $somaAreas = '0';
    foreach (['essential', 'discretionary', 'investment'] as $a) {
        $somaAreas = bcadd($somaAreas, \App\Support\Budget\BudgetStructure::percent($draft['areas'][$a] ?? '0'), 4);
    }
    $somaGrupos = '0';
    foreach ($draft['groups'] ?? [] as $g) {
        $somaGrupos = bcadd($somaGrupos, \App\Support\Budget\BudgetStructure::percent($g['percent'] ?? '0'), 4);
    }
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
@endphp

<div class="space-y-5" data-budget-editor>
    <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editorTitle ?? 'Regra de orçamento' }}</h2>
        <button type="button" wire:click="closeEditor" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
    </div>

    <div>
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Começar de um modelo</p>
        <div class="mt-1.5 flex flex-wrap gap-2">
            @foreach ($presets as $chave => $preset)
                <button type="button" wire:click="usePreset('{{ $chave }}')" class="btn-secondary px-3 py-1.5 text-xs" title="{{ $preset['description'] }}">{{ $preset['name'] }}</button>
            @endforeach
            @foreach ($modelos ?? [] as $modelo)
                <button type="button" wire:click="useTemplate('{{ $modelo->id }}')" class="btn-secondary px-3 py-1.5 text-xs" title="{{ $modelo->description }}">Meu modelo: {{ $modelo->name }}</button>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-slate-400">Escolher um modelo troca o que está na tela. Nada é gravado até você salvar.</p>
    </div>

    {{-- Áreas --}}
    <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Quanto da receita vai para cada área</p>
        <div class="mt-3 grid gap-3 @sm:grid-cols-3 *:min-w-0">
            @foreach (['essential' => 'Essencial (%)', 'discretionary' => 'Supérfluo (%)', 'investment' => 'Investimentos (%)'] as $chave => $rotulo)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $rotulo }}</label>
                    <input type="text" inputmode="decimal" wire:model.live.blur="draft.areas.{{ $chave }}" class="input mt-1.5" data-area="{{ $chave }}">
                </div>
            @endforeach
        </div>
        <p @class(['mt-2 text-xs', 'text-slate-400' => abs((float) $somaAreas - 100) <= 0.05, 'text-red-700 dark:text-red-400' => abs((float) $somaAreas - 100) > 0.05]) data-areas-sum>
            Somam {{ $fmt($somaAreas) }}%. {{ abs((float) $somaAreas - 100) <= 0.05 ? 'Fechou em 100%.' : 'Precisam somar 100%.' }}
        </p>
    </div>

    {{-- Grupos de investimentos --}}
    <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Como dividir o que vai para investimentos</p>
            <button type="button" wire:click="addGroup" class="btn-ghost px-2 py-1 text-xs">+ Grupo</button>
        </div>
        <p @class(['mt-1 text-xs', 'text-slate-400' => abs((float) $somaGrupos - 100) <= 0.05 || empty($draft['groups']), 'text-red-700 dark:text-red-400' => ! empty($draft['groups']) && abs((float) $somaGrupos - 100) > 0.05]) data-groups-sum>
            Os grupos somam {{ $fmt($somaGrupos) }}% do que vai para investimentos.
        </p>

        <div class="mt-3 space-y-4">
            @foreach ($draft['groups'] ?? [] as $gi => $grupo)
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5" wire:key="grupo-{{ $gi }}" data-group>
                    <div class="grid gap-3 @sm:grid-cols-[1fr_8rem_auto] @sm:items-end *:min-w-0">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Grupo</label>
                            <input type="text" wire:model.blur="draft.groups.{{ $gi }}.name" maxlength="60" class="input mt-1.5" placeholder="Ex.: Reservas">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parte (%)</label>
                            <input type="text" inputmode="decimal" wire:model.live.blur="draft.groups.{{ $gi }}.percent" class="input mt-1.5">
                        </div>
                        <button type="button" wire:click="removeGroup({{ $gi }})" wire:confirm="Tirar o grupo e os itens dele?" class="btn-ghost px-2 py-2 text-xs text-red-700 dark:text-red-400">Tirar grupo</button>
                    </div>

                    <div class="mt-3 space-y-3">
                        @foreach ($grupo['items'] ?? [] as $ii => $item)
                            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-transparent" wire:key="item-{{ $gi }}-{{ $ii }}" data-item>
                                <div class="grid gap-3 @sm:grid-cols-[1fr_12rem_6rem_auto] @sm:items-end *:min-w-0">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Item</label>
                                        <input type="text" wire:model.blur="draft.groups.{{ $gi }}.items.{{ $ii }}.name" maxlength="60" class="input mt-1.5" placeholder="Ex.: Reserva de paz">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                                        <select wire:model.live="draft.groups.{{ $gi }}.items.{{ $ii }}.kind" class="select mt-1.5 w-full">
                                            @foreach (BudgetStructure::KINDS as $valor => $rotulo)
                                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if (($item['kind'] ?? 'percent') !== 'fixed')
                                        <div>
                                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parte (%)</label>
                                            <input type="text" inputmode="decimal" wire:model.live.blur="draft.groups.{{ $gi }}.items.{{ $ii }}.percent" class="input mt-1.5">
                                        </div>
                                    @else
                                        <div></div>
                                    @endif
                                    <button type="button" wire:click="removeItem({{ $gi }}, {{ $ii }})" class="btn-ghost px-2 py-2 text-xs text-red-700 dark:text-red-400">Tirar</button>
                                </div>

                                @if (($item['kind'] ?? 'percent') === 'fixed')
                                    <div class="mt-2">
                                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">De onde vem o valor (o que está cadastrado)</p>
                                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                            @foreach (BudgetStructure::SOURCES as $valor => $rotulo)
                                                <label class="flex items-center gap-1.5 text-xs text-slate-700 dark:text-slate-300">
                                                    <input type="checkbox" wire:model.live="draft.groups.{{ $gi }}.items.{{ $ii }}.sources" value="{{ $valor }}" class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                    {{ $rotulo }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="mt-2">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Categorias de investimento que formam este item</p>
                                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                        @foreach ($categoryOptions as $categoria)
                                            <label class="flex items-center gap-1.5 text-xs text-slate-700 dark:text-slate-300">
                                                <input type="checkbox" wire:model.live="draft.groups.{{ $gi }}.items.{{ $ii }}.categories" value="{{ $categoria }}" class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                {{ $categoria }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addItem({{ $gi }})" class="btn-ghost px-2 py-1 text-xs">+ Item em {{ $grupo['name'] !== '' ? $grupo['name'] : 'este grupo' }}</button>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-slate-400">
            Compromisso fixo (seguro de vida, parcela de consórcio, conta fixa) sai do grupo ANTES: o que sobra é que se divide pelos percentuais. O item "Objetivos" é o que a divisão entre objetivos reparte.
            Cada categoria só pode estar em um item.
        </p>
    </div>

    @if ($showStart ?? false)
        <div class="max-w-xs">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vale a partir de</label>
            <input type="month" wire:model="startYm" class="input mt-1.5" data-start-month>
            <p class="mt-1 text-xs text-slate-400">Os meses anteriores seguem a regra que valia.</p>
            @error('startYm') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    @endif

    @if (count($editorErros) > 0)
        <ul class="space-y-0.5 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20" data-editor-errors>
            @foreach ($editorErros as $erro)
                <li>{{ $erro }}</li>
            @endforeach
        </ul>
    @endif

    @if (($showStart ?? false) && $previa !== null)
        @php
            $linhas = [];
            $linhas[] = 'Vale a partir de '.(preg_match('/^(\d{4})-(\d{2})$/', $startYm, $m) === 1 ? $m[2].'/'.$m[1] : 'o mês escolhido').'; os meses anteriores seguem a regra que valia.';
            if (bccomp($previa['income']['total'], '0', 2) > 0) {
                $inv = $previa['areas']['investment'];
                $linhas[] = 'Com a receita de '.sprintf('%02d/%04d', $mes, $ano).' ('.Money::format($previa['income']['total']).'): essencial '.Money::format($previa['areas']['essential']['target']).', supérfluo '.Money::format($previa['areas']['discretionary']['target']).' e investimentos '.Money::format($inv['target']).'.';
                foreach ($inv['groups'] as $g) {
                    $linhas[] = $g['name'].': '.Money::format($g['target']).($g['fixed_total'] !== '0.00' ? ' (já com '.Money::format($g['fixed_total']).' de compromissos fixos)' : '').'.';
                }
            } else {
                $linhas[] = 'Sem receita em '.sprintf('%02d/%04d', $mes, $ano).', as metas ficam zeradas; elas aparecem quando houver receita lançada ou prevista.';
            }
        @endphp
        <x-form-consequence :lines="$linhas" />
    @endif

    <x-form-errors />

    <div class="flex justify-end gap-2">
        <button type="button" wire:click="closeEditor" class="btn-ghost px-3 py-2 text-sm">Cancelar</button>
        <button type="button" wire:click="{{ $saveAction ?? 'saveRule' }}" wire:loading.attr="disabled" class="btn-primary px-4 py-2 text-sm" data-save-rule>Salvar regra</button>
    </div>
</div>
