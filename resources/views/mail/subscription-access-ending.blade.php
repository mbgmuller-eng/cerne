<x-mail::message>
# Olá, {{ $name }}

Sua assinatura **{{ $bundleLabel }}** continua sem pagamento e o seu acesso ao Cerne será encerrado amanhã, **{{ $cutoffFormatted }}**.

Pagando hoje, nada muda: você mantém o acesso e todos os seus dados.

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">
Pagar a assinatura
</x-mail::button>
@else
<x-mail::button :url="$url">
Ver minha assinatura
</x-mail::button>
@endif

Abraço,<br>
Equipe Cerne
</x-mail::message>
