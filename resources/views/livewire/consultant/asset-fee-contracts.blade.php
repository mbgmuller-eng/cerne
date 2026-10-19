@php
    use App\Enums\AssetFeeMode;
    use App\Support\ClientDisplayName;
    use App\Support\Money;

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
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Percentual sobre patrimônio</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                Percentual ao ano sobre o patrimônio investido do cliente, cobrado na periodicidade combinada. O Cerne estima com o patrimônio de hoje.
            </p>
        </div>
        <button type="button" wire:click="openForm" class="btn-primary px-3 py-1.5 text-xs">Novo contrato</button>
    </div>

    @if ($contratos->isEmpty())
        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum contrato de percentual sobre patrimônio.</p>
        </div>
    @else
        <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($contratos as $linha)
                @php($c = $linha['contrato'])
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="patrimonio-{{ $c->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ ClientDisplayName::forProfile($c->profile) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ $c->period->label() }} ·
                            @if ($c->mode === AssetFeeMode::Total)
                                {{ $pct($c->total_percent) }} ao ano sobre todo o patrimônio
                            @else
                                regras por produto
                            @endif
                            · desde {{ $c->starts_on->format('d/m/Y') }}@if ($c->ends_on) · até {{ $c->ends_on->format('d/m/Y') }}@endif
                        </p>
                        <p class="mt-0.5 text-xs text-slate-400">
                            Hoje: base de {{ Money::format($linha['estimativa']['base']) }}, {{ Money::format($linha['estimativa']['annual']) }} ao ano
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($linha['estimativa']['per_charge']) }}</p>
                            <p class="text-xs {{ $c->is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}">{{ $c->is_active ? 'por cobrança' : 'Pausado' }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="openForm('{{ $c->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                            <button type="button" wire:click="toggle('{{ $c->id }}', {{ $c->is_active ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs">{{ $c->is_active ? 'Pausar' : 'Reativar' }}</button>
                            <button type="button" wire:click="delete('{{ $c->id }}')" wire:confirm="Excluir este contrato? O que ainda é só previsão some; o que já foi recebido continua no livro." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Excluir</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal wire-model="showForm" max-width="lg">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $contractId ? 'Editar contrato' : 'Novo contrato de percentual' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Cliente</label>
                    <select wire:model="profileId" class="select mt-1.5 w-full">
                        <option value="">Escolha o cliente</option>
                        @foreach ($clientes as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                    @error('profileId') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Periodicidade</label>
                    <select wire:model="period" class="select mt-1.5 w-full">
                        @foreach ($periodos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('period') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Base de cálculo</label>
                    <select wire:model.live="mode" class="select mt-1.5 w-full">
                        @foreach ($modos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('mode') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                @if ($mode === 'total')
                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Percentual ao ano (%)</label>
                        <input type="number" step="0.0001" min="0" max="100" wire:model="totalPercent" class="input mt-1.5" placeholder="Ex.: 1">
                        <p class="mt-1 text-xs text-slate-400">O Cerne divide pelos meses do período: 1% ao ano em cobrança trimestral é 0,25% do patrimônio.</p>
                        @error('totalPercent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="@sm:col-span-2">
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                            Cada produto paga o percentual da regra dele (na aba Regras de comissionamento). O que não tem regra fica fora da cobrança.
                            @unless ($temRegras)
                                <span class="font-medium text-amber-700 dark:text-amber-400">Você ainda não tem nenhuma regra de investimento.</span>
                            @endunless
                        </p>
                    </div>
                @endif

                <div>
                    <label class="{{ $campo }}">Início</label>
                    <input type="date" wire:model="startsOn" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Os períodos contam a partir daqui.</p>
                    @error('startsOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Término (opcional)</label>
                    <input type="date" wire:model="endsOn" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Vazio = sem data para acabar.</p>
                    @error('endsOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Observação (opcional)</label>
                    <input type="text" wire:model="notes" class="input mt-1.5" placeholder="Ex.: só renda fixa e fundos">
                    @error('notes') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>
</section>
