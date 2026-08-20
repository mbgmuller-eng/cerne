<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Privacidade do casal</h1>
        <p class="mt-1 max-w-2xl text-sm text-stone-500">
            Escolha o que cada um enxerga do outro em {{ $profile->profile_name }}.
            Seu consultor continua vendo tudo — é o que permite a ele orientar o casal.
        </p>
    </div>

    {{-- Atalhos ------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        @php
            $presets = [
                'transparent' => ['🔓', 'Transparente', 'Vocês dois veem e editam tudo.'],
                'private'     => ['🔒', 'Privado', 'Cada um vê apenas o que é seu.'],
                'custom'      => ['⚙️', 'Personalizado', 'Você decide domínio a domínio.'],
            ];
        @endphp

        @foreach ($presets as $key => [$icon, $title, $description])
            <button
                type="button"
                @if ($key !== 'custom') wire:click="applyPreset('{{ $key }}')" @endif
                @class([
                    'rounded-xl border p-5 text-left transition',
                    'border-brand-700 bg-brand-50 ring-1 ring-brand-700' => $currentPreset === $key,
                    'border-stone-200 bg-white hover:border-stone-300' => $currentPreset !== $key,
                    'cursor-default' => $key === 'custom',
                ])
            >
                <span class="text-lg">{{ $icon }}</span>
                <p class="mt-2 text-sm font-semibold text-stone-900">{{ $title }}</p>
                <p class="mt-1 text-xs text-stone-500">{{ $description }}</p>
            </button>
        @endforeach
    </div>

    {{-- Campo a campo -------------------------------------------------- --}}
    <form wire:submit="save" class="card">
        <div class="border-b border-stone-200 px-6 py-4">
            <h2 class="text-sm font-semibold text-stone-900">Detalhe por domínio</h2>
        </div>

        <ul class="divide-y divide-stone-200">
            @foreach ($domains as $column => $label)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-sm text-stone-700">{{ $label }}</span>

                    <div class="flex gap-2">
                        @foreach (\App\Enums\Visibility::cases() as $option)
                            <label @class([
                                'cursor-pointer rounded-lg border px-3 py-1.5 text-xs transition',
                                'border-brand-700 bg-brand-50 text-brand-900' => $visibility[$column] === $option->value,
                                'border-stone-300 text-stone-600 hover:border-stone-400' => $visibility[$column] !== $option->value,
                            ])>
                                <input
                                    type="radio"
                                    class="sr-only"
                                    wire:model.live="visibility.{{ $column }}"
                                    value="{{ $option->value }}"
                                >
                                {{ $option->label() }}
                            </label>
                        @endforeach
                    </div>
                </li>
            @endforeach

            <li class="flex items-center justify-between px-6 py-4">
                <div>
                    <p class="text-sm text-stone-700">Editar lançamentos do parceiro</p>
                    <p class="text-xs text-stone-500">Permite corrigir um lançamento feito pelo outro.</p>
                </div>

                <label class="inline-flex cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="canEditPartnerRecords" class="peer sr-only">
                    <span class="relative h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-brand-700 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5"></span>
                </label>
            </li>
        </ul>

        <div class="flex items-center justify-end gap-3 border-t border-stone-200 px-6 py-4">
            <button
                type="submit"
                class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-900 disabled:opacity-60"
                wire:loading.attr="disabled"
            >
                Salvar
            </button>
        </div>
    </form>

</div>
