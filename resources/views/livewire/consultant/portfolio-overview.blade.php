@use('App\Support\Money')
@use('App\Enums\ConsultantClientStatus')
@use('App\Enums\ProfileType')

<div class="space-y-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Painel da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Panorama de {{ $dados['clientes']['ativos'] }}
                {{ $dados['clientes']['ativos'] === 1 ? 'cliente com vínculo ativo' : 'clientes com vínculo ativo' }}
            </p>
        </div>
        <button type="button" wire:click="toggleInviteForm" class="btn-secondary">
            {{ $showInviteForm ? 'Cancelar' : '+ Convidar cliente' }}
        </button>
    </div>

    @if ($showInviteForm)
        <div class="card space-y-4 p-5">
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Convidar um cliente</p>

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
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Convidar</button>
                </div>
            </form>

            @if ($lastInviteLink)
                {{-- O link é mostrado para o caso de o e-mail não chegar; o
                     consultor consegue repassá-lo por outro canal. --}}
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-700">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link do convite</p>
                    <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
                </div>
            @endif

            @if ($this->pendingInvites->isNotEmpty())
                <div class="border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Convites enviados, aguardando cadastro</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($this->pendingInvites as $convite)
                            <li class="flex items-baseline justify-between py-1.5 text-sm">
                                <span class="text-slate-700 dark:text-slate-300">{{ $convite->client_name }}</span>
                                <span class="text-xs text-slate-400">{{ $convite->client_email }} · expira {{ $convite->expires_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- KPIs ---------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card relative overflow-hidden bg-brand-800 p-6 text-white">
            <div class="pointer-events-none absolute -top-16 -right-16 h-40 w-40 rounded-full bg-brand-600/50 dark:bg-brand-400/20 blur-3xl"></div>
            <p class="relative eyebrow text-brand-200">Patrimônio total</p>
            <p class="relative figure mt-2 text-3xl font-medium">{{ Money::compact($dados['patrimonio']['liquido']) }}</p>
            <p class="relative mt-1 text-xs text-brand-200">{{ Money::format($dados['patrimonio']['liquido']) }}</p>
        </div>

        <div class="card p-6">
            <p class="eyebrow">Clientes vinculados</p>
            <p class="figure mt-2 text-3xl font-medium text-slate-900 dark:text-white">{{ $dados['clientes']['ativos'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ $dados['clientes']['total'] }} no total, incluindo pendentes e inativos
            </p>
        </div>

        <div class="card p-6">
            <p class="eyebrow">Prêmios / mês</p>
            <p class="figure mt-2 text-3xl font-medium text-slate-900 dark:text-white">{{ Money::format($dados['premios_mes']) }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">soma dos seguros ativos da carteira</p>
        </div>

        <div class="card p-6">
            <p class="eyebrow">Multiproduto</p>
            <p class="figure mt-2 text-3xl font-medium text-accent-600 dark:text-accent-400">{{ $dados['multiproduto'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ $dados['multiproduto'] === 1 ? 'cliente com 2+ apólices' : 'clientes com 2+ apólices' }}
            </p>
        </div>
    </div>

    {{-- Ações pendentes ------------------------------------------------ --}}
    @php
        $pendentes = $dados['acoes_pendentes'];
        $temAcoes = ! empty($pendentes['vinculos']) || ! empty($pendentes['faturas']);
    @endphp
    @if ($temAcoes)
        <div class="card border-l-4 border-l-amber-500 p-5">
            <p class="text-sm font-medium text-slate-900 dark:text-white">Ações pendentes</p>

            @if (! empty($pendentes['vinculos']))
                <div class="mt-3">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Convites aguardando aceite</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($pendentes['vinculos'] as $vinculo)
                            <li class="flex items-baseline justify-between py-1.5 text-sm">
                                <span class="text-slate-700 dark:text-slate-300">{{ $vinculo['name'] }}</span>
                                <span class="text-xs text-amber-700 dark:text-amber-400">
                                    {{ $vinculo['dias'] }} {{ $vinculo['dias'] === 1 ? 'dia' : 'dias' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($pendentes['faturas']))
                <div class="mt-4 {{ ! empty($pendentes['vinculos']) ? 'border-t border-slate-100 pt-4 dark:border-white/10' : '' }}">
                    <div class="flex items-baseline justify-between">
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Faturas vencendo nos próximos {{ $pendentes['dias'] }} dias
                        </p>
                        <span class="figure text-sm text-slate-700 dark:text-slate-300">{{ Money::format($pendentes['total_faturas']) }}</span>
                    </div>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($pendentes['faturas'] as $fatura)
                            <li class="flex items-baseline justify-between py-1.5 text-sm">
                                <span class="text-slate-700 dark:text-slate-300">
                                    {{ $fatura['cliente'] }}
                                    <span class="text-slate-400">· {{ $fatura['nome'] }} · {{ $fatura['vencimento'] }}</span>
                                </span>
                                <span class="figure text-slate-800 dark:text-slate-200">{{ Money::format($fatura['valor']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- Evolução do patrimônio investido ----------------------------------- --}}
    @php
        $evolucao = collect($dados['evolucao_investido']);
        $maximo = max($evolucao->max(fn ($m) => (float) $m['valor']), 1);
    @endphp
    <div class="card p-6">
        <p class="eyebrow">Patrimônio investido · últimos {{ $evolucao->count() }} meses</p>
        <p class="mt-0.5 text-xs text-slate-400">soma dos investimentos ativos da carteira — contas e faturas não têm histórico mensal</p>

        <div class="mt-6 flex items-end gap-2 sm:gap-4" style="height: 140px">
            @foreach ($evolucao as $mes)
                <div class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5">
                    <div
                        class="w-full max-w-8 rounded-t-md bg-brand-600 transition-colors group-hover:bg-brand-500 dark:bg-brand-400 dark:group-hover:bg-brand-300"
                        style="height: {{ max(2, ((float) $mes['valor'] / $maximo) * 100) }}%"
                        title="{{ Money::format($mes['valor']) }}"
                    ></div>
                    <span class="w-full truncate text-center text-[10px] text-slate-400">{{ $mes['rotulo'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Composição da carteira ---------------------------------------------- --}}
    @php $totalDist = $dados['distribuicao']['individual'] + $dados['distribuicao']['casal']; @endphp
    @if ($totalDist > 0)
        <div class="card p-6">
            <p class="eyebrow">Composição da carteira</p>

            <div class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2 {{ $dados['distribuicao']['casal'] > 0 ? 'lg:grid-cols-4' : '' }}">
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Individual</p>
                    <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $dados['distribuicao']['individual'] }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Casal</p>
                    <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $dados['distribuicao']['casal'] }}</p>
                </div>

                @if ($dados['distribuicao']['casal'] > 0)
                    <div>
                        <p class="text-xs text-accent-600 dark:text-accent-400">Vida financeira única</p>
                        <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $dados['distribuicao']['vida_financeira']['unica'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-amber-700 dark:text-amber-400">Vida financeira separada</p>
                        <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $dados['distribuicao']['vida_financeira']['separada'] }}</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Sem seguro de vida ------------------------------------------------ --}}
    @if (! empty($dados['sem_seguro_vida']) || $buscaSemSeguro !== '')
        <section>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                    Sem seguro de vida
                    <span class="font-normal text-slate-400">({{ count($dados['sem_seguro_vida']) }})</span>
                </h2>

                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="buscaSemSeguro"
                        placeholder="Buscar por nome ou e-mail"
                        class="rounded-lg border-slate-200 bg-white py-1 text-xs dark:border-white/10 dark:bg-slate-800 dark:text-slate-200"
                    >
                    <label class="flex items-center gap-2">
                        Ordenar por
                        <select wire:model.live="ordenarSemSeguro" class="rounded-lg border-slate-200 bg-white py-1 text-xs dark:border-white/10 dark:bg-slate-800 dark:text-slate-200">
                            @foreach ($ordensSemSeguro as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>

            @if (empty($dados['sem_seguro_vida']))
                <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-6 text-center dark:border-slate-600 dark:bg-slate-800/40">
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum resultado para "{{ $buscaSemSeguro }}".</p>
                </div>
            @else
                <div class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($dados['sem_seguro_vida'] as $cliente)
                        <div class="flex items-center justify-between px-5 py-3">
                            <div>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $cliente['name'] }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $cliente['email'] }}</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="figure text-sm text-slate-700 dark:text-slate-300">{{ Money::compact($cliente['patrimonio']) }}</p>
                                    <p class="text-xs text-slate-400">cliente desde {{ $cliente['since']?->translatedFormat('m/Y') ?? '—' }}</p>
                                </div>
                                <form method="POST" action="{{ route('profile.switch', $cliente['profile_id']) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary px-3 py-1.5 whitespace-nowrap">Abrir perfil</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    {{-- Por cliente ------------------------------------------------------ --}}
    <section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por cliente</h2>

            @if ($dados['clientes']['total'] > 0)
                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="buscaClientes"
                        placeholder="Buscar por nome ou e-mail"
                        class="rounded-lg border-slate-200 bg-white py-1 text-xs dark:border-white/10 dark:bg-slate-800 dark:text-slate-200"
                    >
                    <label class="flex items-center gap-2">
                        Status
                        <select wire:model.live="statusClientes" class="rounded-lg border-slate-200 bg-white py-1 text-xs dark:border-white/10 dark:bg-slate-800 dark:text-slate-200">
                            <option value="todos">Todos</option>
                            @foreach (ConsultantClientStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        Ordenar por
                        <select wire:model.live="ordenarClientes" class="rounded-lg border-slate-200 bg-white py-1 text-xs dark:border-white/10 dark:bg-slate-800 dark:text-slate-200">
                            @foreach ($ordensClientes as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            @endif
        </div>

        @if ($dados['clientes']['total'] === 0)
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum cliente vinculado ainda.</p>
                <p class="mt-1 text-xs text-slate-400">
                    <button type="button" wire:click="toggleInviteForm" class="underline">Convide o primeiro</button>, no botão no topo da página.
                </p>
            </div>
        @elseif (empty($dados['por_cliente']))
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum cliente bate com esse filtro.</p>
                <button type="button" wire:click="limparFiltrosClientes" class="mt-2 text-sm text-brand-800 underline dark:text-brand-300">
                    Limpar filtros
                </button>
            </div>
        @else
            <div class="card mt-3 overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-[11px] tracking-wide text-slate-400 uppercase dark:border-white/10">
                            <th class="px-5 py-3 font-semibold">Cliente</th>
                            <th class="px-3 py-3 font-semibold">Seguros</th>
                            <th class="px-3 py-3 text-right font-semibold">Patrimônio</th>
                            <th class="px-3 py-3 text-right font-semibold">Seguro/mês</th>
                            <th class="px-3 py-3 font-semibold">Desde</th>
                            <th class="px-3 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($dados['por_cliente'] as $linha)
                            <tr @class(['opacity-50' => $linha['status'] !== ConsultantClientStatus::Active])>
                                <td class="max-w-56 px-5 py-3">
                                    <p class="truncate font-medium text-slate-800 dark:text-slate-200">{{ $linha['name'] }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $linha['email'] }}</p>
                                    @if ($linha['tipo_perfil'] !== null)
                                        <p class="truncate text-xs text-slate-400 dark:text-slate-500">
                                            @if ($linha['tipo_perfil'] === ProfileType::Single)
                                                Individual
                                            @elseif ($linha['acessos'] === 2)
                                                {{ $linha['tipo_perfil']->label() }} ·
                                                <span @class([
                                                    'font-medium',
                                                    'text-accent-600 dark:text-accent-400' => $linha['vida_financeira'] === 'unica',
                                                    'text-amber-700 dark:text-amber-400' => $linha['vida_financeira'] === 'separada',
                                                ])>
                                                    Vida financeira {{ $linha['vida_financeira'] === 'unica' ? 'única' : 'separada' }}
                                                </span>
                                            @else
                                                {{ $linha['tipo_perfil']->label() }} · {{ $linha['parceiro'] }} (convite pendente)
                                            @endif
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @if ($linha['insurance_types'] === [])
                                        <span class="text-slate-400">—</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($linha['insurance_types'] as $tipo)
                                                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                                    {{ $tipo }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-800 dark:text-slate-200">
                                    {{ $linha['patrimonio'] !== null ? Money::compact($linha['patrimonio']) : '—' }}
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                    {{ $linha['premio_mensal'] !== null ? Money::format($linha['premio_mensal']) : '—' }}
                                </td>
                                <td class="px-3 py-3 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $linha['since']?->translatedFormat('m/Y') ?? '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    <span @class([
                                        'badge',
                                        'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $linha['status'] === ConsultantClientStatus::Active,
                                        'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300' => $linha['status'] === ConsultantClientStatus::Pending,
                                        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $linha['status'] === ConsultantClientStatus::Inactive,
                                    ])>
                                        {{ $linha['status']->label() }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($linha['profile_id'])
                                        {{-- Form puro: abrir o perfil não pode depender de JS. --}}
                                        <form method="POST" action="{{ route('profile.switch', $linha['profile_id']) }}">
                                            @csrf
                                            <button type="submit" class="btn-secondary px-3 py-1.5 whitespace-nowrap">Abrir perfil</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

</div>
