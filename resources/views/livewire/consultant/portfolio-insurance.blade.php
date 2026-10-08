@use('App\Support\Money')
@use('App\Models\InsurancePolicy')

@php
    $badgeColors = ['bg-brand-700', 'bg-accent-700', 'bg-brand-500', 'bg-accent-600', 'bg-brand-900'];
    $rotulo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
@endphp

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Seguros da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalGeral }} {{ $totalGeral === 1 ? 'apólice ativa' : 'apólices ativas' }} entre os clientes vinculados,
                um cartão por cliente.
            </p>
        </div>

        @if (auth()->user()->isBroker())
            <button type="button" wire:click="toggleInviteForm" class="btn-secondary">
                {{ $showInviteForm ? 'Cancelar' : '+ Vincular cliente' }}
            </button>
        @endif
    </div>

    {{-- Vínculo com cliente novo — só o corretor vê este bloco; o
         consultor já tem o formulário equivalente em PortfolioOverview. --}}
    @if (auth()->user()->isBroker() && $showInviteForm)
        <div class="card space-y-4 p-5">
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Vincular um cliente</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    E-mail sem conta vira convite de cadastro; e-mail que já tem conta no Cerne vira um pedido —
                    o cliente decide, na hora de autorizar, quais apólices já cadastradas liberar para você.
                </p>
            </div>

            <form wire:submit="invite" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="inviteName" class="input mt-1.5" placeholder="Nome do cliente">
                    @error('inviteName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">E-mail</label>
                    <input type="email" wire:model="inviteEmail" class="input mt-1.5" placeholder="email@exemplo.com">
                    @error('inviteEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Vincular</button>
                </div>
            </form>

            @if ($lastInviteLink)
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-700">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link — copie e envie por outro canal se o e-mail não chegar</p>
                    <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
                </div>
            @endif

            @if ($this->pendingInvites->isNotEmpty())
                <div class="border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Convites enviados, aguardando cadastro</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($this->pendingInvites as $convite)
                            <li class="flex items-center justify-between gap-3 py-1.5 text-sm">
                                <div class="min-w-0">
                                    <span class="text-slate-700 dark:text-slate-300">{{ $convite->client_name }}</span>
                                    <span class="text-xs text-slate-400">{{ $convite->client_email }} · expira {{ $convite->expires_at->diffForHumans() }}</span>
                                </div>
                                <button type="button" wire:click="reenviarConvite('{{ $convite->id }}')" wire:loading.attr="disabled" class="btn-ghost shrink-0 px-2 py-1 text-xs whitespace-nowrap">
                                    Reenviar
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- Filtros --------------------------------------------------------- --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-3">
                <label for="busca-seguros" class="{{ $rotulo }}">Buscar</label>
                <input id="busca-seguros" type="search" wire:model.live.debounce.300ms="busca" class="input mt-1.5" placeholder="Cliente, pessoa, seguradora, nº da apólice ou item">
            </div>

            <div>
                <label for="tipo-seguros" class="{{ $rotulo }}">Tipo de seguro</label>
                <select id="tipo-seguros" wire:model.live="tipo" class="select mt-1.5 w-full">
                    <option value="">Todos os tipos</option>
                    @foreach ($tipos as $opcao)
                        <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="seguradora-seguros" class="{{ $rotulo }}">Seguradora</label>
                <select id="seguradora-seguros" wire:model.live="seguradora" class="select mt-1.5 w-full">
                    <option value="">Todas as seguradoras</option>
                    @foreach ($seguradoras as $nome)
                        <option value="{{ $nome }}">{{ $nome }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="situacao-seguros" class="{{ $rotulo }}">Situação</label>
                <select id="situacao-seguros" wire:model.live="situacao" class="select mt-1.5 w-full">
                    <option value="">Todas</option>
                    <option value="vencendo">Vencendo em 30 dias</option>
                    <option value="incompleta">Dados incompletos</option>
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <p>
                {{ $grouped->count() }} {{ $grouped->count() === 1 ? 'cliente' : 'clientes' }}
                · {{ $totalFiltrado }} @if ($filtrando) de {{ $totalGeral }} @endif {{ $totalFiltrado === 1 ? 'apólice' : 'apólices' }}
            </p>

            <div class="flex items-center gap-3">
                @if ($filtrando)
                    <button type="button" wire:click="limparFiltros" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Limpar filtros</button>
                @endif
                <button type="button" x-on:click="$dispatch('portfolio-toggle', { open: true })" class="hover:underline">Expandir todos</button>
                <button type="button" x-on:click="$dispatch('portfolio-toggle', { open: false })" class="hover:underline">Recolher todos</button>
            </div>
        </div>
    </div>

    @if ($grouped->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                @if ($filtrando)
                    Nenhuma apólice combina com os filtros escolhidos.
                @else
                    Nenhuma apólice ativa entre os clientes vinculados ainda.
                @endif
            </p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($grouped as $clienteNome => $porCliente)
                {{-- Fechado por padrão, como em Investimentos da carteira. "Expandir/Recolher todos" fala com todos os cartões. --}}
                <div x-data="{ open: false }" x-on:portfolio-toggle.window="open = $event.detail.open" class="card overflow-hidden" wire:key="cliente-{{ $porCliente['profile_id'] }}">
                    <div class="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <button type="button" x-on:click="open = ! open" class="w-full min-w-0 text-left sm:w-auto sm:flex-1">
                            <p class="font-display text-lg font-semibold text-slate-900 sm:text-xl dark:text-white">{{ $clienteNome }}</p>

                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                @foreach ($porCliente['tipos'] as $tipoDoCliente)
                                    <span class="badge bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{{ $tipoDoCliente->label() }}</span>
                                @endforeach
                                <span class="text-xs text-slate-400">
                                    {{ $porCliente['quantidade'] }} {{ $porCliente['quantidade'] === 1 ? 'apólice' : 'apólices' }}
                                    · {{ $porCliente['seguradoras']->implode(', ') }}
                                </span>
                                @if ($porCliente['vencendo'] > 0)
                                    <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                        {{ $porCliente['vencendo'] }} vencendo
                                    </span>
                                @endif
                                @if ($porCliente['incompletas'] > 0)
                                    <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">
                                        {{ $porCliente['incompletas'] }} {{ $porCliente['incompletas'] === 1 ? 'incompleta' : 'incompletas' }}
                                    </span>
                                @endif
                            </div>
                        </button>

                        <div class="flex w-full items-center justify-between gap-4 sm:w-auto sm:justify-end">
                            {{-- Só o custo: somar capitais de tipos diferentes (morte, carro, casa...) não diz nada. --}}
                            <div class="text-left sm:text-right">
                                <p class="figure text-sm font-semibold text-slate-800 dark:text-slate-200">{{ Money::format($porCliente['mensal']) }}</p>
                                <p class="text-xs text-slate-400">por mês</p>
                            </div>

                            <form method="POST" action="{{ route('profile.switch', $porCliente['profile_id']) }}">
                                @csrf
                                <button type="submit" class="btn-secondary px-3 py-1.5 whitespace-nowrap">Abrir perfil</button>
                            </form>

                            <button type="button" x-on:click="open = ! open" aria-label="Mostrar ou esconder as apólices de {{ $clienteNome }}">
                                <x-nav-icon
                                    name="chevron"
                                    class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200"
                                    x-bind:class="{ 'rotate-180': open }"
                                />
                            </button>
                        </div>
                    </div>

                    <div x-show="open" x-cloak x-transition class="divide-y divide-slate-100 border-t border-slate-100 px-5 dark:divide-white/10 dark:border-white/10">
                        @foreach ($porCliente['pessoas'] as $pessoa)
                            <div class="py-3">
                                @if ($porCliente['separarPorPessoa'])
                                    <p class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                        {{ $pessoa['nome'] ?? 'Seguro familiar' }}
                                    </p>
                                @endif

                                <div class="overflow-x-auto">
                                    {{-- table-fixed + colgroup: com uma tabela por pessoa, sem largura fixa as colunas não alinhavam entre uma e outra. --}}
                                    <table class="w-full min-w-[720px] table-fixed text-sm">
                                        <colgroup>
                                            <col class="w-[11%]">
                                            <col class="w-[21%]">
                                            <col class="w-[21%]">
                                            <col class="w-[13%]">
                                            <col class="w-[18%]">
                                            <col class="w-[16%]">
                                        </colgroup>
                                        <thead>
                                            <tr class="text-left text-[10.5px] tracking-wide text-slate-400 uppercase">
                                                <th class="pb-1.5 font-medium">Tipo</th>
                                                <th class="pb-1.5 font-medium">Seguradora</th>
                                                <th class="pb-1.5 font-medium">Item ou apólice</th>
                                                <th class="pb-1.5 text-right font-medium">Capital</th>
                                                <th class="pb-1.5 text-right font-medium">Custo</th>
                                                <th class="pb-1.5 pl-3 font-medium">Vencimento</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                            @foreach ($pessoa['linhas'] as $linha)
                                                @php $apolice = $linha['policy']; @endphp
                                                <tr>
                                                    <td class="py-2 pr-3 align-top">
                                                        <span class="badge bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{{ $apolice->insurance_type->label() }}</span>
                                                    </td>
                                                    <td class="py-2 pr-3 align-top">
                                                        <div class="flex items-center gap-2">
                                                            <x-insurer-badge
                                                                :initials="InsurancePolicy::initialsFor($linha['seguradora'])"
                                                                :color="\App\Models\Insurer::colorIn($insurerColors, $linha['seguradora'])"
                                                                :fallback="$badgeColors[InsurancePolicy::colorIndexFor($linha['seguradora'], count($badgeColors))]"
                                                                class="h-6 w-6 shrink-0 rounded-md text-[10px] font-semibold"
                                                            />
                                                            <span class="truncate font-medium text-slate-700 dark:text-slate-300">{{ $linha['seguradora'] }}</span>
                                                        </div>
                                                    </td>
                                                    <td class="py-2 pr-3 align-top text-slate-600 dark:text-slate-400">
                                                        @if ($apolice->insured_item)
                                                            <p class="truncate text-slate-800 dark:text-slate-200" title="{{ $apolice->insured_item }}">{{ $apolice->insured_item }}</p>
                                                            @if ($apolice->policy_number)
                                                                <p class="truncate text-xs text-slate-400" title="Apólice {{ $apolice->policy_number }}">Apólice {{ $apolice->policy_number }}</p>
                                                            @endif
                                                        @elseif ($apolice->policy_number)
                                                            <p class="truncate" title="Apólice {{ $apolice->policy_number }}">Apólice {{ $apolice->policy_number }}</p>
                                                        @else
                                                            <p class="text-slate-400">—</p>
                                                        @endif
                                                        @if ($apolice->isMissingCost())
                                                            <p class="text-xs text-amber-700 dark:text-amber-400">Dados incompletos</p>
                                                        @endif
                                                    </td>
                                                    <td class="py-2 pr-3 text-right align-top tabular-nums text-slate-800 dark:text-slate-200">
                                                        {{ $apolice->principalCapital() !== null ? Money::compact($apolice->principalCapital()) : '—' }}
                                                    </td>
                                                    <td class="py-2 pr-3 text-right align-top whitespace-nowrap tabular-nums text-slate-500 dark:text-slate-400">
                                                        @if ($apolice->isMissingCost())
                                                            <span class="text-slate-400">—</span>
                                                        @else
                                                            {{ Money::format($apolice->normalizedMonthlyCost()) }}/mês
                                                        @endif
                                                    </td>
                                                    <td class="py-2 pl-3 align-top whitespace-nowrap">
                                                        @if ($apolice->expiry_date === null)
                                                            <span class="text-xs text-slate-400">{{ $apolice->validityLabel() }}</span>
                                                        @elseif ($apolice->isExpiring(30))
                                                            <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                                                {{ $apolice->expiry_date->format('d/m/Y') }}
                                                            </span>
                                                        @else
                                                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ $apolice->expiry_date->format('d/m/Y') }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
