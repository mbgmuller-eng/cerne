<x-mail::message>
# Pedido de vínculo

@if ($isBroker)
**{{ $consultantName }}** quer ser seu corretor de seguros no Cerne. Você já tem uma
conta por aqui — falta só autorizar.
@else
**{{ $consultantName }}** quer se tornar seu consultor no Cerne. Você já tem uma
conta por aqui — falta só autorizar.
@endif

<x-mail::button :url="$link">
Ver pedido
</x-mail::button>

@if ($isBroker)
Autorizando, você escolhe quais apólices de seguro já cadastradas liberar pra ele —
nada mais das suas informações financeiras fica visível.
@else
Autorizando, ele passa a enxergar suas informações financeiras, inclusive o que
for privado entre você e seu cônjuge, se houver perfil de casal.
@endif

Se você não esperava este pedido, pode ignorar esta mensagem — nada muda sem a
sua confirmação.

Abraço,<br>
Equipe Cerne
</x-mail::message>
