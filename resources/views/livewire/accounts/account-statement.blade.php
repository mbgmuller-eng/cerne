@use('App\Support\Money')

<div class="space-y-6" data-extrato>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('accounts.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">← Contas &amp; Cartões</a>
            <h1 class="mt-1 truncate font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Extrato</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $conta->displayName() }}</p>
        </div>
        <div class="text-right">
            <p class="text-xs text-slate-500 dark:text-slate-400">Saldo hoje</p>
            <p class="figure text-2xl font-medium {{ (float) $conta->current_balance < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ Money::format($conta->current_balance) }}</p>
        </div>
    </div>

    <x-seletor-mes :rotulo="$referencia->translatedFormat('F \d\e Y')" :hoje="$ehMesAtual ? null : 'currentMonth'" class="w-full lg:w-auto" />

    @if ($aviso)
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20" data-extrato-aviso>{{ $aviso }}</p>
    @endif

    {{-- Resumo do mês: começou, terminou, e para onde vai --}}
    <div class="grid grid-cols-3 gap-3">
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">Início do mês</p><p class="figure mt-1 text-sm font-medium sm:text-lg">{{ Money::format($saldoInicial) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">Fim do mês</p><p class="figure mt-1 text-sm font-medium sm:text-lg">{{ Money::format($saldoFinal) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500 dark:text-slate-400">Previsto</p><p class="figure mt-1 text-sm font-medium sm:text-lg {{ (float) $saldoPrevisto < 0 ? 'text-red-700 dark:text-red-400' : '' }}">{{ Money::format($saldoPrevisto) }}</p></div>
    </div>

    {{-- Previstos: o que ainda vai acontecer neste mês, com o botão de dar baixa --}}
    @if (count($previstos) > 0)
        <div class="card p-5" data-extrato-previstos>
            <p class="eyebrow">Previsto para o mês</p>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($previstos as $p)
                    <li class="flex items-center justify-between gap-3 py-3" data-previsto="{{ $p['tipo'] }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $p['titulo'] }}</p>
                            <p class="text-xs {{ $p['atrasada'] ? 'text-red-700 dark:text-red-400' : 'text-slate-500 dark:text-slate-400' }}">
                                {{ $p['atrasada'] ? 'venceu em' : 'vence em' }} {{ $p['vencimento']->format('d/m') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <p class="figure text-sm {{ $p['sinal'] === 1 ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-800 dark:text-slate-200' }}">{{ $p['sinal'] === 1 ? '+' : '−' }} {{ Money::format($p['valor']) }}</p>
                            @if ($p['variavel'])
                                <a href="{{ route('fixedbills.index') }}" class="btn-secondary px-3 py-1 text-xs">Informar valor</a>
                            @elseif ($p['tipo'] === 'conta')
                                <button type="button" wire:click="efetivarConta('{{ $p['id'] }}')" wire:confirm="Dar baixa nesta conta fixa? O valor sai desta conta e vira uma despesa do mês." class="btn-primary px-3 py-1 text-xs" data-efetivar>Efetivar</button>
                            @else
                                <button type="button" wire:click="efetivarReceita('{{ $p['id'] }}')" wire:confirm="Dar baixa nesta receita? O valor entra nesta conta e vira uma receita do mês." class="btn-primary px-3 py-1 text-xs" data-efetivar>Efetivar</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-slate-400">Efetivar dá baixa pelo mesmo caminho das telas de origem: gera o lançamento e muda o saldo desta conta.</p>
        </div>
    @endif

    {{-- Movimentações, dia a dia --}}
    @forelse ($dias as $d)
        <div data-extrato-dia="{{ $d['dia']->toDateString() }}">
            <p class="px-1 text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ $d['dia']->format('d') }} · <span class="font-normal text-slate-500 dark:text-slate-400">{{ $d['dia']->translatedFormat('l') }}</span>
            </p>
            <ul class="card mt-2 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($d['itens'] as $i)
                    <li class="flex items-center gap-3 px-4 py-3">
                        <x-icone-categoria :nome="$i['categoria']" tamanho="h-9 w-9" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $i['titulo'] }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $i['categoria'] }}</p>
                        </div>
                        <p class="figure shrink-0 text-sm {{ $i['sinal'] === 1 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">{{ $i['sinal'] === 1 ? '+' : '−' }} {{ Money::format($i['valor']) }}</p>
                    </li>
                @endforeach
                <li class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-2 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400" data-saldo-do-dia>
                    <span>Saldo ao fim do dia</span>
                    <span class="figure text-sm font-medium {{ (float) $d['saldo'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">{{ Money::format($d['saldo']) }}</span>
                </li>
            </ul>
        </div>
    @empty
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma movimentação nesta conta em {{ $referencia->translatedFormat('F \d\e Y') }}.</p>
        </div>
    @endforelse

    <p class="text-xs text-slate-400">O saldo de cada dia é calculado a partir do saldo atual da conta, desfazendo o que foi lançado depois. Compras no cartão só mexem no saldo quando a fatura é paga.</p>
</div>
