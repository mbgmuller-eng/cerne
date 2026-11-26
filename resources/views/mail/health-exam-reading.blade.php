<x-mail::message>
# Olá, {{ $recipientName }}

@if ($success)
O laudo que você enviou para **{{ $memberName }}** foi lido. Nada foi salvo ainda: confira os resultados e salve no Cerne.
@else
Não foi possível ler o laudo que você enviou para **{{ $memberName }}**. Você pode enviar de novo ou digitar o resultado à mão.
@endif

<x-mail::button :url="$url">
Abrir os exames
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
