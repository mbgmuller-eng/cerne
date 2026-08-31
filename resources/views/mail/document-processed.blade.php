<x-mail::message>
@if ($sucesso)
# Olá, {{ $recipientName }}

O arquivo **"{{ $filename }}"** foi processado e já está pronto pra revisão.

<x-mail::button :url="$url">
Revisar
</x-mail::button>

Nada foi lançado automaticamente — a leitura por IA só vira lançamento depois que você confirma item por item.
@else
# Olá, {{ $recipientName }}

Não foi possível processar o arquivo **"{{ $filename }}"**.

@if ($errorMessage)
{{ $errorMessage }}
@endif

<x-mail::button :url="$url">
Ver documentos
</x-mail::button>
@endif

Abraço,<br>
Equipe Cerne
</x-mail::message>
