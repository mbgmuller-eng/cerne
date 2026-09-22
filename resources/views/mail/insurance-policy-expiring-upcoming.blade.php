<x-mail::message>
# Olá, {{ $recipientName }}

A apólice da **{{ $insurerName }}**@if($personLabel) ({{ $personLabel }})@endif vence em **{{ $expiryDateFormatted }}** —
prêmio atual de {{ $premiumFormatted }}/mês.

<x-mail::button :url="$url">
Ver datas importantes
</x-mail::button>

Diferente de uma renovação automática, essa apólice tem data de fim marcada — vale confirmar a continuidade da cobertura.

Abraço,<br>
Equipe Cerne
</x-mail::message>
