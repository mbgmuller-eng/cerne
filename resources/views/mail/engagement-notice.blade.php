<x-mail::message>
# Olá, {{ $recipientName }}

**{{ $heading }}**

{{ $message }}

@foreach ($details ?? [] as $linha)
@if (is_array($linha['value']))
**{{ $linha['label'] }}:**

@foreach ($linha['value'] as $item)
- {{ $item }}
@endforeach

@else
**{{ $linha['label'] }}:** {{ $linha['value'] }}

@endif
@endforeach
<x-mail::button :url="$url">
Abrir o acompanhamento
</x-mail::button>

@if (! empty($calendarUrl))
[Adicionar à agenda]({{ $calendarUrl }})

@endif
Abraço,<br>
Equipe Cerne
</x-mail::message>