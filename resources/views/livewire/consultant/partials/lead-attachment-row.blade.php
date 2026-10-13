@use('App\Enums\ProcessingStatus')

{{--
    Uma linha de arquivo do lead: nome, tamanho, a leitura por IA (quando é PDF) e remover. Recebe $anexo e $aiReading.
    A leitura nunca vira cotação sozinha: "Revisar leitura" abre o formulário preenchido para conferência.
--}}
@php
    $estado = $anexo->extraction_status;
    $lendo = in_array($estado, [ProcessingStatus::Pending, ProcessingStatus::Processing], true);
    $opcoes = count($anexo->extraction_summary['cotacoes'] ?? []);
@endphp

<div class="space-y-1" wire:key="anexo-{{ $anexo->id }}">
    <div class="flex items-center justify-between gap-2 text-xs">
        <a href="{{ route('consultant.leads.attachment', $anexo) }}" target="_blank" rel="noopener" class="truncate font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $anexo->original_filename }}</a>
        <span class="shrink-0 text-slate-400">{{ $anexo->sizeLabel() }}
            <button type="button" wire:click="deleteAttachment('{{ $anexo->id }}')" wire:confirm="Remover este arquivo?" class="ml-1 hover:text-red-700">remover</button>
        </span>
    </div>

    @if ($anexo->isPdf() && $aiReading)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            @if ($lendo)
                <span class="text-slate-500 dark:text-slate-400">Lendo a simulação...</span>
            @elseif ($anexo->awaitingReview())
                <span class="font-medium text-amber-700 dark:text-amber-400">
                    Leitura pronta{{ $opcoes > 1 ? ': '.$opcoes.' opções' : '' }}
                </span>
                <button type="button" wire:click="reviewReading('{{ $anexo->id }}')" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Revisar leitura</button>
                <button type="button" wire:click="discardReading('{{ $anexo->id }}')" wire:confirm="Descartar esta leitura? Nenhuma cotação será criada." class="text-slate-400 hover:text-red-700">descartar</button>
            @elseif ($estado === ProcessingStatus::Failed)
                <span class="text-red-700 dark:text-red-400">{{ $anexo->extraction_error ?? 'A leitura falhou.' }}</span>
                <button type="button" wire:click="readWithAi('{{ $anexo->id }}')" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Tentar de novo</button>
            @elseif ($estado === ProcessingStatus::Completed)
                <span class="text-slate-400">Leitura usada em {{ $anexo->extraction_applied_at?->format('d/m/Y') }}</span>
                <button type="button" wire:click="readWithAi('{{ $anexo->id }}')" wire:confirm="Ler de novo? Conta no limite diário de leituras." class="text-slate-500 hover:underline dark:text-slate-400">ler de novo</button>
            @else
                <button type="button" wire:click="readWithAi('{{ $anexo->id }}')" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Ler com IA</button>
            @endif
        </div>
    @endif
</div>
