<div data-write data-envio-laudo>
    @if (! $open)
        <button type="button" wire:click="$set('open', true)" class="btn-secondary px-3 py-2 text-sm">Enviar laudo (PDF)</button>
    @else
        <form wire:submit="send" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
            @if (! $disponivel)
                <p class="text-sm text-slate-600 dark:text-slate-300">A leitura automática não está disponível agora. Digite o resultado à mão.</p>
            @else
                @if ($pessoas->count() > 1)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é o laudo</label>
                        <select wire:model="memberId" class="select mt-1.5 w-full">
                            @foreach ($pessoas as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">PDF do laudo</label>
                    <input type="file" wire:model="arquivo" accept=".pdf" class="mt-1.5 w-full text-sm">
                    <p class="mt-1 text-xs text-slate-400">Leituras que ainda cabem hoje neste perfil: {{ $restantes }}.</p>
                    <p wire:loading wire:target="arquivo" class="mt-1 text-xs text-slate-400">Enviando o arquivo...</p>
                </div>
                <x-form-consequence :lines="$consequencia" />
            @endif
            <x-form-errors />
            <div class="flex flex-wrap items-center gap-3">
                @if ($disponivel)
                    <button type="submit" class="btn-primary px-3 py-1.5 text-sm" wire:loading.attr="disabled" wire:target="arquivo, send">Enviar para leitura</button>
                @endif
                <button type="button" wire:click="$set('open', false)" class="text-xs text-slate-400">Cancelar</button>
            </div>
        </form>
    @endif
</div>
