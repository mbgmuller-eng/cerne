<x-mail::message>
# Olá, {{ $name }}

Não conseguimos cobrar a sua assinatura **{{ $bundleLabel }}** no cartão. Isso costuma acontecer por limite insuficiente, cartão vencido ou bloqueio do banco.

Você pode pagar agora, com o mesmo cartão ou com outro, pelo botão abaixo. Seu acesso segue ativo até **{{ $accessUntilFormatted }}**.

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">
Pagar a assinatura
</x-mail::button>
@else
<x-mail::button :url="$url">
Ver minha assinatura
</x-mail::button>
@endif

Se o pagamento não for feito até essa data, o acesso é encerrado.

Abraço,<br>
Equipe Cerne
</x-mail::message>
