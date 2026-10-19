@php
    use App\Models\DebtCommission;
    use App\Support\Money;

    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ''), '0'), ',').'%';
@endphp

<section class="space-y-6">
    @if ($aviso)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $aviso }}</div>
    @endif

    {{-- Regras por administradora ---------------------------------------- --}}
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Consórcio</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Quanto cada administradora paga sobre a carta e em quais parcelas. Ex.: 3,5% da carta nas parcelas 2, 4 e 6.</p>
            </div>
            <button type="button" wire:click="openRuleForm" class="btn-primary px-3 py-1.5 text-xs">Nova regra</button>
        </div>

        @if ($regras->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma regra de consórcio.</p>
            </div>
        @else
            <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($regras as $r)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="regra-cons-{{ $r->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $r->counterparty }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $pct($r->credit_percent) }} da carta · parcelas {{ $r->installment_schedule }}
                                @if ($r->payout_delay_days > 0) · paga {{ $r->payout_delay_days }} dias depois @endif
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
    </div>

    {{-- Consórcios com comissão ------------------------------------------- --}}
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Consórcios que geram comissão</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Você escolhe os consórcios dos seus clientes. Se um terceiro vendeu, informe o percentual combinado.</p>
            </div>
            <button type="button" wire:click="openLinkForm" class="btn-primary px-3 py-1.5 text-xs">Incluir consórcio</button>
        </div>

        @if ($consorcios->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum consórcio com comissão ainda.</p>
            </div>
        @else
            <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($consorcios as $c)
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="consorcio-{{ $c['id'] }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $c['cliente'] }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $c['administradora'] }} · carta {{ Money::format($c['carta']) }}
                                · {{ $c['papel'] === DebtCommission::ROLE_SOLD ? 'você vendeu' : 'terceiro vendeu' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <p class="text-right text-xs">
                                @if ($c['quitado'])
                                    <span class="text-slate-400">Consórcio encerrado</span>
                                @elseif ($c['percentual'] === null || blank($c['cronograma']))
                                    <span class="text-amber-700 dark:text-amber-400">
                                        {{ $c['papel'] === DebtCommission::ROLE_SOLD ? 'Sem regra para a administradora' : 'Informe o percentual combinado' }}
                                    </span>
                                @else
                                    <span class="figure block text-sm text-slate-800 dark:text-slate-200">{{ Money::format($c['total']) }}</span>
                                    <span class="text-emerald-700 dark:text-emerald-400">{{ $pct($c['percentual']) }} da carta · parcelas {{ $c['cronograma'] }}</span>
                                @endif
                            </p>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="openLinkForm('{{ $c['id'] }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                                <button type="button" wire:click="toggleLink('{{ $c['id'] }}', {{ $c['ativo'] ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs whitespace-nowrap">{{ $c['ativo'] ? 'Parar de contar' : 'Voltar a contar' }}</button>
                                <button type="button" wire:click="deleteLink('{{ $c['id'] }}')" wire:confirm="Tirar este consórcio da lista? A previsão some; o que já foi recebido continua no livro." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Tirar</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Regra -------------------------------------------------------------- --}}
    <x-modal wire-model="showRuleForm" max-width="lg">
        <form wire:submit="saveRule" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $ruleId ? 'Editar regra' : 'Nova regra de consórcio' }}</h2>
                <button type="button" wire:click="$set('showRuleForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Administradora</label>
                    <input type="text" wire:model="ruleCounterparty" list="administradoras-regra" class="input mt-1.5" placeholder="Ex.: Porto Seguro Consórcio">
                    <datalist id="administradoras-regra">
                        @foreach ($administradoras as $nome)
                            <option value="{{ $nome }}"></option>
                        @endforeach
                    </datalist>
                    <p class="mt-1 text-xs text-slate-400">Igual ao que está no cadastro do consórcio do cliente (campo credor).</p>
                    @error('ruleCounterparty') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Comissão sobre a carta (%)</label>
                    <input type="number" step="0.0001" min="0" max="100" wire:model="ruleCreditPercent" class="input mt-1.5" placeholder="Ex.: 3,5">
                    @error('ruleCreditPercent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Paga nas parcelas</label>
                    <input type="text" wire:model="ruleSchedule" class="input mt-1.5" placeholder="Ex.: 2, 4, 6">
                    @error('ruleSchedule') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <p class="text-xs text-slate-400 @sm:col-span-2">{{ $dica }}</p>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Prazo de pagamento (dias)</label>
                    <input type="number" min="0" max="120" wire:model="ruleDelay" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Quantos dias depois do vencimento da parcela a comissão costuma cair. Define o mês em que ela aparece.</p>
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

    {{-- Consórcio com comissão ------------------------------------------- --}}
    <x-modal wire-model="showLinkForm" max-width="lg">
        <form wire:submit="saveLink" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $linkId ? 'Editar consórcio' : 'Incluir consórcio com comissão' }}</h2>
                <button type="button" wire:click="$set('showLinkForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                @unless ($linkId)
                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Consórcio de um cliente seu</label>
                        <select wire:model="linkDebtId" class="select mt-1.5 w-full">
                            <option value="">Escolha o consórcio</option>
                            @foreach ($incluiveis as $id => $rotulo)
                                <option value="{{ $id }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('linkDebtId') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                        @if ($incluiveis === [])
                            <p class="mt-1 text-xs text-slate-400">Todos os consórcios em andamento dos seus clientes já estão na lista.</p>
                        @endif
                    </div>
                @endunless

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Quem vendeu</label>
                    <select wire:model.live="linkRole" class="select mt-1.5 w-full">
                        <option value="sold">Eu vendi (vale a regra da administradora)</option>
                        <option value="third_party">Um terceiro vendeu (informo o percentual)</option>
                    </select>
                    @error('linkRole') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Comissão sobre a carta (%)</label>
                    <input type="number" step="0.0001" min="0" max="100" wire:model="linkPercent" class="input mt-1.5" placeholder="{{ $linkRole === 'sold' ? 'Da regra' : 'Ex.: 1' }}">
                    <p class="mt-1 text-xs text-slate-400">{{ $linkRole === 'sold' ? 'Vazio = o da regra.' : 'Obrigatório: o que você combinou.' }}</p>
                    @error('linkPercent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Paga nas parcelas</label>
                    <input type="text" wire:model="linkSchedule" class="input mt-1.5" placeholder="{{ $linkRole === 'sold' ? 'Da regra' : 'Ex.: 2, 4' }}">
                    <p class="mt-1 text-xs text-slate-400">Vazio = as da regra.</p>
                    @error('linkSchedule') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>
</section>
