<x-mail::message>
# Olá, {{ $recipientName }}

Seus leads pedem atenção hoje:

@foreach ($lines as $linha)
- {{ $linha }}
@endforeach

<x-mail::button :url="$url">
Ver leads
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
