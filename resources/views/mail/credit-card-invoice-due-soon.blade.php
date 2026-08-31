<x-mail::message>
# Olá, {{ $recipientName }}

A fatura do cartão **"{{ $cardDisplayName }}"** vence em **{{ $dueDateFormatted }}** — valor de {{ $amountFormatted }}.

<x-mail::button :url="$url">
Ver fatura
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
