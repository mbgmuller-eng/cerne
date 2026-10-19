@php
    use App\Enums\CommissionType;
    use App\Support\Money;

    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ''), '0'), ',').'%';
@endphp

<div class="space-y-6">
    @if ($aviso)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $aviso }}</div>
    @endif

    @if ($semRegra !== [])
        <div class="card border-l-4 border-l-amber-500 p-5">
            <p class="text-sm font-medium text-slate-900 dark:text-white">Apólices sem regra de comissão</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Sem a regra da seguradora o Cerne não consegue prever o que você vai receber.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($semRegra as $nome)
                    <button type="button" wire:click="openRuleForm(null, @js($nome))" class="btn-secondary px-3 py-1.5 text-xs">Criar regra para {{ $nome }}</button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Regras por seguradora -------------------------------------------- --}}
    <section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Seguradoras</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Quanto cada seguradora paga de comissão sobre cada parcela, e o agenciamento no fechamento.</p>
            </div>
            <button type="button" wire:click="openRuleForm" class="btn-primary px-3 py-1.5 text-xs">Nova regra</button>
        </div>

        @if ($regras->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma regra cadastrada.</p>
                <p class="mt-1 text-xs text-slate-400">Cadastre a primeira para o Cerne prever a comissão das suas apólices.</p>
            </div>
        @else
            <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($regras as $r)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="regra-{{ $r->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ $r->counterparty }}
                                <span class="font-normal text-slate-400">· {{ $r->insurance_type?->label() ?? 'todos os tipos' }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                1º ano {{ $pct($r->first_year_percent) }}
                                · renovação {{ $r->renewal_percent !== null ? $pct($r->renewal_percent) : 'igual' }}
                                @if ($r->hasAgency())
                                    · agenciamento {{ $r->agency_type === CommissionType::Percent ? $pct($r->agency_percent).' do prêmio' : Money::format($r->agency_amount) }}
                                @endif
                                @if ($r->payout_delay_days > 0)
                                    · paga {{ $r->payout_delay_days }} dias depois
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="mr-2 text-xs {{ $r->is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}">{{ $r->is_active ? 'Ativa' : 'Pausada' }}</span>
                            <button type="button" wire:click="openRuleForm('{{ $r->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                            <button type="button" wire:click="toggleRule('{{ $r->id }}', {{ $r->is_active ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs">{{ $r->is_active ? 'Pausar' : 'Reativar' }}</button>
                            <button type="button" wire:click="deleteRule('{{ $r->id }}')" wire:confirm="Excluir esta regra? A previsão que dependia dela sai do livro." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Excluir</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Apólices com comissão --------------------------------------------- --}}
    <section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Apólices que geram comissão</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ $ehConsultor ? 'Entram sozinhas as que você vendeu por uma cotação de lead. As outras você inclui à mão.' : 'Entram sozinhas as apólices ligadas a você como corretor.' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="recalculate" class="btn-secondary px-3 py-1.5 text-xs">Refazer previsão</button>
                @if ($ehConsultor)
                    <button type="button" wire:click="openIncludeForm" class="btn-primary px-3 py-1.5 text-xs">Incluir apólice</button>
                @endif
            </div>
        </div>

        @if ($apolices->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma apólice com comissão ainda.</p>
            </div>
        @else
            <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($apolices as $a)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="apolice-{{ $a['id'] }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $a['cliente'] }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $a['seguradora'] }} · {{ $a['tipo'] }}@if ($a['numero']) · {{ $a['numero'] }}@endif
                                · {{ Money::format($a['parcela']) }} {{ mb_strtolower($a['periodicidade']) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <p class="text-xs">
                                @if (! $a['apolice_ativa'])
                                    <span class="text-slate-400">Apólice encerrada</span>
                                @elseif ($a['regra'])
                                    <span class="text-emerald-700 dark:text-emerald-400">Regra: 1º ano {{ $pct($a['regra']->first_year_percent) }}</span>
                                @else
                                    <span class="text-amber-700 dark:text-amber-400">Sem regra</span>
                                @endif
                            </p>
                            <button type="button" wire:click="toggleLink('{{ $a['id'] }}', {{ $a['ativo'] ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs whitespace-nowrap">{{ $a['ativo'] ? 'Parar de contar' : 'Voltar a contar' }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Regra ------------------------------------------------------------- --}}
    <x-modal wire-model="showRuleForm" max-width="lg">
        <form wire:submit="saveRule" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $ruleId ? 'Editar regra' : 'Nova regra de comissão' }}</h2>
                <button type="button" wire:click="$set('showRuleForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">Seguradora</label>
                    <input type="text" wire:model="ruleCounterparty" list="seguradoras-regra" class="input mt-1.5" placeholder="Ex.: Porto Seguro">
                    <datalist id="seguradoras-regra">
                        @foreach ($seguradoras as $nome)
                            <option value="{{ $nome }}"></option>
                        @endforeach
                    </datalist>
                    @error('ruleCounterparty') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Tipo de seguro</label>
                    <select wire:model="ruleType" class="select mt-1.5 w-full">
                        <option value="">Todos os tipos</option>
                        @foreach ($tipos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">A regra de um tipo vale mais que a geral.</p>
                    @error('ruleType') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Comissão do 1º ano (%)</label>
                    <input type="number" step="0.001" min="0" max="100" wire:model="ruleFirstYear" class="input mt-1.5" placeholder="Ex.: 30">
                    @error('ruleFirstYear') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Comissão da renovação (%)</label>
                    <input type="number" step="0.001" min="0" max="100" wire:model="ruleRenewal" class="input mt-1.5" placeholder="Igual ao 1º ano">
                    <p class="mt-1 text-xs text-slate-400">Vazio = a seguradora paga o mesmo todo ano.</p>
                    @error('ruleRenewal') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Agenciamento</label>
                    <select wire:model.live="ruleAgencyType" class="select mt-1.5 w-full">
                        <option value="">Não tem</option>
                        @foreach (CommissionType::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('ruleAgencyType') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    @if ($ruleAgencyType === 'percent')
                        <label class="{{ $campo }}">Agenciamento (% do prêmio)</label>
                        <input type="number" step="0.001" min="0" wire:model="ruleAgencyPercent" class="input mt-1.5">
                        <p class="mt-1 text-xs text-slate-400">Sobre o prêmio do 1º ano.</p>
                        @error('ruleAgencyPercent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    @elseif ($ruleAgencyType === 'amount')
                        <label class="{{ $campo }}">Agenciamento (R$)</label>
                        <input type="number" step="0.01" min="0" wire:model="ruleAgencyAmount" class="input mt-1.5">
                        <p class="mt-1 text-xs text-slate-400">Por apólice nova.</p>
                        @error('ruleAgencyAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    @else
                        <label class="{{ $campo }}">Valor do agenciamento</label>
                        <input type="text" class="input mt-1.5" disabled placeholder="Escolha o tipo ao lado">
                    @endif
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Prazo de pagamento (dias)</label>
                    <input type="number" min="0" max="120" wire:model="ruleDelay" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">A seguradora só paga depois que o cliente paga a parcela. Quantos dias depois do vencimento a comissão costuma cair? Define o mês em que ela aparece.</p>
                    @error('ruleDelay') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Observação (opcional)</label>
                    <input type="text" wire:model="ruleNotes" class="input mt-1.5" placeholder="Ex.: tabela de 2026">
                    @error('ruleNotes') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>

    {{-- Incluir apólice ---------------------------------------------------- --}}
    @if ($ehConsultor)
        <x-modal wire-model="showIncludeForm" max-width="lg">
            <form wire:submit="includePolicy" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Incluir apólice com comissão</h2>
                    <button type="button" wire:click="$set('showIncludeForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div>
                    <label class="{{ $campo }}">Apólice de um cliente seu</label>
                    <select wire:model="includePolicyId" class="select mt-1.5 w-full">
                        <option value="">Escolha a apólice</option>
                        @foreach ($incluiveis as $id => $rotulo)
                            <option value="{{ $id }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('includePolicyId') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    @if ($incluiveis === [])
                        <p class="mt-1 text-xs text-slate-400">Todas as apólices dos seus clientes já estão na lista.</p>
                    @endif
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2 text-sm">Incluir</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
