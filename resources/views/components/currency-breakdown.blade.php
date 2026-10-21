@props(['rows', 'tone' => 'dark'])

{{--
    O patrimônio investido dividido por moeda: cada moeda com a sua quantidade (BTC e ETH com 8 casas, USDT com 2, como dólar) e o
    equivalente em reais pela cotação do dia. `tone` = dark para o cartão azul do painel, light para os cartões brancos.
--}}
@use('App\Support\Money')

@php
    $rotulo = ['BRL' => 'Reais', 'USDT' => 'USDT (dólar digital)', 'BTC' => 'Bitcoin', 'ETH' => 'Ethereum'];
    $claro = $tone === 'light';
@endphp

<div {{ $attributes->merge(['class' => 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4']) }}>
    @foreach ($rows as $linha)
        <div>
            <p class="{{ $claro ? 'text-slate-500 dark:text-slate-400' : 'text-brand-200' }} text-xs">{{ $rotulo[$linha['currency']] ?? $linha['currency'] }}</p>
            <p class="figure mt-0.5 {{ $claro ? 'text-slate-900 dark:text-white' : '' }} text-xl font-medium">
                {{ $linha['currency'] === 'BRL' ? Money::format($linha['brl']) : Money::native($linha['native'], $linha['currency']) }}
            </p>
            @if ($linha['currency'] !== 'BRL')
                <p class="{{ $claro ? 'text-slate-400' : 'text-brand-200' }} text-xs">≈ {{ Money::format($linha['brl']) }}</p>
            @endif
        </div>
    @endforeach
</div>
