<x-mail::message>
# Olá, {{ $personName }}

**{{ $inviterName }}** criou um espaço no Cerne para guardar as suas informações de saúde (consultas, remédios, alergias e documentos) e ajudar a cuidar de você.

Com este convite você passa a ter o seu próprio acesso: pode ver tudo o que está registrado, ver **quem tem acesso** ao seu espaço, encerrar o acesso de quem você não quiser e, se preferir, apagar tudo a qualquer momento.

<x-mail::button :url="$link">
Entrar no meu espaço
</x-mail::button>

O link vale por {{ config('cerne.invite.expires_in_days') }} dias. Se você não reconhece este convite, ignore este e-mail.
</x-mail::message>
