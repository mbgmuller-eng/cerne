<x-mail::message>
# Olá, {{ $recipientName }}

O investimento **{{ $displayName }}** vence em **{{ $maturityDateFormatted }}** — valor atual de {{ $amountFormatted }}.

<x-mail::button :url="$url">
Ver datas importantes
</x-mail::button>

Bom momento pra conversar com o cliente sobre o que fazer com o resgate.

Abraço,<br>
Equipe Cerne
</x-mail::message>
