@props(['subscription'])

@php
    $temAcessoAtivo = $subscription?->isCurrent() ?? false;
@endphp

@if ($subscription)
    <dl class="grid gap-3 sm:grid-cols-2">
        <div>
            <dt class="text-xs text-slate-500 dark:text-slate-400">Pacote</dt>
            <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $subscription->bundle->label() }}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt>
            <dd @class([
                'mt-0.5 text-sm font-medium',
                'text-emerald-700 dark:text-emerald-400' => $temAcessoAtivo,
                'text-amber-700 dark:text-amber-400' => ! $temAcessoAtivo,
            ])>{{ $subscription->status->label() }}</dd>
        </div>
        @if ($subscription->billing_type)
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Forma de pagamento</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $subscription->billing_type->label() }}</dd>
            </div>
        @endif
        @if ($subscription->current_period_ends_at && $subscription->status !== \App\Enums\SubscriptionStatus::Cancelled)
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Próxima cobrança</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $subscription->current_period_ends_at->format('d/m/Y') }}</dd>
            </div>
        @elseif ($subscription->status === \App\Enums\SubscriptionStatus::Cancelled && $subscription->cancelled_at)
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Cancelada em</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $subscription->cancelled_at->format('d/m/Y') }}</dd>
            </div>
        @endif
    </dl>

    {{ $actions ?? '' }}
@else
    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $slot->isEmpty() ? 'Nenhuma assinatura própria.' : $slot }}</p>
@endif
