/**
 * Tema (claro/escuro/sistema): aplica a classe em <html> na hora e salva
 * a preferência no servidor em segundo plano — ver
 * App\Http\Controllers\ThemePreferenceController.
 *
 * Quando a preferência é explícita (claro/escuro), o <html> já nasce com
 * a classe certa renderizada pelo servidor — sem flash. Este script cobre
 * dois casos que o servidor não sabe decidir sozinho: "sistema" (olha o
 * SO via matchMedia) e a troca ao vivo quando a pessoa clica num botão.
 */
(function () {
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    function applyIfSystem() {
        if (root.dataset.themePreference === 'system') {
            root.classList.toggle('dark', media.matches);
        }
    }

    media.addEventListener('change', applyIfSystem);

    function setActiveButton(value) {
        document.querySelectorAll('[data-theme-switcher] [data-theme-value]').forEach((button) => {
            button.classList.toggle('theme-switch-active', button.dataset.themeValue === value);
        });
    }

    function applyTheme(value) {
        root.dataset.themePreference = value;
        root.classList.toggle('dark', value === 'dark' || (value === 'system' && media.matches));
        setActiveButton(value);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-theme-switcher] [data-theme-value]');

        if (!button) {
            return;
        }

        const value = button.dataset.themeValue;
        applyTheme(value);

        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        fetch('/preferencias/tema', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/json',
            },
            body: JSON.stringify({ theme: value }),
            keepalive: true,
        }).catch(() => {
            // Preferência de tela, não dado financeiro: falhar em silêncio
            // é melhor que travar a troca visual por causa da rede.
        });
    });
})();
