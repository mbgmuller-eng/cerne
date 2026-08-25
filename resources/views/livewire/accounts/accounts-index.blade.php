@use('App\Support\Money')
@use('App\Enums\AccountType')
@use('App\Enums\CardBrand')

<div class="space-y-8">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Contas &amp; Cartões</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Saldos e faturas em aberto.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="toggleAccountForm" class="btn-secondary px-3 py-1.5">+ Conta</button>
            <button wire:click="toggleCardForm" class="btn-primary px-3 py-1.5">+ Cartão</button>
        </div>
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    {{-- Nova/editar conta -------------------------------------------------- --}}
    @if ($showAccountForm)
        <form wire:submit="saveAccount" class="card space-y-4 p-5">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingAccountId ? 'Editar conta' : 'Nova conta' }}</h2>
                <button type="button" wire:click="toggleAccountForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Banco</label>
                    <input type="text" wire:model="accountBankName" class="input mt-1.5" placeholder="Ex.: Itaú">
                    @error('accountBankName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model="accountType" class="select mt-1.5 w-full">
                        @foreach (AccountType::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Saldo atual</label>
                    <input type="number" step="0.01" wire:model="accountBalance" class="input mt-1.5" placeholder="0,00">
                    @error('accountBalance') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Agência (opcional)</label>
                    <input type="text" wire:model="accountAgency" class="input mt-1.5">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta (opcional)</label>
                    <input type="text" wire:model="accountNumber" class="input mt-1.5">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                    <select wire:model="accountMemberId" class="select mt-1.5 w-full">
                        <option value="">Selecione</option>
                        @foreach ($members as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('accountMemberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Marcar "conjunta" abaixo não dispensa o dono — é só a flag de compartilhamento.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cor</label>
                    <input type="color" wire:model="accountColor" class="mt-1.5 h-10 w-full rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
                </div>

                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" wire:model="accountIsJoint" id="accountIsJoint" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <label for="accountIsJoint" class="text-sm text-slate-600 dark:text-slate-400">Conta conjunta</label>
                </div>

                @unless ($accountIsJoint)
                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="accountVisibleToPartner" id="accountVisibleToPartner" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="accountVisibleToPartner" class="text-sm text-slate-600 dark:text-slate-400">Visível pro cônjuge</label>
                    </div>

                    @if ($accountVisibleToPartner)
                        <div class="flex items-center gap-2 pt-5">
                            <input type="checkbox" wire:model="accountIncludedInConsolidated" id="accountIncludedInConsolidated" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <label for="accountIncludedInConsolidated" class="text-sm text-slate-600 dark:text-slate-400">Entra no consolidado</label>
                        </div>
                    @endif
                @else
                    <p class="self-end pb-2 text-xs text-slate-400">Conjunta é sempre visível e entra no consolidado.</p>
                @endunless

                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                    <textarea wire:model="accountNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">
                    {{ $editingAccountId ? 'Salvar alterações' : 'Salvar conta' }}
                </button>
            </div>
        </form>
    @endif

    {{-- Novo/editar cartão --------------------------------------------------- --}}
    @if ($showCardForm)
        <form wire:submit="saveCard" class="card space-y-4 p-5">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingCardId ? 'Editar cartão' : 'Novo cartão' }}</h2>
                <button type="button" wire:click="toggleCardForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do cartão</label>
                    <input type="text" wire:model="cardName" class="input mt-1.5" placeholder="Ex.: Nubank Roxinho">
                    @error('cardName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Banco / emissor</label>
                    <input type="text" wire:model="cardBankName" class="input mt-1.5" placeholder="Ex.: Nubank">
                    @error('cardBankName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Bandeira</label>
                    <select wire:model="cardBrand" class="select mt-1.5 w-full">
                        @foreach (CardBrand::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Limite</label>
                    <input type="number" step="0.01" min="0.01" wire:model="cardLimit" class="input mt-1.5" placeholder="0,00">
                    @error('cardLimit') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia de fechamento</label>
                    <input type="number" min="1" max="31" wire:model="cardClosingDay" class="input mt-1.5">
                    @error('cardClosingDay') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia de vencimento</label>
                    <input type="number" min="1" max="31" wire:model="cardDueDay" class="input mt-1.5">
                    @error('cardDueDay') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Últimos 4 dígitos (opcional)</label>
                    <input type="text" maxlength="4" wire:model="cardLastFour" class="input mt-1.5" placeholder="0000">
                    @error('cardLastFour') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                    <select wire:model="cardMemberId" class="select mt-1.5 w-full">
                        <option value="">Selecione</option>
                        @foreach ($members as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('cardMemberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cor</label>
                    <input type="color" wire:model="cardColor" class="mt-1.5 h-10 w-full rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
                </div>

                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" wire:model="cardIsJoint" id="cardIsJoint" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <label for="cardIsJoint" class="text-sm text-slate-600 dark:text-slate-400">Cartão conjunto</label>
                </div>

                @unless ($cardIsJoint)
                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="cardVisibleToPartner" id="cardVisibleToPartner" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="cardVisibleToPartner" class="text-sm text-slate-600 dark:text-slate-400">Visível pro cônjuge</label>
                    </div>

                    @if ($cardVisibleToPartner)
                        <div class="flex items-center gap-2 pt-5">
                            <input type="checkbox" wire:model="cardIncludedInConsolidated" id="cardIncludedInConsolidated" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <label for="cardIncludedInConsolidated" class="text-sm text-slate-600 dark:text-slate-400">Entra no consolidado</label>
                        </div>
                    @endif
                @else
                    <p class="self-end pb-2 text-xs text-slate-400">Conjunto é sempre visível e entra no consolidado.</p>
                @endunless
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">
                    {{ $editingCardId ? 'Salvar alterações' : 'Salvar cartão' }}
                </button>
            </div>
        </form>
    @endif

    {{-- Totais ------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <p class="eyebrow">Saldo em contas</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($totalBalance) }}</p>
            <p class="mt-1 text-xs text-slate-400">Apenas contas incluídas no consolidado.</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Faturas em aberto</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($totalCardDebt) }}</p>
            <p class="mt-1 text-xs text-slate-400">Soma das faturas ainda não pagas.</p>
        </div>
    </div>

    {{-- Contas -------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Contas bancárias</h2>

        @if ($accounts->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma conta cadastrada.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($accounts as $account)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="h-9 w-1.5 shrink-0 rounded-full" style="background: {{ $account->color_hex ?? '#0F766E' }}"></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $account->bank_name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ $account->account_type->label() }}
                                    @if ($account->member) · {{ $account->member->name }} @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            @if ($account->is_joint)
                                <span class="rounded-full bg-brand-100 dark:bg-brand-500/20 px-2.5 py-0.5 text-xs text-brand-900 dark:text-brand-100">Conjunta</span>
                            @elseif (! $account->visible_to_partner)
                                <span class="rounded-full bg-slate-100 dark:bg-slate-700 px-2.5 py-0.5 text-xs text-slate-600 dark:text-slate-300">Privada</span>
                            @endif

                            <span class="text-sm font-semibold tabular-nums {{ (float) $account->current_balance < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">
                                {{ Money::format($account->current_balance) }}
                            </span>

                            @if ($confirmingDeleteAccountId === $account->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteAccount('{{ $account->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteAccount" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div
                                        x-show="open" x-transition x-cloak @click="open = false"
                                        class="absolute right-0 z-10 mt-1 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10"
                                    >
                                        <button wire:click="editAccount('{{ $account->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteAccount('{{ $account->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                                            {{ $account->hasActivity() ? 'Desativar' : 'Excluir' }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($inactiveAccounts->isNotEmpty())
            <div class="mt-3">
                <p class="text-xs text-slate-400">Desativadas ({{ $inactiveAccounts->count() }})</p>
                <ul class="mt-2 card divide-y divide-slate-100 dark:divide-white/10 opacity-60">
                    @foreach ($inactiveAccounts as $account)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-600 dark:text-slate-300">{{ $account->bank_name }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $account->account_type->label() }}</p>
                            </div>
                            <button wire:click="reactivateAccount('{{ $account->id }}')" class="btn-ghost px-2 py-1 text-xs">Reativar</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- Cartões ------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Cartões de crédito</h2>

        @if ($cards->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum cartão cadastrado.</p>
            </div>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($cards as $card)
                    @php $invoice = $currentInvoices->get($card->id); @endphp
                    <li class="card">
                        <div class="flex items-center justify-between gap-4 px-5 py-4">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="h-9 w-1.5 shrink-0 rounded-full" style="background: {{ $card->color_hex ?? '#B45309' }}"></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $card->displayName() }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $card->card_brand->label() }} · fecha dia {{ $card->closing_day }} · vence dia {{ $card->due_day }}
                                        @if ($card->member) · {{ $card->member->name }} @endif
                                    </p>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <div class="text-right">
                                    @if ($invoice)
                                        <p class="text-sm font-semibold tabular-nums text-slate-800 dark:text-slate-200">
                                            {{ Money::format($invoice->total_amount) }}
                                        </p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $invoice->status->label() }} · vence {{ $invoice->due_date->format('d/m') }}
                                        </p>
                                    @else
                                        <p class="text-xs text-slate-400">Sem fatura aberta</p>
                                    @endif
                                </div>

                                @if ($confirmingDeleteCardId === $card->id)
                                    <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                    <button wire:click="deleteCard('{{ $card->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                    <button wire:click="cancelDeleteCard" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                                @else
                                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                        <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                            <x-nav-icon name="dots" class="h-4 w-4" />
                                        </button>
                                        <div
                                            x-show="open" x-transition x-cloak @click="open = false"
                                            class="absolute right-0 z-10 mt-1 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10"
                                        >
                                            <button wire:click="editCard('{{ $card->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                            <button wire:click="confirmDeleteCard('{{ $card->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                                                {{ $card->hasActivity() ? 'Desativar' : 'Excluir' }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($invoice)
                            <div class="border-t border-slate-100 dark:border-white/10 px-5 py-3">
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-sm text-brand-800 dark:text-brand-300 hover:underline">
                                    Ver fatura de {{ $invoice->competenceLabel() }} →
                                </a>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($inactiveCards->isNotEmpty())
            <div class="mt-3">
                <p class="text-xs text-slate-400">Desativados ({{ $inactiveCards->count() }})</p>
                <ul class="mt-2 card divide-y divide-slate-100 dark:divide-white/10 opacity-60">
                    @foreach ($inactiveCards as $card)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-600 dark:text-slate-300">{{ $card->displayName() }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $card->card_brand->label() }}</p>
                            </div>
                            <button wire:click="reactivateCard('{{ $card->id }}')" class="btn-ghost px-2 py-1 text-xs">Reativar</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

</div>
