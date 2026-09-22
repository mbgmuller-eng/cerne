<x-mail::message>
# Olá, {{ $name }}

Sua conta de **{{ $roleLabel }}** no Cerne está pronta. Use o botão abaixo para definir sua senha e criar seu acesso.

<x-mail::button :url="$link">
Criar meu acesso
</x-mail::button>

O convite vale até {{ $expiresAt->translatedFormat('d \d\e F \d\e Y') }}. Depois disso será preciso pedir um novo ao administrador.

Se você não esperava este convite, pode ignorar esta mensagem.

Abraço,<br>
Equipe Cerne
</x-mail::message>
