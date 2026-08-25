@props(['current' => \App\Enums\ThemePreference::System])

{{--
    Seletor claro/sistema/escuro. O estado inicial vem do servidor (sem
    flash de tema errado); a troca depois é só resources/js/app.js, que
    aplica a classe na hora e salva em segundo plano — ver
    App\Http\Controllers\ThemePreferenceController.
--}}
<div class="flex items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-900" data-theme-switcher>
    @foreach (['light' => 'sun', 'system' => 'monitor', 'dark' => 'moon'] as $value => $icon)
        <button
            type="button"
            data-theme-value="{{ $value }}"
            @class(['theme-switch-btn', 'theme-switch-active' => $current->value === $value])
            title="{{ \App\Enums\ThemePreference::from($value)->label() }}"
        >
            <x-nav-icon :name="$icon" class="h-4 w-4" />
            <span class="sr-only">{{ \App\Enums\ThemePreference::from($value)->label() }}</span>
        </button>
    @endforeach
</div>
