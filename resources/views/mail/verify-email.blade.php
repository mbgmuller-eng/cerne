<x-mail::message>
# Olá, {{ $name }}

Confirme seu e-mail para ativar sua conta no Cerne e escolher seu pacote.

<x-mail::button :url="$url">
Confirmar e-mail
</x-mail::button>

Se você não criou essa conta, pode ignorar esta mensagem.

Abraço,<br>
Equipe Cerne
</x-mail::message>
