@use('App\Support\Money')
@use('Carbon\CarbonImmutable')

@php
    $mesTexto = CarbonImmutable::create($ano, $mes, 1)->locale('pt_BR')->translatedFormat('F \d\e Y');
    $chip = [
        'reached' => ['Meta batida', 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
        'partial' => ['Em andamento', 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
        'none' => ['Nada lançado', 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10'],
        'ok' => ['Dentro do limite', 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
        'over' => ['Passou do limite', 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20'],
    ];
    $validacao = [
        'paid' => ['Lançado', 'text-emerald-700 dark:text-emerald-400'],
        'partial' => ['Lançado em parte', 'text-amber-700 dark:text-amber-400'],
        'pending' => ['Ainda não lançado', 'text-amber-700 dark:text-amber-400'],
        'unregistered' => ['Nada cadastrado', 'text-slate-500 dark:text-slate-400'],
    ];
    $barra = fn (string $situacao) => match ($situacao) {
        'over' => 'bg-red-600 dark:bg-red-400',
        'partial' => 'bg-amber-500 dark:bg-amber-400',
        default => 'bg-brand-700 dark:bg-brand-400',
    };
@endphp

<div class="space-y-6" data-budget-page>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Regra de orçamento</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Quanto da receita deveria ir para cada área, e quanto foi.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 rounded-lg ring-1 ring-slate-200 dark:ring-white/10">
                <button type="button" wire:click="previousMonth" class="btn-ghost px-2.5 py-1.5 text-sm" aria-label="Mês anterior">‹</button>
                <span class="min-w-[9rem] px-1 text-center text-sm font-medium first-letter:uppercase text-slate-800 dark:text-slate-200" data-budget-month>{{ $mesTexto }}</span>
                <button type="button" wire:click="nextMonth" class="btn-ghost px-2.5 py-1.5 text-sm" aria-label="Próximo mês">›</button>
            </div>
            @unless ($ehMesAtual)
                <button type="button" wire:click="currentMonth" class="btn-ghost px-2 py-1.5 text-xs">Mês atual</button>
            @endunless
            @if ($relatorio !== null)
                <button type="button" wire:click="openEditor" class="btn-secondary px-3 py-1.5 text-sm" data-edit-rule>Editar regra</button>
            @endif
        </div>
    </div>

    @if (session('budget_status'))
        <p class="rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20" data-budget-status>{{ session('budget_status') }}</p>
    @endif

    @if ($relatorio === null)
        {{-- Sem regra: escolher por onde começar --}}
        <div class="card p-6" data-budget-empty>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">Defina como a sua receita se divide</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                Você escolhe quanto da receita vai para o essencial, o supérfluo e os investimentos, e como dividir os investimentos (reservas, aposentadoria, objetivos).
                Com a receita do mês, o Cerne mostra o quanto deveria ir para cada área e compara com o que foi lançado.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($presets as $chave => $preset)
                    <button type="button" wire:click="startWith('{{ $chave }}')" class="{{ $chave === 'personalizado' ? 'btn-secondary' : 'btn-primary' }} px-4 py-2 text-sm" data-preset="{{ $chave }}">{{ $preset['name'] }}</button>
                @endforeach
                @foreach ($modelos as $modelo)
                    <button type="button" wire:click="useTemplate('{{ $modelo->id }}')" class="btn-secondary px-4 py-2 text-sm">Meu modelo: {{ $modelo->name }}</button>
                @endforeach
            </div>
            <ul class="mt-4 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                @foreach ($presets as $preset)
                    <li><strong class="font-medium text-slate-700 dark:text-slate-300">{{ $preset['name'] }}:</strong> {{ $preset['description'] }}</li>
                @endforeach
            </ul>
        </div>
    @else
        {{-- Receita do mês --}}
        <div class="card p-5" data-budget-income>
            <p class="eyebrow">Receita de {{ $mesTexto }}</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($relatorio['income']['total']) }}</p>
            @php
                $fraseReceita = Money::format($relatorio['income']['received']).' já recebidos'
                    .(bccomp($relatorio['income']['expected'], '0', 2) > 0 ? ' + '.Money::format($relatorio['income']['expected']).' previstos nas receitas recorrentes' : '')
                    .'. As metas abaixo saem deste valor.';
            @endphp
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-income-sentence>{{ $fraseReceita }}</p>
            @if (bccomp($relatorio['income']['total'], '0', 2) <= 0)
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Sem receita neste mês, as metas ficam zeradas. Lance a receita ou cadastre uma receita recorrente.</p>
            @endif
        </div>

        {{-- As três áreas --}}
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach (['essential', 'discretionary', 'investment'] as $area)
                @php $a = $relatorio['areas'][$area]; @endphp
                <div class="card p-5" data-area-card="{{ $area }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="eyebrow">{{ $a['name'] }} · {{ rtrim(rtrim(number_format((float) $a['percent'], 2, ',', ''), '0'), ',') }}%</p>
                        <span class="rounded-full px-2 py-0.5 text-[11px] ring-1 {{ $chip[$a['status']][1] }}">{{ $chip[$a['status']][0] }}</span>
                    </div>
                    <p class="figure mt-2 text-xl font-medium text-slate-900 dark:text-white">{{ Money::format($a['realized']) }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $a['direction'] === 'max' ? 'limite' : 'meta' }} de {{ Money::format($a['target']) }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"><div class="h-full rounded-full {{ $barra($a['status']) }}" style="width: {{ $a['progress'] }}%"></div></div>
                    <p class="mt-2 text-xs {{ $a['status'] === 'over' ? 'text-red-700 dark:text-red-400' : 'text-slate-500 dark:text-slate-400' }}">
                        @if ($a['direction'] === 'max')
                            {{ $a['status'] === 'over' ? 'Passou '.Money::format($a['over']).' do limite.' : 'Ainda cabem '.Money::format(bcsub($a['target'], $a['realized'], 2)).'.' }}
                        @else
                            {{ bccomp($a['missing'], '0', 2) > 0 ? 'Faltam '.Money::format($a['missing']).' para a meta.' : 'Meta do mês batida.' }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        {{-- Investimentos por grupo e item --}}
        @php $inv = $relatorio['areas']['investment']; @endphp
        <div class="card p-5" data-investments>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Para onde vai o investimento</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Meta de {{ Money::format($inv['target']) }} · lançado {{ Money::format($inv['realized']) }}</p>
            </div>

            @if (count($inv['groups']) === 0)
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400" data-no-groups>Sem grupos: a meta vale para o investimento como um todo. Em "Editar regra" você pode dividi-lo em grupos e itens.</p>
            @endif

            <div class="mt-4 space-y-5">
                @foreach ($inv['groups'] as $grupo)
                    <div data-group-report="{{ $grupo['key'] }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $grupo['name'] }} <span class="text-xs font-normal text-slate-400">· {{ rtrim(rtrim(number_format((float) $grupo['percent'], 2, ',', ''), '0'), ',') }}%</span></p>
                            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($grupo['realized']) }} de {{ Money::format($grupo['target']) }}</p>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"><div class="h-full rounded-full {{ $barra($grupo['status']) }}" style="width: {{ $grupo['progress'] }}%"></div></div>

                        @if ($grupo['over_commitment'] !== null)
                            <p class="mt-1.5 text-xs text-red-700 dark:text-red-400" data-over-commitment>Os compromissos fixos ({{ Money::format($grupo['fixed_total']) }}) passam do que a regra reserva para {{ $grupo['name'] }} em {{ Money::format($grupo['over_commitment']) }}. Reveja os percentuais.</p>
                        @elseif ($grupo['fixed_total'] !== '0.00')
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Compromissos fixos {{ Money::format($grupo['fixed_total']) }}; restam {{ Money::format($grupo['remaining']) }} para os demais itens.</p>
                        @endif

                        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($grupo['items'] as $item)
                                <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between" data-item-report="{{ $item['key'] }}">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm text-slate-700 dark:text-slate-300">{{ $item['name'] }}
                                            @if ($item['kind'] === 'fixed') <span class="text-xs text-slate-400">· compromisso fixo</span>
                                            @elseif ($item['percent'] !== null) <span class="text-xs text-slate-400">· {{ rtrim(rtrim(number_format((float) $item['percent'], 2, ',', ''), '0'), ',') }}% do que sobra</span> @endif
                                        </p>
                                        @if ($item['kind'] === 'fixed')
                                            @php
                                                $conferencia = $validacao[$item['validation']][0].': cadastrado '.Money::format($item['committed'])
                                                    .($item['committed_detail'] !== '' ? ' ('.$item['committed_detail'].')' : '')
                                                    .', lançado '.Money::format($item['realized']).'.';
                                            @endphp
                                            <p class="text-xs {{ $validacao[$item['validation']][1] }}" data-fixed-check>{{ $conferencia }}</p>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                        @if ($item['kind'] !== 'fixed')
                                            meta {{ Money::format($item['target']) }} · lançado {{ Money::format($item['realized']) }}
                                            @if (bccomp($item['missing'], '0', 2) > 0 && bccomp($item['target'], '0', 2) > 0)
                                                <span class="text-amber-700 dark:text-amber-400">· faltam {{ Money::format($item['missing']) }}</span>
                                            @endif
                                        @else
                                            <span class="rounded-full px-2 py-0.5 text-[11px] ring-1 {{ $chip[$item['status']][1] }}">{{ $chip[$item['status']][0] }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            @if (count($inv['unmapped']) > 0)
                <div class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20" data-unmapped>
                    <p class="font-medium">Lançado como investimento, mas sem item na regra:</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($inv['unmapped'] as $linha)
                            <li>{{ $linha['name'] }}: {{ Money::format($linha['amount']) }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1">Entre em "Editar regra" e inclua a categoria em um item para que ela conte nas metas.</p>
                </div>
            @endif

            @if (count($relatorio['double_count']) > 0)
                <div class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20" data-double-count>
                    <p class="font-medium">Pode haver aporte contado duas vezes:</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($relatorio['double_count'] as $aviso)
                            <li>{{ Money::format($aviso['amount']) }} em "{{ $aviso['first'] }}" e em "{{ $aviso['second'] }}", em contas diferentes e com poucos dias de distância.</li>
                        @endforeach
                    </ul>
                    <p class="mt-1">A transferência da conta corrente para a conta de investimento não é aporte: só a compra do ativo é. Se um dos dois é a transferência, marque como transferência entre contas.</p>
                </div>
            @endif
        </div>

        {{-- Divisão do balde de objetivos --}}
        @if ($alocacao !== null)
            <div class="card p-5" data-goal-allocation>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Dividir o balde de objetivos</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ Money::format($alocacao['pool']) }} por mês para repartir entre os objetivos (já descontado o consórcio).</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como dividir</label>
                        <select wire:model.live="goalMethod" class="select mt-1 w-full">
                            @foreach (\App\Services\GoalAllocationService::METHODS as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if (count($alocacao['rows']) === 0)
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhum objetivo em andamento para receber. Os alcançados e os ligados a consórcio ficam de fora.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($alocacao['rows'] as $linha)
                            <li class="py-2" data-goal-row="{{ $linha['goal']->id }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-sm text-slate-800 dark:text-slate-200">{{ $linha['goal']->priority }}. {{ $linha['goal']->name }}
                                        @if ($linha['needed'] !== null) <span class="text-xs text-slate-400">· precisa de {{ Money::format($linha['needed']) }} por mês</span>
                                        @else <span class="text-xs text-slate-400">· sem data-alvo</span> @endif
                                    </p>
                                    <p class="text-sm tabular-nums font-medium text-slate-900 dark:text-white">{{ Money::format($linha['suggested']) }}
                                        <span class="text-xs font-normal {{ $linha['status'] === 'full' ? 'text-emerald-700 dark:text-emerald-400' : ($linha['status'] === 'none' ? 'text-slate-400' : 'text-amber-700 dark:text-amber-400') }}">
                                            {{ ['full' => 'completo', 'partial' => 'parcial', 'none' => 'sem verba', 'leftover' => 'sobra'][$linha['status']] }}
                                        </span>
                                    </p>
                                </div>
                                @if ($linha['forecast'])
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $linha['forecast'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (bccomp($alocacao['leftover'], '0', 2) > 0)
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Sobram {{ Money::format($alocacao['leftover']) }} do balde: todos os objetivos com data já têm o necessário e não há objetivo sem data para receber.</p>
                    @endif

                    <div class="mt-4 space-y-3">
                        <x-form-consequence :lines="$linhasObjetivos" />
                        <div class="flex justify-end">
                            <button type="button" wire:click="applyGoals" wire:confirm="Gravar estes valores como o quanto você vai guardar por mês em cada objetivo?" class="btn-primary px-4 py-2 text-sm" data-apply-goals>Aplicar nos objetivos</button>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <p data-rule-effective>
                @if ($regra)
                    Regra em vigor desde {{ sprintf('%02d/%04d', $regra->effective_month, $regra->effective_year) }}.
                @endif
            </p>
            @if ($regra)
                <button type="button" wire:click="deleteRule" wire:confirm="Remover esta versão da regra? Volta a valer a anterior, se houver." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Remover esta regra</button>
            @endif
        </div>
    @endif

    {{-- Editor --}}
    <x-modal wire-model="showEditor" max-width="3xl">
        @if ($showEditor)
            @include('livewire.budget.partials.editor', ['presets' => $presets, 'modelos' => $modelos, 'saveAction' => 'saveRule', 'showStart' => true, 'editorTitle' => 'Regra de orçamento'])
        @endif
    </x-modal>
</div>
