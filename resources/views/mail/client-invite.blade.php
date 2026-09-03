<x-mail::message>
# Olá, {{ $clientName }}

@if ($consultantName)
**{{ $consultantName }}** convidou você para acompanhar suas finanças no Cerne.
@else
Você foi convidado para organizar suas finanças no Cerne.
@endif

Use o botão abaixo para definir sua senha e criar seu acesso.

<x-mail::button :url="$link">
Criar meu acesso
</x-mail::button>

O convite vale até {{ $expiresAt->translatedFormat('d \d\e F \d\e Y') }}. Depois disso será preciso pedir um novo.

Se você não esperava este convite, pode ignorar esta mensagem.

Abraço,<br>
Equipe Cerne
</x-mail::message>
