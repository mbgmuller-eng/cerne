<x-mail::message>
# Olá, {{ $name }}

O seu teste grátis da assinatura **{{ $bundleLabel }}** termina em **{{ $trialEndsFormatted }}**.

Para continuar com o débito automático por Pix, autorize no seu banco: você paga o primeiro mês ({{ $priceFormatted }}) por um QR Code e, a partir daí, as cobranças mensais são debitadas sozinhas. Você pode cancelar a autorização a qualquer momento.

<x-mail::button :url="$url">
Ativar débito automático
</x-mail::button>

Se não fizer isso até o fim do teste, o acesso é encerrado.

Abraço,<br>
Equipe Cerne
</x-mail::message>
