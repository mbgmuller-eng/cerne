{{-- "Ao salvar": o que vai acontecer quando a pessoa confirmar o cadastro (fatura em que a compra cai, saldo da conta antes e depois, parcelas...).
     As frases vêm de App\Support\Forms\FormConsequence, que usa a mesma conta que o Cerne usa para gravar. Sem frase, o quadro nem aparece. --}}
@props(['lines' => []])

@if (count($lines) > 0)
    <div data-consequence {{ $attributes->merge(['class' => 'rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10']) }}>
        <p class="font-semibold text-slate-700 dark:text-slate-200">Ao salvar</p>
        <ul class="mt-1 space-y-0.5">
            @foreach ($lines as $linha)
                <li>{{ $linha }}</li>
            @endforeach
        </ul>
    </div>
@endif
