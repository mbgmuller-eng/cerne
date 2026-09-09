@use('App\Support\Money')
@use('App\Enums\PortfolioDisplayGroup')
@use('App\Enums\InvestorType')
@use('App\Enums\EmploymentType')
@use('App\Enums\AssetClass')
@use('App\Enums\ReserveType')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Investimentos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Carteira, rentabilidade e movimentações.</p>
    </div>

    {{-- Totais -------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Patrimônio investido</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($total) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Custo de aquisição</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-700 dark:text-slate-300">{{ Money::format($totalInvested) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Ganho não realizado</p>
            <p class="figure mt-2 text-2xl font-medium {{ (float) $totalGain < 0 ? 'text-slate-500 dark:text-slate-400' : 'text-accent-700 dark:text-accent-400' }}">
                {{ Money::format($totalGain) }}
            </p>
        </div>
    </div>

    {{-- Abas ---------------------------------------------------------- --}}
    <div class="flex gap-1 border-b border-slate-200 dark:border-white/10">
        @foreach (['portfolio' => 'Portfólio', 'performance' => 'Performance', 'transactions' => 'Transações'] as $chave => $rotulo)
            <button
                wire:click="setTab('{{ $chave }}')"
                @class([
                    '-mb-px border-b-2 px-4 py-2 text-sm transition',
                    'border-brand-700 dark:border-brand-400 font-medium text-brand-800 dark:text-brand-300' => $tab === $chave,
                    'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $chave,
                ])
            >{{ $rotulo }}</button>
        @endforeach
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    @if ($tab === 'portfolio')
        {{-- Novo investimento ------------------------------------------ --}}
        <div>
            <button type="button" wire:click="toggleInvestmentForm" class="btn-secondary">
                {{ $showInvestmentForm ? 'Cancelar' : '+ Investimento' }}
            </button>
        </div>

        @php
            // Editar nunca mostra os campos de cota, mesmo pra um ativo
            // que nasceu com elas — ver InvestmentsIndex::saveInvestment().
            $comCotas = ! $editingInvestmentId && $investmentAssetClass !== '' && AssetClass::tryFrom($investmentAssetClass)?->hasQuantity();
        @endphp
        <x-modal wire-model="showInvestmentForm">
            <form wire:submit="saveInvestment" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        {{ $editingInvestmentId ? 'Editar investimento' : 'Novo investimento' }}
                    </h2>
                    <button type="button" wire:click="toggleInvestmentForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 @sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                        <input type="text" wire:model="investmentName" class="input mt-1.5" placeholder="Ex.: Tesouro IPCA+ 2035">
                        @error('investmentName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Classe do ativo</label>
                        <select wire:model.live="investmentAssetClass" class="select mt-1.5 w-full">
                            <option value="">Selecione</option>
                            @foreach (AssetClass::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('investmentAssetClass') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                        <select wire:model.live="investmentMemberId" class="select mt-1.5 w-full">
                            <option value="">Selecione</option>
                            @foreach ($members as $membro)
                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                            @endforeach
                        </select>
                        @error('investmentMemberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($privacyMembers->count() >= 2 && $investmentMemberId !== '')
                        <div class="flex items-center gap-2 pt-5">
                            <input type="checkbox" wire:model="investmentIsPrivate" id="investmentIsPrivate" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <label for="investmentIsPrivate" class="text-sm text-slate-600 dark:text-slate-400">Ocultar do meu cônjuge</label>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta pra reserva</label>
                        <select wire:model="investmentReserveType" class="select mt-1.5 w-full">
                            <option value="">Nenhuma</option>
                            @foreach (ReserveType::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('investmentReserveType') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Instituição</label>
                        <input type="text" wire:model="investmentInstitution" class="input mt-1.5" placeholder="Ex.: XP Investimentos">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ticker</label>
                        <input type="text" wire:model="investmentTicker" class="input mt-1.5" placeholder="Ex.: PETR4">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data da compra</label>
                        <input type="date" wire:model="investmentPurchaseDate" class="input mt-1.5">
                    </div>

                    @if ($comCotas)
                        <div wire:key="investment-field-quantity">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantidade</label>
                            <input type="number" step="0.000001" min="0.000001" wire:model="investmentQuantity" class="input mt-1.5">
                            @error('investmentQuantity') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div wire:key="investment-field-unit-price">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Preço unitário de compra</label>
                            <input type="number" step="0.01" min="0.01" wire:model="investmentUnitPrice" class="input mt-1.5" placeholder="0,00">
                            @error('investmentUnitPrice') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div wire:key="investment-field-current-amount-cotas">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor atual de mercado</label>
                            <input type="number" step="0.01" min="0" wire:model="investmentCurrentAmount" class="input mt-1.5" placeholder="Se vazio, usa qtd. x preço">
                        </div>
                    @else
                        <div wire:key="investment-field-current-amount-plain">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor atual</label>
                            <input type="number" step="0.01" min="0" wire:model="investmentCurrentAmount" class="input mt-1.5" placeholder="0,00">
                            @error('investmentCurrentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        @if ($editingInvestmentId)
                            <div wire:key="investment-field-value-date">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data deste valor</label>
                                <input type="date" wire:model="investmentValueDate" class="input mt-1.5" max="{{ now()->toDateString() }}">
                                <p class="mt-1 text-xs text-slate-400">O dia em que você conferiu esse valor — atualiza o histórico do mês.</p>
                                @error('investmentValueDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div wire:key="investment-field-invested-amount">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor investido (aporte)</label>
                            <input type="number" step="0.01" min="0" wire:model="investmentInvestedAmount" class="input mt-1.5" placeholder="Se vazio, usa o valor atual">
                        </div>

                        <div wire:key="investment-field-return-rate">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Taxa contratada</label>
                            <input type="text" wire:model="investmentReturnRate" class="input mt-1.5" placeholder="Ex.: CDI 108%">
                        </div>
                    @endif
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">
                        {{ $editingInvestmentId ? 'Salvar alterações' : 'Salvar investimento' }}
                    </button>
                </div>
            </form>
        </x-modal>

        {{-- Reservas -------------------------------------------------- --}}
        @if ($reserves->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Reservas</h2>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ($reserves as $reserva)
                        <div class="card p-5 {{ $reserva->isShared() ? 'border-l-4 border-l-brand-600 dark:border-l-brand-400' : '' }}">
                            <div class="flex items-baseline justify-between">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $reserva->reserve_type->label() }}</p>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ $reserva->member->name ?? 'Casal' }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $reserva->reserve_type->description() }}
                                @if ($reserva->isShared())
                                    · visível para os dois
                                @endif
                            </p>

                            <div class="mt-3 flex items-center gap-2">
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                                    <div class="h-full rounded-full {{ $reserva->progressBarColorClass() }}" style="width: {{ $reserva->progressPercentage() }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs font-medium tabular-nums {{ $reserva->progressTextColorClass() }}">
                                    {{ number_format($reserva->progressPercentage(), 0) }}%
                                </span>
                            </div>

                            <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                                {{ Money::format($reserva->effectiveAmount()) }}
                                <span class="text-slate-400">de {{ Money::format($reserva->targetAmount()) }}</span>
                                @if ($reserva->isComplete())
                                    <span class="ml-1 {{ $reserva->progressTextColorClass() }}">· completa</span>
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Perfil do investidor: recomendado x real ------------------- --}}
        @if ($investorAllocations->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Perfil do investidor</h2>
                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    @foreach ($investorAllocations as $item)
                        @php
                            $perfil = $item['perfil'];
                            $membro = $item['membro'];
                            $categorias = $item['categorias'];
                            $fatias = $categorias->map(fn ($c) => ['pct' => $c['atualPct'], 'color' => $c['classe']->color()])->all();
                            $editandoEste = $showInvestorProfileForm && $investorProfileMemberId === $membro->id;
                        @endphp
                        <div class="card p-5">
                            <div class="flex items-baseline justify-between">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $membro->name }}</p>
                                <div class="flex items-center gap-2">
                                    @if ($perfil !== null)
                                        <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
                                            {{ $perfil->investor_type->label() }}
                                        </span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="toggleInvestorProfileForm('{{ $membro->id }}')"
                                        class="text-xs font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                                    >
                                        {{ $editandoEste ? 'Cancelar' : ($perfil !== null ? 'Editar' : 'Cadastrar') }}
                                    </button>
                                </div>
                            </div>

                            @if ($editandoEste)
                                <div class="mt-3 space-y-3 border-t border-slate-100 pt-3 dark:border-white/10">
                                    <div>
                                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Perfil de investidor</label>
                                        <select wire:model="investorTypeInput" class="select mt-1.5 w-full">
                                            <option value="">Selecione</option>
                                            @foreach (InvestorType::options() as $valor => $rotulo)
                                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                                            @endforeach
                                        </select>
                                        @error('investorTypeInput') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Tipo de atuação</label>
                                        <select wire:model="employmentTypeInput" class="select mt-1.5 w-full">
                                            <option value="">Selecione</option>
                                            @foreach (EmploymentType::options() as $valor => $rotulo)
                                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                                            @endforeach
                                        </select>
                                        @error('employmentTypeInput') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                        <p class="mt-1 text-xs text-slate-400">Define quantos meses de gasto essencial a reserva de paz precisa cobrir.</p>
                                    </div>

                                    <button type="button" wire:click="saveInvestorProfile" class="btn-primary w-full">Salvar</button>
                                </div>
                            @elseif ($perfil === null)
                                <p class="mt-4 text-xs text-slate-400">Sem perfil de investidor cadastrado ainda.</p>
                            @else
                                {{-- Reserva de paz já aparece com progresso e valor na seção
                                     "Reservas" acima — repetir aqui só duplicava o mesmo número. --}}
                                <p class="mt-3 text-xs text-slate-400">{{ $perfil->employment_type->label() }} · {{ $perfil->employment_type->reserveMonths() }} meses de gasto essencial</p>

                                @if ($categorias->isEmpty())
                                    <p class="mt-4 text-xs text-slate-400">Sem investimentos alocáveis cadastrados ainda para comparar com a recomendação.</p>
                                @else
                                    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
                                        <x-donut-chart :slices="$fatias" :size="140" :thickness="20" class="mx-auto sm:mx-0" />

                                        <div class="min-w-0 flex-1 space-y-1">
                                            @foreach ($categorias as $cat)
                                                <div x-data="{ aberta: false }">
                                                    <button
                                                        type="button"
                                                        @click="aberta = !aberta"
                                                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-slate-50 dark:hover:bg-white/5"
                                                    >
                                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $cat['classe']->color() }}"></span>
                                                        <span class="min-w-0 flex-1 truncate text-xs text-slate-700 dark:text-slate-300">{{ $cat['classe']->label() }}</span>
                                                        <span class="shrink-0 text-xs tabular-nums text-slate-800 dark:text-slate-200">{{ number_format($cat['atualPct'], 1, ',', '.') }}%</span>
                                                        <span class="shrink-0 text-xs tabular-nums text-slate-400">meta {{ number_format($cat['recomendadoPct'], 1, ',', '.') }}%</span>
                                                    </button>

                                                    <div x-show="aberta" x-transition class="ml-6 space-y-1 pb-2" x-cloak>
                                                        @forelse ($cat['investimentos'] as $ativo)
                                                            <div class="flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                                                                <span class="min-w-0 truncate">{{ $ativo->displayName() }}</span>
                                                                <span class="shrink-0 tabular-nums">{{ Money::format($ativo->current_amount) }}</span>
                                                            </div>
                                                        @empty
                                                            <p class="text-xs text-slate-400">Nenhum investimento nesta categoria ainda.</p>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Carteira por grupo ------------------------------------------ --}}
        @forelse ($byGroup as $grupo => $ativos)
            <section>
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        {{ PortfolioDisplayGroup::from($grupo)->label() }}
                    </h2>
                    <span class="text-sm tabular-nums text-slate-500 dark:text-slate-400">
                        {{ Money::format(Money::sum($ativos->pluck('current_amount'))) }}
                    </span>
                </div>

                @if ($grupo === 'retirement')
                    {{-- Previdência é um contrato que evolui no tempo, não um
                         ativo com cota — o gráfico é mais informativo que a
                         linha de lista usada para os outros setores. --}}
                    <div class="mt-2 space-y-4">
                        @foreach ($ativos as $ativo)
                            @php
                                $pct = $ativo->gainPercentage();
                                $anualizado = $ativo->annualizedReturnPercentage();
                                $dias = $ativo->daysHeld();
                            @endphp
                            <div class="card p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                            {{ $ativo->institution ?? $ativo->name }} · {{ $ativo->asset_class->label() }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                            {{ $ativo->name }}
                                            @if ($ativo->return_rate) · {{ $ativo->return_rate }} @endif
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="figure text-lg font-semibold text-slate-900 dark:text-white">
                                            {{ Money::format($ativo->current_amount) }}
                                        </p>
                                        @if ($pct !== null)
                                            <p @class([
                                                'text-xs font-medium',
                                                'text-accent-700 dark:text-accent-400' => $pct >= 0,
                                                'text-slate-500 dark:text-slate-400' => $pct < 0,
                                            ])>
                                                {{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 2, ',', '.') }}%
                                            </p>
                                        @endif
                                        <button type="button" wire:click="editInvestment('{{ $ativo->id }}')" class="mt-0.5 text-xs text-slate-500 hover:underline dark:text-slate-400">Editar</button>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <x-sparkline :values="$snapshotHistory[$ativo->id] ?? []" />
                                </div>

                                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($ativo->invested_amount)
                                        <span>Capital inicial: {{ Money::format($ativo->invested_amount) }}</span>
                                    @endif
                                    @if ($dias !== null)
                                        <span>Dias corridos: {{ $dias }}</span>
                                    @endif
                                    @if ($anualizado !== null)
                                        <span>Anualizado: {{ number_format($anualizado, 2, ',', '.') }}%</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <ul class="mt-2 card divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($ativos as $ativo)
                            <li class="flex items-center justify-between gap-4 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $ativo->displayName() }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $ativo->asset_class->label() }}
                                        @if ($ativo->institution) · {{ $ativo->institution }} @endif
                                        @if ($ativo->quantity && (float) $ativo->quantity > 0)
                                            · {{ rtrim(rtrim($ativo->quantity, '0'), '.') }} cotas
                                            a {{ Money::format($ativo->average_price) }}
                                        @endif
                                        @if ($ativo->return_rate) · {{ $ativo->return_rate }} @endif
                                    </p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($ativo->current_amount) }}</p>
                                    @if ($ativo->invested_amount && (float) $ativo->invested_amount > 0)
                                        @php $ganho = $ativo->unrealizedGain(); $pct = $ativo->gainPercentage(); @endphp
                                        <p class="text-xs tabular-nums {{ (float) $ganho < 0 ? 'text-slate-500 dark:text-slate-400' : 'text-accent-700 dark:text-accent-400' }}">
                                            {{ (float) $ganho >= 0 ? '+' : '' }}{{ Money::format($ganho) }}
                                            @if ($pct !== null)
                                                ({{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 1, ',', '.') }}%)
                                            @endif
                                        </p>
                                    @endif
                                    <button type="button" wire:click="editInvestment('{{ $ativo->id }}')" class="text-xs text-slate-500 hover:underline dark:text-slate-400">Editar</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum investimento cadastrado.</p>
            </div>
        @endforelse

    @elseif ($tab === 'performance')
        @if ($portfolioEvolution !== null)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="eyebrow">Evolução do patrimônio</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Desde {{ $portfolioEvolution['desde'] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="figure text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($portfolioEvolution['valorAtual']) }}</p>
                        <p @class([
                            'text-sm font-medium tabular-nums',
                            'text-accent-700 dark:text-accent-400' => (float) $portfolioEvolution['crescimentoValor'] >= 0,
                            'text-slate-500 dark:text-slate-400' => (float) $portfolioEvolution['crescimentoValor'] < 0,
                        ])>
                            {{ (float) $portfolioEvolution['crescimentoValor'] >= 0 ? '+' : '' }}{{ Money::format($portfolioEvolution['crescimentoValor']) }}
                            @if ($portfolioEvolution['crescimentoPct'] !== null)
                                ({{ $portfolioEvolution['crescimentoPct'] >= 0 ? '+' : '' }}{{ number_format($portfolioEvolution['crescimentoPct'], 1, ',', '.') }}%)
                            @endif
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-white/5">
                        @foreach (['total' => 'Total', 'group' => 'Grupo', 'asset' => 'Ativo'] as $lente => $rotuloLente)
                            <button
                                type="button"
                                wire:click="$set('evolutionLens', '{{ $lente }}')"
                                @class([
                                    'rounded-md px-3 py-1 text-xs font-medium transition',
                                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $evolutionLens === $lente,
                                    'text-slate-500 dark:text-slate-400' => $evolutionLens !== $lente,
                                ])
                            >{{ $rotuloLente }}</button>
                        @endforeach
                    </div>

                    @if ($evolutionLens === 'asset')
                        <select wire:model.live="evolutionAssetId" class="select w-auto text-xs">
                            <option value="">Selecione um ativo</option>
                            @foreach ($evolutionChart['ativosDisponiveis'] as $opcaoAtivo)
                                <option value="{{ $opcaoAtivo['id'] }}">{{ $opcaoAtivo['nome'] }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <div class="mt-3">
                    @if ($evolutionLens === 'group' && $evolutionChart['porGrupo'] !== null)
                        @if (collect($evolutionChart['porGrupo'])->contains('visivel', true))
                            <x-bar-chart
                                :meses="$evolutionChart['meses']"
                                :series="collect($evolutionChart['porGrupo'])->where('visivel', true)->map(fn ($g) => ['cor' => $g['cor'], 'valores' => $g['valores'], 'rotulo' => $g['grupo']->label()])->all()"
                                :maximo="$evolutionChart['maximo']"
                                :height="180"
                                :width="640"
                            />
                        @else
                            <p class="py-8 text-center text-xs text-slate-400">Nenhum grupo selecionado — clique num grupo abaixo pra mostrar de novo.</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ($evolutionChart['porGrupo'] as $grupoEvolucao)
                                <button
                                    type="button"
                                    wire:click="toggleEvolutionGroup('{{ $grupoEvolucao['grupo']->value }}')"
                                    @class([
                                        'flex items-center gap-1.5 text-xs transition',
                                        'text-slate-500 dark:text-slate-400' => $grupoEvolucao['visivel'],
                                        'text-slate-300 line-through dark:text-slate-600' => ! $grupoEvolucao['visivel'],
                                    ])
                                    title="{{ $grupoEvolucao['visivel'] ? 'Clique pra esconder' : 'Clique pra mostrar' }}"
                                >
                                    <span
                                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                                        style="background-color: {{ $grupoEvolucao['visivel'] ? $grupoEvolucao['cor'] : 'transparent' }}; border: 1.5px solid {{ $grupoEvolucao['cor'] }}"
                                    ></span>
                                    {{ $grupoEvolucao['grupo']->label() }}
                                </button>
                            @endforeach
                        </div>
                    @elseif ($evolutionLens === 'asset')
                        @if ($evolutionChart['ativo'] !== null)
                            <x-bar-chart
                                :meses="$evolutionChart['meses']"
                                :series="[['cor' => 'currentColor', 'valores' => $evolutionChart['ativo']['valores'], 'rotulo' => $evolutionChart['ativo']['nome']]]"
                                :maximo="$evolutionChart['maximo']"
                                :height="180"
                                :width="640"
                                class="text-brand-700 dark:text-brand-300"
                            />
                        @else
                            <p class="py-8 text-center text-xs text-slate-400">Selecione um ativo pra ver a evolução dele.</p>
                        @endif
                    @else
                        <x-bar-chart
                            :meses="$evolutionChart['meses']"
                            :series="[['cor' => 'currentColor', 'valores' => $evolutionChart['total'], 'rotulo' => 'Total']]"
                            :maximo="$evolutionChart['maximo']"
                            :height="180"
                            :width="640"
                            class="text-brand-700 dark:text-brand-300"
                        />
                    @endif
                </div>
            </div>
        @endif

        @if ($performance->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma rentabilidade registrada.</p>
                <p class="mt-1 text-xs text-slate-400">Os relatórios de rentabilidade chegam pela importação de PDF.</p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($performance as $p)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">
                                {{ $p->isPortfolioWide() ? 'Carteira consolidada' : $p->investment->displayName() }}
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $p->periodLabel() }}
                                @if ($p->benchmark) · vs {{ $p->benchmark->label() }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums {{ (float) $p->return_percentage < 0 ? 'text-slate-500 dark:text-slate-400' : 'text-accent-700 dark:text-accent-400' }}">
                                {{ number_format((float) $p->return_percentage, 2, ',', '.') }}%
                            </p>
                            @if ($p->vs_benchmark !== null)
                                <p class="text-xs {{ $p->beatBenchmark() ? 'text-brand-600 dark:text-brand-400' : 'text-slate-400' }}">
                                    {{ $p->beatBenchmark() ? 'acima' : 'abaixo' }} do índice
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

    @else
        @if ($transactions->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma movimentação registrada.</p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($transactions as $tx)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">
                                {{ $tx->transaction_type->label() }} · {{ $tx->investment->displayName() }}
                            </p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $tx->operation_date->format('d/m/Y') }}
                                @if ($tx->quantity && (float) $tx->quantity > 0)
                                    · {{ rtrim(rtrim($tx->quantity, '0'), '.') }} a {{ Money::format($tx->unit_price) }}
                                @endif
                                @if ($tx->broker_fee && (float) $tx->broker_fee > 0)
                                    · taxa {{ Money::format($tx->broker_fee) }}
                                @endif
                            </p>
                        </div>
                        <p class="shrink-0 text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($tx->net_amount) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>
