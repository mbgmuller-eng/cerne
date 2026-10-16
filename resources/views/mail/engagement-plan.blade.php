<x-mail::message>
# Seu plano de acompanhamento

@if ($ended)
O vínculo com **{{ $consultantName }}** no Cerne foi encerrado. Antes disso, ele(a) quis deixar com você o plano que vocês construíram juntos: tarefas, reuniões, demandas e notas compartilhadas.
@else
**{{ $consultantName }}** enviou o resumo do plano de acompanhamento de vocês: o passo a passo, as tarefas, as reuniões, as demandas e as notas compartilhadas.
@endif

O arquivo está em anexo, em PDF.

Abraço,<br>
Equipe Cerne
</x-mail::message>
