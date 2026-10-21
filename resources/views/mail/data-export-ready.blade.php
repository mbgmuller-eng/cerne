<x-mail::message>
# A cópia dos seus dados está pronta

Você pediu uma cópia dos dados que o Cerne guarda sobre você. O arquivo já pode ser baixado.

<x-mail::button :url="$url">
Baixar meus dados
</x-mail::button>

O link vale até **{{ $expiraEm->format('d/m/Y \à\s H:i') }}** e só abre com a sua conta conectada. Depois dessa data o arquivo é apagado e, se precisar, você pode pedir outro em Minha conta.

O arquivo contém dados pessoais e financeiros. Guarde-o em um lugar seguro e não o compartilhe.

Se você não fez esse pedido, entre em Minha conta e troque a sua senha.
</x-mail::message>
