{{-- Modal de anexar documento. O componente que inclui usa HandlesAttachments e passa $documentos (os que podem ser compartilhados). --}}
<x-modal wire-model="showAttach">
    <form wire:submit="attachDocument" class="space-y-4">
        <div class="flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Anexar documento</h2>
            <button type="button" wire:click="$set('showAttach', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
        </div>

        @if ($documentos->isEmpty())
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum documento disponível para anexar.</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Só aparecem aqui os documentos que o consultor também pode ver: apólices cadastradas e documentos da categoria "Outros" marcados como visíveis ao profissional na área de Documentos. Documentos pessoais e de saúde ficam sempre fechados.</p>
        @else
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Documento</label>
                <select wire:model="attachDocumentId" class="select mt-1.5 w-full">
                    <option value="">Escolha um documento</option>
                    @foreach ($documentos as $doc)
                        <option value="{{ $doc->id }}">{{ $doc->title }}</option>
                    @endforeach
                </select>
                @error('attachDocumentId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <p class="text-xs text-slate-400">O anexo é um atalho para o documento: ele continua na área de Documentos, com as mesmas regras de quem pode ver.</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Anexar</button></div>
        @endif
    </form>
</x-modal>
