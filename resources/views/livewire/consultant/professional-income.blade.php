@php
    use App\Enums\IncomeStatus;
    use App\Support\Money;

    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $rotuloMes = $referencia->translatedFormat('F \d\e Y');
    $maximo = max(1.0, ...collect($evolucao)->flatMap(fn ($m) => [(float) $m['previsto'], (float) $m['recebido']])->all());
    $ehMesAtual = $referencia->isSameMonth(now());
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Recebimentos</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $ehConsultor ? 'O que entra por mês dos seus clientes: honorários, comissões e o que mais você lançar.' : 'O que entra por mês das comissões e do que mais você lançar.' }}
                Só você vê esta tela.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousMonth" class="btn-ghost px-2.5 py-1.5" aria-label="Mês anterior">
                <x-nav-icon name="chevron" class="h-4 w-4 rotate-90" />
            </button>
            <p class="min-w-36 text-center text-sm font-medium text-slate-800 first-letter:uppercase dark:text-slate-100">{{ $rotuloMes }}</p>
            <button type="button" wire:click="nextMonth" class="btn-ghost px-2.5 py-1.5" aria-label="Próximo mês">
                <x-nav-icon name="chevron" class="h-4 w-4 -rotate-90" />
            </button>
            @unless ($ehMesAtual)
                <button type="button" wire:click="thisMonth" class="btn-secondary px-3 py-1.5 text-xs">Mês atual</button>
            @endunless
        </div>
    </div>

    @if ($aviso)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $aviso }}</div>
    @endif

    @php
        $abas = $ehConsultor
            ? ['recebimentos' => 'Recebimentos', 'contratos' => 'Contratos de honorário', 'regras' => 'Regras de comissionamento']
            : ['recebimentos' => 'Recebimentos', 'regras' => 'Regras de comissionamento'];
    @endphp
    <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-white/5" role="tablist" aria-label="Recebimentos">
        @foreach ($abas as $chave => $rotulo)
            <button type="button" role="tab" wire:click="setAba('{{ $chave }}')" @class([
                'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition',
                'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $aba === $chave,
                'text-slate-500 dark:text-slate-400' => $aba !== $chave,
            ])>{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($aba === 'recebimentos')
        {{-- Totais ------------------------------------------------------- --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card p-5">
                <p class="eyebrow">Previsto no mês</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($resumo['previsto']) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Recebido no mês</p>
                <p class="figure mt-2 text-2xl font-medium text-emerald-700 dark:text-emerald-400">{{ Money::format($resumo['recebido']) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Falta receber</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($resumo['a_receber']) }}</p>
                @if (bccomp($resumo['atrasado'], '0', 2) > 0)
                    <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ Money::format($resumo['atrasado']) }} já passou do vencimento</p>
                @endif
            </div>
            <div class="card p-5">
                <p class="eyebrow">Recebido em {{ $referencia->format('Y') }}</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($totaisDoAno['recebido']) }}</p>
                <p class="mt-1 text-xs text-slate-400">de {{ Money::format($totaisDoAno['previsto']) }} previstos no ano</p>
            </div>
        </div>

        {{-- Evolução ----------------------------------------------------- --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Últimos 12 meses</h2>
                <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-500"></span>previsto</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-600 dark:bg-emerald-400"></span>recebido</span>
                </div>
            </div>
            <div class="mt-4">
                <x-bar-chart
                    :meses="collect($evolucao)->pluck('rotulo')->all()"
                    :series="[
                        ['cor' => 'currentColor', 'classe' => 'text-slate-300 dark:text-slate-500', 'valores' => collect($evolucao)->pluck('previsto')->map(fn ($v) => (float) $v)->all(), 'rotulo' => 'Previsto'],
                        ['cor' => 'currentColor', 'classe' => 'text-emerald-600 dark:text-emerald-400', 'valores' => collect($evolucao)->pluck('recebido')->map(fn ($v) => (float) $v)->all(), 'rotulo' => 'Recebido'],
                    ]"
                    :maximo="$maximo"
                    :height="180"
                    :width="720"
                    modo="agrupado"
                />
            </div>
        </div>

        {{-- Lançamentos do mês ------------------------------------------- --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                    Lançamentos de <span class="first-letter:uppercase">{{ $rotuloMes }}</span>
                    <span class="font-normal text-slate-400">({{ $entradas->count() }})</span>
                </h2>
                <button type="button" wire:click="openEntryForm" class="btn-primary px-3 py-1.5 text-xs">Novo lançamento</button>
            </div>

            @if ($entradas->isEmpty())
                <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nada lançado neste mês.</p>
                    <p class="mt-1 text-xs text-slate-400">
                        @if ($ehConsultor)
                            Cadastre um contrato de honorário para o Cerne prever o que entra, ou use "Novo lançamento".
                        @else
                            Use "Novo lançamento" para registrar o que você recebe.
                        @endif
                    </p>
                </div>
            @else
                <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($entradas as $e)
                        @php
                            $cliente = $e->profile ? \App\Support\ClientDisplayName::forProfile($e->profile) : null;
                            $manual = ! $e->isGenerated();
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="entrada-{{ $e->id }}">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg bg-slate-100 leading-none dark:bg-white/10">
                                    <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $e->due_on->format('d') }}</span>
                                    <span class="mt-0.5 text-[9px] font-medium text-slate-500 uppercase dark:text-slate-400">{{ $e->due_on->translatedFormat('M') }}</span>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $cliente ?? ($e->description ?: 'Sem descrição') }}</p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="badge {{ $e->source->badgeClasses() }}">{{ $e->source->label() }}</span>
                                        @if ($cliente && $e->description)
                                            <span class="truncate">{{ $e->description }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="figure text-sm text-slate-800 dark:text-slate-200">
                                        {{ Money::format($e->status === IncomeStatus::Received ? $e->received_amount : $e->amount) }}
                                    </p>
                                    @if ($e->status === IncomeStatus::Received)
                                        <p class="text-xs text-emerald-700 dark:text-emerald-400">Recebido em {{ $e->received_on?->format('d/m/Y') }}</p>
                                    @elseif ($e->status === IncomeStatus::Cancelled)
                                        <p class="text-xs text-slate-400">Cancelado</p>
                                    @elseif ($e->isOverdue())
                                        <p class="text-xs text-red-700 dark:text-red-400">Atrasado</p>
                                    @else
                                        <p class="text-xs text-slate-400">Previsto</p>
                                    @endif
                                </div>

                                <div class="flex items-center gap-1">
                                    @if ($e->status === IncomeStatus::Expected)
                                        <button type="button" wire:click="openReceive('{{ $e->id }}')" class="btn-secondary px-3 py-1.5 text-xs whitespace-nowrap">Dar baixa</button>
                                    @endif
                                    <div class="relative" x-data="{ aberto: false }" @keydown.escape.window="aberto = false">
                                        <button type="button" @click="aberto = ! aberto" @click.outside="aberto = false" :aria-expanded="aberto" aria-haspopup="menu" aria-label="Mais ações" class="btn-ghost px-2 py-1.5">
                                            <x-nav-icon name="dots" class="h-4 w-4" />
                                        </button>
                                        <div x-show="aberto" x-cloak role="menu" class="absolute right-0 z-20 mt-1 w-44 rounded-xl border border-slate-200 bg-white p-1 text-xs shadow-lg dark:border-white/10 dark:bg-slate-800">
                                            @if ($manual)
                                                <button type="button" role="menuitem" wire:click="openEntryForm('{{ $e->id }}')" @click="aberto = false" class="flex w-full rounded-lg px-3 py-2 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">Editar</button>
                                            @endif
                                            @if ($e->status !== IncomeStatus::Expected)
                                                <button type="button" role="menuitem" wire:click="reopenEntry('{{ $e->id }}')" @click="aberto = false" class="flex w-full rounded-lg px-3 py-2 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">Voltar para previsto</button>
                                            @endif
                                            @if ($e->status !== IncomeStatus::Cancelled)
                                                <button type="button" role="menuitem" wire:click="cancelEntry('{{ $e->id }}')" wire:confirm="Cancelar este recebimento? Ele sai dos totais." @click="aberto = false" class="flex w-full rounded-lg px-3 py-2 text-left text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Cancelar</button>
                                            @endif
                                            @if ($manual)
                                                <button type="button" role="menuitem" wire:click="deleteEntry('{{ $e->id }}')" wire:confirm="Excluir este lançamento?" @click="aberto = false" class="flex w-full rounded-lg px-3 py-2 text-left text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Por origem e por cliente ------------------------------------- --}}
        @if ($resumo['por_origem'] !== [])
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="card p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por origem</h2>
                    <div class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($resumo['por_origem'] as $linha)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <span class="badge {{ $linha['origem']->badgeClasses() }}">{{ $linha['origem']->label() }}</span>
                                <span class="text-right">
                                    <span class="figure block text-sm text-slate-800 dark:text-slate-200">{{ Money::format($linha['recebido']) }}</span>
                                    <span class="block text-xs text-slate-400">de {{ Money::format($linha['previsto']) }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por cliente</h2>
                    <div class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($resumo['por_cliente'] as $linha)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <span class="min-w-0 truncate text-sm text-slate-700 dark:text-slate-300">{{ $linha['cliente'] }}</span>
                                <span class="shrink-0 text-right">
                                    <span class="figure block text-sm text-slate-800 dark:text-slate-200">{{ Money::format($linha['recebido']) }}</span>
                                    <span class="block text-xs text-slate-400">de {{ Money::format($linha['previsto']) }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    @elseif ($aba === 'regras')
        <livewire:consultant.commission-rules />

        @if ($ehConsultor)
            <livewire:consultant.investment-rules />
        @endif
    @else
        {{-- Contratos de honorário --------------------------------------- --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Contratos de honorário</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Valor fixo combinado com o cliente. O Cerne prevê os próximos 12 meses a partir do mês atual.</p>
                </div>
                <button type="button" wire:click="openAgreementForm" class="btn-primary px-3 py-1.5 text-xs">Novo contrato</button>
            </div>

            @if ($contratos->isEmpty())
                <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum contrato cadastrado.</p>
                </div>
            @else
                <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($contratos as $c)
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3" wire:key="contrato-{{ $c->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ \App\Support\ClientDisplayName::forProfile($c->profile) }}</p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $c->period->label() }} · desde {{ $c->starts_on->format('d/m/Y') }}@if ($c->ends_on) · até {{ $c->ends_on->format('d/m/Y') }}@endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-right">
                                    <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($c->amount) }}</p>
                                    <p class="text-xs {{ $c->is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}">{{ $c->is_active ? 'Ativo' : 'Pausado' }}</p>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="openAgreementForm('{{ $c->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                                    <button type="button" wire:click="toggleAgreement('{{ $c->id }}', {{ $c->is_active ? 'false' : 'true' }})" class="btn-ghost px-3 py-1.5 text-xs">{{ $c->is_active ? 'Pausar' : 'Reativar' }}</button>
                                    <button type="button" wire:click="deleteAgreement('{{ $c->id }}')" wire:confirm="Excluir este contrato? O que ainda é só previsão some; o que já foi recebido continua no livro." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Excluir</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <livewire:consultant.asset-fee-contracts />
    @endif

    {{-- Lançamento ------------------------------------------------------- --}}
    <x-modal wire-model="showEntryForm" max-width="lg">
        <form wire:submit="saveEntry" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $entryId ? 'Editar lançamento' : 'Novo lançamento' }}</h2>
                <button type="button" wire:click="$set('showEntryForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">Origem</label>
                    <select wire:model="entrySource" class="select mt-1.5 w-full">
                        @foreach ($origens as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('entrySource') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Cliente (opcional)</label>
                    <select wire:model="entryProfileId" class="select mt-1.5 w-full">
                        <option value="">Sem cliente</option>
                        @foreach ($clientes as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                    @error('entryProfileId') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Descrição (opcional)</label>
                    <input type="text" wire:model="entryDescription" class="input mt-1.5" placeholder="Ex.: Comissão da apólice 1234 - Porto Seguro">
                    @error('entryDescription') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Data</label>
                    <input type="date" wire:model="entryDueOn" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Define o mês em que aparece.</p>
                    @error('entryDueOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Valor (R$)</label>
                    <input type="number" step="0.01" min="0" wire:model="entryAmount" class="input mt-1.5" placeholder="0,00">
                    @error('entryAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div @class(['@sm:col-span-2' => $entryStatus !== 'received'])>
                    <label class="{{ $campo }}">Situação</label>
                    <select wire:model.live="entryStatus" class="select mt-1.5 w-full">
                        <option value="expected">Previsto</option>
                        <option value="received">Já recebi</option>
                    </select>
                    @error('entryStatus') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                @if ($entryStatus === 'received')
                    <div>
                        <label class="{{ $campo }}">Data do recebimento</label>
                        <input type="date" wire:model="entryReceivedOn" class="input mt-1.5">
                        @error('entryReceivedOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Valor recebido (R$)</label>
                        <input type="number" step="0.01" min="0" wire:model="entryReceivedAmount" class="input mt-1.5" placeholder="Deixe vazio se for igual ao valor">
                        @error('entryReceivedAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>

    {{-- Dar baixa -------------------------------------------------------- --}}
    <x-modal wire-model="showReceiveForm" max-width="sm">
        <form wire:submit="confirmReceive" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar recebimento</h2>
                <button type="button" wire:click="$set('showReceiveForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">Valor recebido (R$)</label>
                    <input type="number" step="0.01" min="0" wire:model="receiveAmount" class="input mt-1.5">
                    @error('receiveAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Data do recebimento</label>
                    <input type="date" wire:model="receiveOn" class="input mt-1.5">
                    @error('receiveOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-slate-400">O valor já vem preenchido com o previsto. Mude se a seguradora ou o cliente pagou diferente.</p>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Confirmar</button>
            </div>
        </form>
    </x-modal>

    {{-- Contrato --------------------------------------------------------- --}}
    @if ($ehConsultor)
        <x-modal wire-model="showAgreementForm" max-width="lg">
            <form wire:submit="saveAgreement" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $agreementId ? 'Editar contrato' : 'Novo contrato de honorário' }}</h2>
                    <button type="button" wire:click="$set('showAgreementForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 @sm:grid-cols-2">
                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Cliente</label>
                        <select wire:model="agreementProfileId" class="select mt-1.5 w-full">
                            <option value="">Escolha o cliente</option>
                            @foreach ($clientes as $id => $nome)
                                <option value="{{ $id }}">{{ $nome }}</option>
                            @endforeach
                        </select>
                        @error('agreementProfileId') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $campo }}">Valor por cobrança (R$)</label>
                        <input type="number" step="0.01" min="0" wire:model="agreementAmount" class="input mt-1.5" placeholder="0,00">
                        @error('agreementAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $campo }}">Periodicidade</label>
                        <select wire:model="agreementPeriod" class="select mt-1.5 w-full">
                            @foreach ($periodos as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('agreementPeriod') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $campo }}">Primeira cobrança</label>
                        <input type="date" wire:model="agreementStartsOn" class="input mt-1.5">
                        <p class="mt-1 text-xs text-slate-400">As seguintes caem no mesmo dia.</p>
                        @error('agreementStartsOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $campo }}">Término (opcional)</label>
                        <input type="date" wire:model="agreementEndsOn" class="input mt-1.5">
                        <p class="mt-1 text-xs text-slate-400">Vazio = sem data para acabar.</p>
                        @error('agreementEndsOn') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Observação (opcional)</label>
                        <input type="text" wire:model="agreementNotes" class="input mt-1.5" placeholder="Ex.: Plano anual, reajuste em janeiro">
                        @error('agreementNotes') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
