<x-mail::message>
# Olá, {{ $recipientName }}

**{{ $memberName }}** completa {{ $turningAge }} anos em **{{ $occurrenceDateFormatted }}**.

<x-mail::button :url="$url">
Ver datas importantes
</x-mail::button>

Boa oportunidade pra entrar em contato.

Abraço,<br>
Equipe Cerne
</x-mail::message>
