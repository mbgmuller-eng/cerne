<x-mail::message>
# Olá, {{ $recipientName }}

Para **{{ $memberName }}**: **{{ $itemName }}**@if ($deviceName) ({{ $deviceName }})@endif {{ $whenLabel }}, dia **{{ $dueFormatted }}**.

Quando fizer, toque em "Feito hoje" no Cerne: a próxima data é recalculada a partir dele.

<x-mail::button :url="$url">
Ver cuidados e itens
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
