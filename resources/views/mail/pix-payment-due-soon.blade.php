<x-mail::message>
# Olá, {{ $name }}

Sua assinatura **{{ $bundleLabel }}** vence em **{{ $dueDateFormatted }}**. Como é Pix, não existe débito automático: pague até lá para manter o acesso.

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">
Pagar com Pix
</x-mail::button>
@else
<x-mail::button :url="$url">
Ver minha assinatura
</x-mail::button>
@endif

Se o pagamento não cair até o vencimento, o acesso é encerrado.

Abraço,<br>
Equipe Cerne
</x-mail::message>
