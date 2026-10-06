<x-mail::message>
# Olá, {{ $name }}

A cobrança da sua assinatura **{{ $bundleLabel }}** venceu em **{{ $dueDateFormatted }}** e ainda não identificamos o pagamento.

Seu acesso segue ativo até **{{ $accessUntilFormatted }}**. Pague até lá para não perder o acesso.

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">
Pagar a assinatura
</x-mail::button>
@else
<x-mail::button :url="$url">
Ver minha assinatura
</x-mail::button>
@endif

Se você já pagou, pode ignorar este e-mail: a confirmação do banco às vezes leva um dia.

Abraço,<br>
Equipe Cerne
</x-mail::message>
