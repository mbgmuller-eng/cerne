<x-mail::message>
# Olá, {{ $clientName }}

@if ($consultantName && $isBroker)
**{{ $consultantName }}** convidou você para cuidar dos seus seguros no Cerne.
@elseif ($consultantName)
**{{ $consultantName }}** convidou você para cuidar das suas finanças no Cerne.
@else
Você foi convidado pro Cerne: o centro da sua vida organizada.
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
