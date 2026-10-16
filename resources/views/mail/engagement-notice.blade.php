<x-mail::message>
# Olá, {{ $recipientName }}

**{{ $heading }}**

{{ $message }}

<x-mail::button :url="$url">
Abrir o acompanhamento
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
