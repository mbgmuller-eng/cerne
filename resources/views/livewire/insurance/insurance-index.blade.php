@use('App\Support\Money')
@use('App\Enums\InsuranceType')
@use('App\Enums\PaymentFrequency')

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

    <div class="flex justify-end">
        <button wire:click="togglePolicyForm" class="btn-primary px-3 py-1.5">+ Nova apólice</button>
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    {{-- Nova/editar apólice --------------------------------------------- --}}
    <x-modal wire-model="showPolicyForm" max-width="xl">
        <form wire:submit="savePolicy" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingPolicyId ? 'Editar apólice' : 'Nova apólice' }}</h2>
                <button type="button" wire:click="togglePolicyForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo de seguro</label>
                    <select wire:model="policyInsuranceType" class="select mt-1.5 w-full">
                        @foreach (InsuranceType::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('policyInsuranceType') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Seguradora</label>
                    <input type="text" wire:model="policyInsurerName" class="input mt-1.5" placeholder="Ex.: Icatu Seguros">
                    @error('policyInsurerName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Número da apólice (opcional)</label>
                    <input type="text" wire:model="policyNumber" class="input mt-1.5">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                    <select wire:model="policyMemberId" class="select mt-1.5 w-full">
                        <option value="">Seguro familiar / outra pessoa</option>
                        @foreach ($members as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('policyMemberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Outra pessoa (opcional)</label>
                    <input type="text" wire:model="policyInsuredPersonName" class="input mt-1.5" placeholder="Ex.: Filha, Maria (dependente)">
                    <p class="mt-1 text-xs text-slate-400">Só quando não é o titular nem o cônjuge cadastrado. Com "Membro" preenchido, este campo é ignorado.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Item segurado (opcional)</label>
                    <input type="text" wire:model="policyInsuredItem" class="input mt-1.5" placeholder="Ex.: Honda Civic 2022, iPhone 15">
                    <p class="mt-1 text-xs text-slate-400">Pra carro, eletrônico ou imóvel — ajuda a separar quando há mais de um.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cobertura total (opcional)</label>
                    <input type="number" step="0.01" wire:model="policyCoverageAmount" class="input mt-1.5" placeholder="0,00">
                    @error('policyCoverageAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Forma de pagamento</label>
                    <select wire:model="policyPaymentFrequency" class="select mt-1.5 w-full">
                        @foreach (PaymentFrequency::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Mensalidade</label>
                    <input type="number" step="0.01" wire:model="policyMonthlyPremium" class="input mt-1.5" placeholder="0,00">
                    @error('policyMonthlyPremium') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Pra apólice anual, deixe 0 e preencha o prêmio anual ao lado.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prêmio anual (opcional)</label>
                    <input type="number" step="0.01" wire:model="policyAnnualPremium" class="input mt-1.5" placeholder="0,00">
                    @error('policyAnnualPremium') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Início de vigência</label>
                    <input type="date" wire:model="policyStartDate" class="input mt-1.5">
                    @error('policyStartDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vencimento (opcional)</label>
                    <input type="date" wire:model="policyExpiryDate" class="input mt-1.5">
                    @error('policyExpiryDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" wire:model="policyIsPrivate" id="policyIsPrivate" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <label for="policyIsPrivate" class="text-sm text-slate-600 dark:text-slate-400">Ocultar do cônjuge</label>
                </div>

                <div class="@sm:col-span-2 @lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                    <textarea wire:model="policyNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-white/10">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editingPolicyId ? 'Salvar' : 'Cadastrar' }}</button>
            </div>
        </form>
    </x-modal>

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
        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
            Separadas por tipo de seguro, por pessoa quando há mais de um dono, e por seguradora.
        </p>

        @if ($policies->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma apólice cadastrada.</p>
            </div>
        @else
            <div class="mt-3 space-y-8">
                @foreach ($grouped as $grupoTipo)
                    <div>
                        <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                            {{ $grupoTipo['tipo']->label() }}
                        </h3>

                        <div class="mt-3 space-y-6">
                            @foreach ($grupoTipo['membros'] as $porMembro)
                                <div>
                                    @if ($grupoTipo['separarPorMembro'])
                                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                                            {{ $porMembro['nome'] ?? 'Seguro familiar' }}
                                        </p>
                                    @endif

                                    <div @class(['space-y-4', 'mt-2' => $grupoTipo['separarPorMembro']])>
                                        @foreach ($porMembro['seguradoras'] as $seguradoraNome => $apolicesDaSeguradora)
                                            <div class="card overflow-hidden">
                                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 dark:border-white/10">
                                                    <div class="flex items-center gap-3">
                                                        <div @class([
                                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg font-display text-xs font-semibold text-white',
                                                            $badgeColors[$apolicesDaSeguradora->first()->insurerColorIndex(count($badgeColors))],
                                                        ])>
                                                            {{ $apolicesDaSeguradora->first()->insurerInitials() }}
                                                        </div>
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $seguradoraNome }}</p>
                                                    </div>
                                                </div>

                                                <div class="divide-y divide-slate-100 dark:divide-white/10">
                                                    @foreach ($apolicesDaSeguradora as $apolice)
                                                        <div class="px-5 py-4">
                                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                                <div>
                                                                    @if ($apolice->insured_item)
                                                                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $apolice->insured_item }}</p>
                                                                    @endif
                                                                    @if ($apolice->policy_number)
                                                                        <p class="text-xs text-slate-500 dark:text-slate-400">Apólice {{ $apolice->policy_number }}</p>
                                                                    @endif
                                                                </div>

                                                                <div class="flex items-center gap-2">
                                                                    @if ($apolice->isExpiring(30))
                                                                        <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                                                            Vence em {{ $apolice->daysUntilExpiry() }} {{ $apolice->daysUntilExpiry() === 1 ? 'dia' : 'dias' }}
                                                                        </span>
                                                                    @else
                                                                        <span class="badge bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">Ativa</span>
                                                                    @endif
                                                                    <button type="button" wire:click="editPolicy('{{ $apolice->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                                                    <button type="button" wire:click="confirmDeletePolicy('{{ $apolice->id }}')" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Excluir</button>
                                                                </div>
                                                            </div>

                                                            <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
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
                                                                <div class="mt-4 divide-y divide-slate-100 dark:divide-white/10">
                                                                    @foreach ($apolice->coverageList() as $cobertura)
                                                                        <div class="flex items-center justify-between py-2 text-sm">
                                                                            <span class="text-slate-600 dark:text-slate-300">{{ $cobertura['name'] }}</span>
                                                                            <span class="font-semibold text-slate-900 dark:text-white">{{ Money::format($cobertura['value']) }}</span>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @elseif ($apolice->coverage_amount !== null)
                                                                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-sm dark:border-white/10">
                                                                    <span class="text-slate-600 dark:text-slate-300">Cobertura</span>
                                                                    <span class="font-semibold text-slate-900 dark:text-white">{{ Money::format($apolice->coverage_amount) }}</span>
                                                                </div>
                                                            @endif

                                                            @if ($confirmingDeletePolicyId === $apolice->id)
                                                                <div class="mt-4 flex items-center justify-between rounded-lg bg-red-50 px-3 py-2 text-sm dark:bg-red-500/10">
                                                                    <span class="text-red-800 dark:text-red-300">Excluir esta apólice?</span>
                                                                    <div class="flex gap-2">
                                                                        <button type="button" wire:click="deletePolicy('{{ $apolice->id }}')" class="btn-secondary px-2 py-1 text-xs">Confirmar</button>
                                                                        <button type="button" wire:click="cancelDeletePolicy" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

</div>
