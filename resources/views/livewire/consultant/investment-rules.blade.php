@php
    use App\Enums\InvestmentRuleScope;

    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ''), '0'), ',').'%';
@endphp

<section>
    @if ($aviso)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $aviso }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Investimentos</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                Percentual ao ano por produto, empresa ou tipo de ativo. Valem para os contratos de percentual no modo "regras". O mais específico vence: produto, depois empresa, depois tipo.
            </p>
        </div>
        <button type="button" wire:click="openForm" class="btn-primary px-3 py-1.5 text-xs">Nova regra</button>
    </div>

    @if ($regras->isEmpty())
        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma regra de investimento.</p>
            <p class="mt-1 text-xs text-slate-400">Só precisa se algum contrato cobra percentuais diferentes por produto.</p>
        </div>
    @else
        <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($regras as $r)
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="regra-inv-{{ $r->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                            {{ $r->counterparty }}
                            <span class="font-normal text-slate-400">· {{ InvestmentRuleScope::from($r->type_key)->label() }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $pct($r->annual_percent) }} ao ano</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="mr-2 text-xs {{ $r->is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}">{{ $r->is_active ? 'Ativa' : 'Pausada' }}</span>
                        <button type="button" wire:click="openForm('{{ $r->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                        <button type="button" wire:click="toggle('{{ $r->id }}', {{ $r->is_active ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs">{{ $r->is_active ? 'Pausar' : 'Reativar' }}</button>
                        <button type="button" wire:click="delete('{{ $r->id }}')" wire:confirm="Excluir esta regra? A previsão que dependia dela é refeita." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Excluir</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal wire-model="showForm" max-width="lg">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $ruleId ? 'Editar regra' : 'Nova regra de investimento' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">A regra vale para</label>
                    <select wire:model.live="scope" class="select mt-1.5 w-full">
                        @foreach ($escopos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('scope') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Percentual ao ano (%)</label>
                    <input type="number" step="0.0001" min="0" max="100" wire:model="annualPercent" class="input mt-1.5" placeholder="Ex.: 0,8">
                    @error('annualPercent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    @if ($scope === 'asset_class')
                        <label class="{{ $campo }}">Tipo de ativo</label>
                        <select wire:model="target" class="select mt-1.5 w-full">
                            <option value="">Escolha o tipo</option>
                            @foreach ($tiposDeAtivo as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    @elseif ($scope === 'institution')
                        <label class="{{ $campo }}">Empresa (instituição)</label>
                        <input type="text" wire:model="target" list="empresas-regra" class="input mt-1.5" placeholder="Ex.: XP Investimentos">
                        <datalist id="empresas-regra">
                            @foreach ($empresas as $nome)
                                <option value="{{ $nome }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-xs text-slate-400">Como aparece nos investimentos dos clientes.</p>
                    @else
                        <label class="{{ $campo }}">Produto (nome ou código)</label>
                        <input type="text" wire:model="target" class="input mt-1.5" placeholder="Ex.: HGLG11 ou Tesouro IPCA 2035">
                        <p class="mt-1 text-xs text-slate-400">Igual ao nome ou ao código do investimento, sem diferenciar maiúscula e acento.</p>
                    @endif
                    @error('target') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Observação (opcional)</label>
                    <input type="text" wire:model="notes" class="input mt-1.5" placeholder="Ex.: tabela combinada em 2026">
                    @error('notes') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>
</section>
