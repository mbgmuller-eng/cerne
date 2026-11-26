@props(['model', 'label', 'field', 'placeholder' => 'Nome do médico ou profissional', 'maxlength' => 120])

{{--
    Campo de profissional: escolhe um guardado (os fixos vêm primeiro) ou digita um novo. O filtro das sugestões é no navegador, sem acento nem
    maiúscula; a escolha e a opção de guardar vão ao servidor. $field vem de PicksHealthProfessional::professionalField.
--}}
<div x-data="{ aberto: false, busca: '', plano(t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() } }"
     @click.outside="aberto = false" @keydown.escape="aberto = false" data-campo-profissional>
    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</label>
    <input type="text" wire:model.live.debounce.400ms="{{ $model }}" @focus="aberto = true; busca = ''" @input="aberto = true; busca = $event.target.value"
           class="input mt-1.5" placeholder="{{ $placeholder }}" maxlength="{{ $maxlength }}" autocomplete="off">
    @if ($field['options']->isNotEmpty())
        <ul x-show="aberto" x-cloak class="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white px-1 dark:border-white/10 dark:bg-slate-800">
            @foreach ($field['options'] as $p)
                <li x-show="! busca || plano($el.dataset.busca).includes(plano(busca))" data-busca="{{ $p->name }} {{ $p->specialty }}" wire:key="opcao-pro-{{ $p->id }}">
                    <button type="button" wire:click="pickProfessional('{{ $p->id }}')" @click="aberto = false" class="block w-full rounded-lg px-2 py-2 text-left text-sm text-slate-800 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
                        @if ($p->is_favorite)<span class="text-amber-600 dark:text-amber-400" title="Médico fixo">★</span> @endif{{ $p->name }}
                        @if ($p->specialty)<span class="text-xs text-slate-400">{{ $p->specialty }}</span>@endif
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($field['canKeep'])
        <label class="mt-2 flex items-start gap-2 px-1 text-sm text-slate-700 dark:text-slate-200" data-guardar-profissional>
            <input type="checkbox" wire:model="keepProfessional" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>Guardar este profissional<span class="block text-xs text-slate-400">Fica disponível para novas consultas e pedidos.</span></span>
        </label>
    @endif
</div>
