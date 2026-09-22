<x-mail::message>
# Olá, {{ $recipientName }}

A apólice da **{{ $insurerName }}**@if($personLabel) ({{ $personLabel }})@endif completa {{ $yearsCompleting }}
{{ $yearsCompleting === 1 ? 'ano' : 'anos' }} de vigência em **{{ $occurrenceDateFormatted }}** — prêmio atual de {{ $premiumFormatted }}/mês.

<x-mail::button :url="$url">
Ver datas importantes
</x-mail::button>

Bom momento pra confirmar com a seguradora se o prêmio ou a cobertura mudam na renovação.

Abraço,<br>
Equipe Cerne
</x-mail::message>
