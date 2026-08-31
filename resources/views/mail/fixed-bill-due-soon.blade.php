<x-mail::message>
# Olá, {{ $recipientName }}

A conta **"{{ $billName }}"** vence em **{{ $dueDateFormatted }}** — valor previsto de {{ $amountFormatted }}.

<x-mail::button :url="$url">
Ver contas fixas
</x-mail::button>

Se já pagou, dá pra marcar como paga direto na tela de contas fixas.

Abraço,<br>
Equipe Cerne
</x-mail::message>
