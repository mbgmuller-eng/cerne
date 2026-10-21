<x-mail::message>
# Erros da plataforma em {{ $d['day']->format('d/m/Y') }}

@if ($d['occurrences'] === 0)
Nenhum erro foi registrado neste dia. O acompanhamento está ligado e nada saiu do lugar.
@else
**{{ $d['occurrences'] }}** {{ $d['occurrences'] === 1 ? 'ocorrência' : 'ocorrências' }} em **{{ $d['types'] }}** {{ $d['types'] === 1 ? 'tipo de erro' : 'tipos de erro' }}.
@if ($d['users'] > 0)
{{ $d['users'] }} {{ $d['users'] === 1 ? 'usuário foi afetado' : 'usuários foram afetados' }}.
@endif

<x-mail::table>
| | |
|:--|--:|
| Novos neste dia | {{ $d['new'] }} |
| Voltaram depois de resolvidos | {{ $d['reopened'] }} |
| Abertos ao todo (ainda sem resolver) | {{ $d['open_total'] }} |
</x-mail::table>

## Do mais frequente para o menos

@foreach ($d['rows'] as $i => $r)
**{{ $i + 1 }}. {{ $r['class'] }}** — {{ $r['day_count'] }}x{{ $r['users'] > 0 ? ' · '.$r['users'].' '.($r['users'] === 1 ? 'usuário' : 'usuários') : '' }}@if ($r['is_new']) · **novo**@endif @if ($r['reopened']) · **voltou depois de resolvido**@endif @if ($r['status'] === 'ignored') · ignorado @endif

{{ $r['message'] }}

`{{ $r['where'] }}` · {{ $r['origin'] }} · {{ $r['total'] }} no total

@endforeach
@if ($d['more'] > 0)
…e mais {{ $d['more'] }} {{ $d['more'] === 1 ? 'tipo' : 'tipos' }} na tela de erros.
@endif
@endif

<x-mail::button :url="$url">
Abrir os erros
</x-mail::button>

Para sentarmos juntos: a tela mostra o passo a passo de cada erro e deixa marcar como resolvido ou ignorado.

Abraço,<br>
Cerne
</x-mail::message>
