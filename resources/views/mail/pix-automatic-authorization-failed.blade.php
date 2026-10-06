<x-mail::message>
# Olá, {{ $name }}

@if ($recusada)
O seu banco não confirmou a autorização de débito automático da assinatura **{{ $bundleLabel }}**. Por isso, as próximas cobranças não serão debitadas sozinhas.
@else
A autorização de débito automático da assinatura **{{ $bundleLabel }}** foi encerrada, por isso as próximas cobranças não serão debitadas sozinhas.
@endif

Para voltar ao débito automático, gere uma nova autorização na tela de assinatura. Enquanto isso, o seu acesso segue as regras normais de vencimento.

<x-mail::button :url="$url">
Ver minha assinatura
</x-mail::button>

Abraço,<br>
Equipe Cerne
</x-mail::message>
