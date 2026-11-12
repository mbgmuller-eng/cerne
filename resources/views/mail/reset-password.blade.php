<x-mail::message>
# Olá, {{ $name }}

Recebemos um pedido para redefinir a senha da sua conta no Cerne. O link abaixo vale por {{ $minutos }} minutos e só pode ser usado uma vez.

<x-mail::button :url="$url">
Escolher nova senha
</x-mail::button>

Se você não pediu essa redefinição, pode ignorar esta mensagem: sua senha continua a mesma.

Abraço,<br>
Equipe Cerne
</x-mail::message>
