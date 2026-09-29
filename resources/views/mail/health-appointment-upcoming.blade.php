<x-mail::message>
# Olá, {{ $recipientName }}

{{ $kindLabel }} de **{{ $memberName }}** em breve: **{{ $title }}**, dia **{{ $dateFormatted }}** às **{{ $timeFormatted }}**.
@if ($location)

Local: {{ $location }}
@endif

<x-mail::button :url="$url">
Ver agenda de saúde
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
