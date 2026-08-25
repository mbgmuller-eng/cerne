<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Privacidade do casal</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
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
                    'border-brand-700 bg-brand-50 ring-1 ring-brand-700 dark:border-brand-400 dark:bg-brand-500/10 dark:ring-brand-400' => $currentPreset === $key,
                    'border-slate-200 bg-white hover:border-slate-300 dark:border-white/10 dark:bg-slate-800 dark:hover:border-slate-600' => $currentPreset !== $key,
                    'cursor-default' => $key === 'custom',
                ])
            >
                <span class="text-lg">{{ $icon }}</span>
                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
            </button>
        @endforeach
    </div>

    {{-- Campo a campo -------------------------------------------------- --}}
    <form wire:submit="save" class="card">
        <div class="border-b border-slate-200 dark:border-white/10 px-6 py-4">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Detalhe por domínio</h2>
        </div>

        <ul class="divide-y divide-slate-200 dark:divide-white/10">
            @foreach ($domains as $column => $label)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ $label }}</span>

                    <div class="flex gap-2">
                        @foreach (\App\Enums\Visibility::cases() as $option)
                            <label @class([
                                'cursor-pointer rounded-lg border px-3 py-1.5 text-xs transition',
                                'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-400 dark:bg-brand-500/10 dark:text-brand-100' => $visibility[$column] === $option->value,
                                'border-slate-300 text-slate-600 hover:border-slate-400 dark:border-slate-600 dark:text-slate-300 dark:hover:border-slate-500' => $visibility[$column] !== $option->value,
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
                    <p class="text-sm text-slate-700 dark:text-slate-300">Editar lançamentos do parceiro</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Permite corrigir um lançamento feito pelo outro.</p>
                </div>

                <label class="inline-flex cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="canEditPartnerRecords" class="peer sr-only">
                    <span class="relative h-6 w-11 rounded-full bg-slate-300 dark:bg-slate-600 transition peer-checked:bg-brand-700 dark:peer-checked:bg-brand-400 after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5"></span>
                </label>
            </li>
        </ul>

        <div class="flex items-center justify-end gap-3 border-t border-slate-200 dark:border-white/10 px-6 py-4">
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
