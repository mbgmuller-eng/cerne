<x-mail::message>
# Olá, {{ $name }}

O seu teste grátis da assinatura **{{ $bundleLabel }}** termina em **3 dias**. O último dia de acesso é **{{ $lastDayFormatted }}**.

Para continuar usando o Cerne sem interrupção, escolha a forma de pagamento (Pix ou cartão de crédito) e conclua a assinatura. O valor é de {{ $priceFormatted }} por mês.

<x-mail::button :url="$url">
Escolher como pagar
</x-mail::button>

Depois do último dia, o acesso fica bloqueado até o pagamento ser confirmado. Se você não quiser continuar, não precisa fazer nada: nenhuma cobrança será feita.

Abraço,<br>
Equipe Cerne
</x-mail::message>
