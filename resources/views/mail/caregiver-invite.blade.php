<x-mail::message>
# Olá, {{ $caregiverName }}

**{{ $inviterName }}** convidou você para acompanhar **{{ $personName }}** no Cerne.

Você poderá ver a ficha de saúde, a agenda de consultas e exames, os itens de cuidado e os documentos que forem liberados.
Seu acesso será: **{{ mb_strtolower($access->label()) }}**. Você não verá nada além disso, e quem convidou pode mudar ou encerrar o acesso quando quiser.

<x-mail::button :url="$link">
Aceitar o convite
</x-mail::button>

O link vale por {{ config('cerne.invite.expires_in_days') }} dias. Se você não reconhece este convite, ignore este e-mail.
</x-mail::message>
