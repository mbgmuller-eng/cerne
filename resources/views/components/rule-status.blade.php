{{-- Diz se a regra, do jeito que está hoje, foi aprovada por um profissional de educação física. Sem aprovação da versão atual, diz que não. --}}
@props(['key'])

@php $aprovacao = app(\App\Services\RuleReviewService::class)->approval($key); @endphp

<p {{ $attributes->merge(['class' => 'text-xs '.($aprovacao ? 'text-emerald-800 dark:text-emerald-300' : 'text-slate-400')]) }} data-regra="{{ $key }}">
    @if ($aprovacao)
        Regra revisada por {{ $aprovacao['name'] }} (CREF {{ $aprovacao['registry'] }}) em {{ $aprovacao['on']->format('d/m/Y') }}.
    @else
        Regra ainda não revisada por um profissional de educação física.
    @endif
</p>
