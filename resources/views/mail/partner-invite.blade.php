<x-mail::message>
# Olá, {{ $partnerName }}

**{{ $inviterName }}** convidou você pro Cerne, pra cuidarem juntos do que sustenta a vida de vocês.

Use o botão abaixo para definir sua senha e criar seu acesso.

<x-mail::button :url="$link">
Criar meu acesso
</x-mail::button>

O convite vale por alguns dias. Depois disso será preciso pedir um novo.

Se você não esperava este convite, pode ignorar esta mensagem.

Abraço,<br>
Equipe Cerne
</x-mail::message>
