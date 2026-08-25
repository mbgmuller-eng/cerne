@use('App\Support\Money')
@use('App\Enums\ConsultantClientStatus')
@use('App\Enums\ProfileType')

<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Painel da carteira</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Panorama de {{ $dados['clientes']['ativos'] }}
            {{ $dados['clientes']['ativos'] === 1 ? 'cliente com vínculo ativo' : 'clientes com vínculo ativo' }}
        </p>
    </div>

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

    {{-- Por cliente ------------------------------------------------------ --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por cliente</h2>

        @if (empty($dados['por_cliente']))
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum cliente vinculado ainda.</p>
                <p class="mt-1 text-xs text-slate-400">
                    Convide um cliente na tela
                    <a href="{{ route('consultant.clients') }}" class="underline">Meus clientes</a>.
                </p>
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
