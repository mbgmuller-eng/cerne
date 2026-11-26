<x-mail::message>
# Olá, {{ $recipientName }}

Para **{{ $memberName }}**: a rotina **{{ $routineName }}** {{ $whenLabel }}, em **{{ $dueFormatted }}**.

Se já marcou, ligue o compromisso à rotina no Cerne: o aviso para de aparecer.

<x-mail::button :url="$url">
Ver check-ups e rotinas
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
