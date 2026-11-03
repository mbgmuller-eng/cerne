<x-mail::message>
# Olá, {{ $caregiverName }}

@if ($assisted)
**{{ $inviterName }}**, consultor de **{{ $personName }}**, liberou o seu acesso no Cerne, em nome e com a autorização de quem você vai acompanhar.
@elseif ($selfFollow)
**{{ $inviterName }}** convidou você para acompanhar o que ele ou ela liberar no Cerne.
@else
**{{ $inviterName }}** convidou você para acompanhar **{{ $personName }}** no Cerne.
@endif

Você poderá ver:

@if ($health)
- **Saúde:** a ficha de saúde, a agenda de consultas e exames, os itens de cuidado e os documentos que forem liberados ({{ mb_strtolower($access->label()) }}).
@endif
@if ($finance)
- **Finanças, só para ver:** contas, cartões, lançamentos, contas a vencer, investimentos, dívidas, seguros e metas. O que a pessoa marcou como privado não aparece, e você não altera nada.
@endif

Você não verá nada além disso, e quem você acompanha pode mudar ou encerrar o acesso quando quiser.

<x-mail::button :url="$link">
Aceitar o convite
</x-mail::button>

O link vale por {{ config('cerne.invite.expires_in_days') }} dias. Se você não reconhece este convite, ignore este e-mail.
</x-mail::message>