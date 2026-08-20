@use('App\Support\Money')
@use('App\Enums\InsuranceType')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Seguros</h1>
        <p class="mt-1 text-sm text-stone-500">Coberturas contratadas e custo mensal.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <p class="eyebrow">Cobertura total</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800">{{ Money::format($totalCoverage) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Custo mensal</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700">{{ Money::format($totalMonthly) }}</p>
            <p class="mt-1 text-xs text-stone-400">Apólices anuais já divididas por 12.</p>
        </div>
    </div>

    @if ($expiring->isNotEmpty())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm font-medium text-amber-900">Apólices vencendo nos próximos 60 dias</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($expiring as $apolice)
                    <li class="text-sm text-amber-900">
                        {{ $apolice->insurer_name }} · {{ $apolice->insurance_type->label() }}
                        — vence {{ $apolice->expiry_date->format('d/m/Y') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Resumo por categoria de risco --------------------------------- --}}
    @if ($byType->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-stone-900">Por categoria de risco</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($byType as $tipo => $resumo)
                    <div class="card p-4">
                        <p class="text-sm font-medium text-stone-800">{{ InsuranceType::from($tipo)->label() }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-stone-900">
                            {{ Money::format($resumo['cobertura']) }}
                        </p>
                        <p class="mt-0.5 text-xs text-stone-500">
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
        <h2 class="text-sm font-semibold text-stone-900">Apólices</h2>

        @if ($policies->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
                <p class="text-sm text-stone-600">Nenhuma apólice cadastrada.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($policies as $apolice)
                    <li class="px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-stone-800">
                                    {{ $apolice->insurance_type->label() }} · {{ $apolice->insurer_name }}
                                </p>
                                <p class="truncate text-xs text-stone-500">
                                    @if ($apolice->policy_number) apólice {{ $apolice->policy_number }} · @endif
                                    {{ $apolice->payment_frequency->label() }}
                                    @if ($apolice->member) · {{ $apolice->member->name }} @else · familiar @endif
                                    @if ($apolice->expiry_date) · vence {{ $apolice->expiry_date->format('d/m/Y') }} @endif
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm tabular-nums text-stone-800">{{ Money::format($apolice->coverage_amount) }}</p>
                                <p class="text-xs tabular-nums text-stone-500">
                                    {{ Money::format($apolice->normalizedMonthlyCost()) }}/mês
                                </p>
                            </div>
                        </div>

                        @if ($apolice->beneficiaryList() !== [])
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-stone-400">Beneficiários:</span>
                                @foreach ($apolice->beneficiaryList() as $b)
                                    <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-700">
                                        {{ $b['name'] }} · {{ $b['percentage'] }}%
                                    </span>
                                @endforeach

                                @unless ($apolice->beneficiariesAreValid())
                                    {{-- Percentuais que não somam 100 travam o pagamento
                                         do sinistro — melhor avisar antes. --}}
                                    <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs text-red-900">
                                        não somam 100%
                                    </span>
                                @endunless
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
