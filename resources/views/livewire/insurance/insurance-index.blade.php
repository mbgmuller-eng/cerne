@use('App\Support\Money')
@use('App\Enums\InsuranceType')

@php
    // Selo da seguradora: cor estável por nome, sem precisar cadastrar
    // uma paleta — ver InsurancePolicy::insurerColorIndex().
    $badgeColors = ['bg-brand-700', 'bg-accent-700', 'bg-brand-500', 'bg-accent-600', 'bg-brand-900'];
@endphp

<div class="space-y-8">

    {{-- Hero --------------------------------------------------------- --}}
    <div class="card relative overflow-hidden bg-brand-800 p-6 text-white sm:p-8">
        <div class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 rounded-full bg-brand-600/50 dark:bg-brand-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-64 w-64 rounded-full bg-brand-950/70 blur-3xl"></div>

        <div class="relative flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="eyebrow text-brand-200">Seguros</p>
                <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight">{{ $profile->profile_name }}</h1>
                <p class="mt-1 text-sm text-brand-200">
                    cliente desde {{ $profile->created_at->translatedFormat('M/Y') }}
                    · {{ $policies->count() }} {{ $policies->count() === 1 ? 'apólice ativa' : 'apólices ativas' }}
                </p>
            </div>

            <div class="flex gap-8">
                <div class="text-right">
                    <p class="figure text-2xl font-medium">{{ Money::compact($totalCoverage) }}</p>
                    <p class="mt-0.5 text-xs text-brand-200">Cobertura total</p>
                </div>
                <div class="text-right">
                    <p class="figure text-2xl font-medium">{{ Money::format($totalMonthly) }}</p>
                    <p class="mt-0.5 text-xs text-brand-200">Custo mensal</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    {{-- Avisos de vencimento -------------------------------------------- --}}
    @foreach ($expiring as $apolice)
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-amber-600 dark:bg-amber-400"></span>
            <div>
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                    Apólice {{ $apolice->insurer_name }} vence em {{ $apolice->daysUntilExpiry() }}
                    {{ $apolice->daysUntilExpiry() === 1 ? 'dia' : 'dias' }}
                </p>
                <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-300/90">
                    {{ $apolice->insurance_type->label() }} · {{ $apolice->insurer_name }}
                    tem vigência até {{ $apolice->expiry_date->format('d/m/Y') }}.
                    Fale com seu consultor para garantir a continuidade da cobertura.
                </p>
            </div>
        </div>
    @endforeach

    {{-- Por categoria de risco --------------------------------------- --}}
    @if ($byType->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por categoria de risco</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($byType as $tipo => $resumo)
                    <div class="card p-4">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ InsuranceType::from($tipo)->label() }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ Money::format($resumo['cobertura']) }}
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ Money::format($resumo['mensal']) }}/mês ·
                            {{ $resumo['quantidade'] }} {{ $resumo['quantidade'] === 1 ? 'apólice' : 'apólices' }}
                        </p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Apólices ------------------------------------------------------ --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Suas apólices</h2>
        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Coberturas contratadas em cada seguradora, sempre atualizadas.</p>

        @if ($policies->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma apólice cadastrada.</p>
            </div>
        @else
            <div class="mt-3 space-y-4">
                @foreach ($policies as $apolice)
                    <div class="card overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-white/10">
                            <div class="flex items-center gap-3">
                                <div @class([
                                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg font-display text-sm font-semibold text-white',
                                    $badgeColors[$apolice->insurerColorIndex(count($badgeColors))],
                                ])>
                                    {{ $apolice->insurerInitials() }}
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $apolice->insurer_name }} · {{ $apolice->insurance_type->label() }}
                                    </p>
                                    @if ($apolice->policy_number)
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Apólice {{ $apolice->policy_number }}</p>
                                    @endif
                                </div>
                            </div>

                            @if ($apolice->isExpiring(30))
                                <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                    Vence em {{ $apolice->daysUntilExpiry() }} {{ $apolice->daysUntilExpiry() === 1 ? 'dia' : 'dias' }}
                                </span>
                            @else
                                <span class="badge bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">Ativa</span>
                            @endif
                        </div>

                        <div class="px-5 py-4">
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div class="border-l-2 border-slate-200 pl-3 dark:border-white/10">
                                    <p class="text-[10.5px] tracking-wide text-slate-400 uppercase">Mensalidade</p>
                                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ Money::format($apolice->normalizedMonthlyCost()) }}
                                    </p>
                                </div>
                                <div class="border-l-2 border-slate-200 pl-3 dark:border-white/10">
                                    <p class="text-[10.5px] tracking-wide text-slate-400 uppercase">Vigência até</p>
                                    <p @class([
                                        'mt-0.5 text-sm font-semibold text-slate-900 dark:text-white',
                                        'text-amber-700 dark:text-amber-400' => $apolice->isExpiring(30),
                                    ])>
                                        {{ $apolice->expiry_date?->format('d/m/Y') ?? 'Sem vencimento' }}
                                    </p>
                                </div>
                                <div class="border-l-2 border-slate-200 pl-3 dark:border-white/10">
                                    <p class="text-[10.5px] tracking-wide text-slate-400 uppercase">Pagamento</p>
                                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $apolice->payment_frequency->label() }}
                                    </p>
                                </div>
                                <div class="border-l-2 border-slate-200 pl-3 dark:border-white/10">
                                    <p class="text-[10.5px] tracking-wide text-slate-400 uppercase">Início</p>
                                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $apolice->start_date->format('d/m/Y') }}
                                    </p>
                                </div>
                            </div>

                            @if ($apolice->coverageList() !== [])
                                <p class="mt-5 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">Coberturas</p>
                                <div class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                                    @foreach ($apolice->coverageList() as $cobertura)
                                        <div class="flex items-center justify-between py-2 text-sm">
                                            <span class="text-slate-600 dark:text-slate-300">{{ $cobertura['name'] }}</span>
                                            <span class="font-semibold text-slate-900 dark:text-white">{{ Money::format($cobertura['value']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif ($apolice->coverage_amount !== null)
                                <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-3 text-sm dark:border-white/10">
                                    <span class="text-slate-600 dark:text-slate-300">Cobertura</span>
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ Money::format($apolice->coverage_amount) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

</div>
