@use('App\Support\Money')

<div class="space-y-8">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Datas importantes</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Aniversário de cliente, renovação e vencimento de apólice
                @if ($isConsultant)
                    , vencimento de investimento
                @endif
                .
            </p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Período</label>
            <select wire:model.live="periodo" class="select mt-1.5">
                @foreach ($periodos as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
                @if ($aba === 'aniversariantes')
                    @foreach ($meses as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                @endif
            </select>
        </div>
    </div>

    {{-- Abas ------------------------------------------------------------ --}}
    <div class="flex flex-wrap gap-1 border-b border-slate-100 dark:border-white/10">
        <button type="button" wire:click="setAba('aniversariantes')" @class([
            'border-b-2 px-3 py-2 text-sm font-medium',
            'border-brand-700 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $aba === 'aniversariantes',
            'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $aba !== 'aniversariantes',
        ])>Aniversariantes</button>
        <button type="button" wire:click="setAba('aniversario_apolice')" @class([
            'border-b-2 px-3 py-2 text-sm font-medium',
            'border-brand-700 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $aba === 'aniversario_apolice',
            'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $aba !== 'aniversario_apolice',
        ])>Aniversário de apólice</button>
        <button type="button" wire:click="setAba('vencimento_apolice')" @class([
            'border-b-2 px-3 py-2 text-sm font-medium',
            'border-brand-700 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $aba === 'vencimento_apolice',
            'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $aba !== 'vencimento_apolice',
        ])>Vencimento de apólice</button>
        @if ($isConsultant)
            <button type="button" wire:click="setAba('vencimento_investimento')" @class([
                'border-b-2 px-3 py-2 text-sm font-medium',
                'border-brand-700 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $aba === 'vencimento_investimento',
                'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $aba !== 'vencimento_investimento',
            ])>Vencimento de investimento</button>
        @endif
    </div>

    {{-- Aniversariantes -------------------------------------------------- --}}
    @if ($aba === 'aniversariantes')
        <section class="card overflow-x-auto p-0">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="text-xs text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Dia</th>
                        <th class="px-5 py-2.5 font-medium">Nome</th>
                        <th class="px-5 py-2.5 font-medium">Fazendo</th>
                        <th class="px-5 py-2.5 font-medium">Prêmio</th>
                        <th class="px-5 py-2.5 font-medium">Contato</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @forelse ($birthdays as $linha)
                        <tr>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                {{ $linha['occurrence_date']->format('d \d\e M') }}
                                <span class="text-xs text-slate-400">({{ $linha['occurrence_date']->isToday() ? 'hoje' : $linha['occurrence_date']->diffForHumans(['options' => 0]) }})</span>
                            </td>
                            <td class="px-5 py-3 text-slate-800 dark:text-slate-200">
                                {{ $linha['member']->name }}
                                <p class="text-xs text-slate-400">{{ $linha['client_name'] }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ $linha['turning_age'] }} anos</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">
                                @forelse ($linha['policies'] as $apolice)
                                    <p class="whitespace-nowrap">{{ $apolice->insurer_name }} · {{ Money::format($apolice->monthly_premium) }}</p>
                                @empty
                                    <span class="text-slate-400">—</span>
                                @endforelse
                            </td>
                            <td class="px-5 py-3">
                                @if ($linha['member']->user)
                                    <div class="flex items-center gap-2 text-slate-400">
                                        <a href="mailto:{{ $linha['member']->user->email }}" class="hover:text-brand-700 dark:hover:text-brand-300" title="{{ $linha['member']->user->email }}">
                                            <x-nav-icon name="mail" class="h-4 w-4" />
                                        </a>
                                        @if ($linha['member']->user->phone)
                                            <a href="tel:{{ $linha['member']->user->phone }}" class="hover:text-brand-700 dark:hover:text-brand-300" title="{{ $linha['member']->user->phone }}">
                                                <x-nav-icon name="phone" class="h-4 w-4" />
                                            </a>
                                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $linha['member']->user->phone) }}" target="_blank" rel="noopener" class="hover:text-brand-700 dark:hover:text-brand-300" title="WhatsApp">
                                                <x-nav-icon name="whatsapp" class="h-4 w-4" />
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Sem login</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">Nenhum aniversário no período.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400 dark:border-white/10">Exibindo {{ $birthdays->count() }} {{ $birthdays->count() === 1 ? 'item' : 'itens' }}.</p>
        </section>

        {{-- Sem aniversário cadastrado --------------------------------- --}}
        @if ($membersWithoutBirthdate->isNotEmpty())
            <section class="card space-y-3 p-5">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Sem aniversário cadastrado</p>
                <ul class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($membersWithoutBirthdate as $membro)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                            <div class="text-sm">
                                <span class="text-slate-700 dark:text-slate-300">{{ $membro->name }}</span>
                                <span class="text-xs text-slate-400">{{ $membro->profile->owner->name ?? '' }}</span>
                            </div>

                            @if ($addingBirthdateMemberId === $membro->id)
                                <div class="flex items-center gap-2">
                                    <input type="date" wire:model="birthdateInput" class="input py-1 text-xs">
                                    <button type="button" wire:click="saveBirthdate" class="btn-primary px-2 py-1 text-xs">Salvar</button>
                                    <button type="button" wire:click="cancelAddingBirthdate" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                                </div>
                            @else
                                <button type="button" wire:click="startAddingBirthdate('{{ $membro->id }}')" class="btn-ghost px-2 py-1 text-xs">+ Adicionar aniversário</button>
                            @endif
                        </li>
                        @error('birthdateInput')
                            @if ($addingBirthdateMemberId === $membro->id)
                                <p class="pb-2 text-xs text-red-700 dark:text-red-400">{{ $message }}</p>
                            @endif
                        @enderror
                    @endforeach
                </ul>
            </section>
        @endif
    @endif

    {{-- Aniversário de apólice -------------------------------------------- --}}
    @if ($aba === 'aniversario_apolice')
        <section class="card overflow-x-auto p-0">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="text-xs text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Data de vigência</th>
                        <th class="px-5 py-2.5 font-medium">Nome</th>
                        <th class="px-5 py-2.5 font-medium">Aniversário</th>
                        <th class="px-5 py-2.5 font-medium">Prêmio atual</th>
                        <th class="px-5 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @forelse ($policyAnniversaries as $linha)
                        @php $apolice = $linha['policy']; @endphp
                        <tr x-data="{ historico: false }">
                            <td class="px-5 py-3 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                {{ $linha['occurrence_date']->format('d \d\e M') }}
                                <span class="text-xs text-slate-400">({{ $linha['occurrence_date']->isToday() ? 'hoje' : $linha['occurrence_date']->diffForHumans(['options' => 0]) }})</span>
                            </td>
                            <td class="px-5 py-3 text-slate-800 dark:text-slate-200">
                                {{ $apolice->insurer_name }}@if ($apolice->personLabel()) <span class="text-xs text-slate-400">({{ $apolice->personLabel() }})</span>@endif
                                <p class="text-xs text-slate-400">{{ $linha['client_name'] }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ $linha['years_completing'] }} {{ $linha['years_completing'] === 1 ? 'ano' : 'anos' }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ Money::format($apolice->monthly_premium) }}/mês</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" @click="historico = ! historico" class="btn-ghost px-2 py-1 text-xs" x-text="historico ? 'Ocultar histórico' : 'Ver histórico'"></button>
                                <button type="button" wire:click="startRenewal('{{ $apolice->id }}')" class="btn-secondary px-2 py-1 text-xs">Registrar renovação</button>
                            </td>
                        </tr>
                        <tr x-show="historico" x-cloak>
                            <td colspan="5" class="bg-slate-50/60 px-5 py-3 dark:bg-white/5">
                                @if ($apolice->renewals->isEmpty())
                                    <p class="text-xs text-slate-400">Nenhuma renovação registrada ainda.</p>
                                @else
                                    <ul class="space-y-1.5">
                                        @foreach ($apolice->renewals as $renovacao)
                                            <li class="text-xs text-slate-600 dark:text-slate-400">
                                                {{ $renovacao->renewed_at->format('d/m/Y') }} —
                                                prêmio {{ Money::format($renovacao->previous_monthly_premium) }} → {{ Money::format($renovacao->new_monthly_premium) }}
                                                @if ($renovacao->new_coverage_amount !== null)
                                                    · cobertura {{ Money::format($renovacao->previous_coverage_amount ?? '0') }} → {{ Money::format($renovacao->new_coverage_amount) }}
                                                @endif
                                                @if ($renovacao->notes)
                                                    <br><span class="italic">{{ $renovacao->notes }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">Nenhuma renovação no período.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400 dark:border-white/10">Exibindo {{ $policyAnniversaries->count() }} {{ $policyAnniversaries->count() === 1 ? 'item' : 'itens' }}.</p>
        </section>

        {{-- Registrar renovação ------------------------------------------ --}}
        <x-modal wire-model="showRenewalForm" max-width="md">
            <form wire:submit="saveRenewal" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar renovação — {{ $renewalInsurerName }}</h2>
                    <button type="button" wire:click="cancelRenewal" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prêmio novo</label>
                        <input type="number" step="0.01" wire:model="renewalPremium" class="input mt-1.5" placeholder="0,00">
                        @error('renewalPremium') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cobertura nova (opcional)</label>
                        <input type="number" step="0.01" wire:model="renewalCoverage" class="input mt-1.5" placeholder="0,00">
                        @error('renewalCoverage') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-400">Deixe vazio se a cobertura não mudou.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                        <textarea wire:model="renewalNotes" rows="2" class="input mt-1.5"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Salvar renovação</button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Vencimento de apólice ---------------------------------------------- --}}
    @if ($aba === 'vencimento_apolice')
        <section class="card overflow-x-auto p-0">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Vencimento</th>
                        <th class="px-5 py-2.5 font-medium">Nome</th>
                        <th class="px-5 py-2.5 font-medium">Prêmio atual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @forelse ($policyExpiries as $linha)
                        @php $apolice = $linha['policy']; @endphp
                        <tr>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                {{ $linha['occurrence_date']->format('d/m/Y') }}
                            </td>
                            <td class="px-5 py-3 text-slate-800 dark:text-slate-200">
                                {{ $apolice->insurer_name }}@if ($apolice->personLabel()) <span class="text-xs text-slate-400">({{ $apolice->personLabel() }})</span>@endif
                                <p class="text-xs text-slate-400">{{ $linha['client_name'] }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ Money::format($apolice->monthly_premium) }}/mês</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">Nenhum vencimento no período.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400 dark:border-white/10">Exibindo {{ $policyExpiries->count() }} {{ $policyExpiries->count() === 1 ? 'item' : 'itens' }}.</p>
        </section>
    @endif

    {{-- Vencimento de investimento ---------------------------------------- --}}
    @if ($aba === 'vencimento_investimento' && $isConsultant)
        <section class="card overflow-x-auto p-0">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5 font-medium">Vencimento</th>
                        <th class="px-5 py-2.5 font-medium">Nome</th>
                        <th class="px-5 py-2.5 font-medium">Ativo</th>
                        <th class="px-5 py-2.5 font-medium">Valor atual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @forelse ($investmentMaturities as $linha)
                        @php $investimento = $linha['investment']; @endphp
                        <tr>
                            <td class="px-5 py-3 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                {{ $linha['occurrence_date']->format('d/m/Y') }}
                            </td>
                            <td class="px-5 py-3 text-slate-800 dark:text-slate-200">{{ $linha['client_name'] }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ $investimento->displayName() }}</td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-400">{{ Money::format($investimento->current_amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">Nenhum vencimento futuro cadastrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400 dark:border-white/10">Exibindo {{ $investmentMaturities->count() }} {{ $investmentMaturities->count() === 1 ? 'item' : 'itens' }}.</p>
        </section>
    @endif

</div>
