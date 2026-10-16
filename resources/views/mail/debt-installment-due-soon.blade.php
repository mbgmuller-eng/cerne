<x-mail::message>
# Olá, {{ $recipientName }}

Uma parcela está para vencer:

**{{ $title }}**<br>
Valor: {{ $amount }}<br>
Vencimento: {{ $dueDate }}

<x-mail::button :url="$url">
Ver a dívida
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
