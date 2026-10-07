<x-mail::message>
# Olá, {{ $recipientName }}

{{ $kindLabel }} de **{{ $memberName }}** em breve: **{{ $title }}**, dia **{{ $dateFormatted }}** às **{{ $timeFormatted }}**.
@if ($location || $address || $phone || $bookedWith)

@if ($location)
Local: {{ $location }}<br>
@endif
@if ($address)
Endereço: {{ $address }}<br>
@endif
@if ($phone)
Telefone: {{ $phone }}<br>
@endif
@if ($bookedWith)
Agendado com: {{ $bookedWith }}
@endif
@endif

<x-mail::button :url="$url">
Ver agenda de saúde
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
