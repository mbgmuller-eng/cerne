{{--
    Preferências de renovação do profissional. Os tipos marcados geram lead de renovação quando a apólice do cliente
    vence; os desmarcados continuam aparecendo em "Datas importantes", sem lead.
--}}
<form wire:submit="save" class="space-y-4">
    <div>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Renovações</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Escolha quais seguros viram contato de renovação no funil quando a apólice de um cliente se aproxima do vencimento. Os desmarcados continuam em "Datas importantes", sem criar contato.</p>
    </div>

    <div class="grid gap-2 @sm:grid-cols-2">
        @foreach ($insuranceTypes as $valor => $rotulo)
            <label class="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm text-slate-700 dark:border-white/10 dark:text-slate-300">
                <input type="checkbox" wire:model="types" value="{{ $valor }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                {{ $rotulo }}
            </label>
        @endforeach
    </div>
    @error('types.*') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

    <div class="max-w-[14rem]">
        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Criar o contato com quantos dias de antecedência</label>
        <input type="number" min="7" max="120" wire:model="days" class="input mt-1.5">
        @error('days') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <p class="text-xs text-slate-400">Apólice que renova sozinha? Marque "Renova automaticamente" no cadastro dela: ela não gera contato nem aviso de vencimento, qualquer que seja o tipo.</p>

    <div class="flex items-center justify-end gap-2">
        <button type="button" wire:click="$dispatch('renewal-settings-saved')" class="btn-ghost px-3 py-1.5 text-xs">Cancelar</button>
        <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar</button>
    </div>
</form>
