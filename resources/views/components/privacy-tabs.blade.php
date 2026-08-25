@props(['members', 'viewAs'])

{{--
    Casal / cada membro — só aparece quando a tela decidiu que faz
    sentido (ver HasPrivacyTabs::showPrivacyTabs). Mesmo estilo visual
    das abas Portfólio/Performance/Transações, pra não introduzir um
    padrão novo de navegação.
--}}
<div class="flex gap-1 border-b border-slate-200 dark:border-white/10">
    <button
        type="button"
        wire:click="setViewAs('')"
        @class([
            '-mb-px border-b-2 px-4 py-2 text-sm transition',
            'border-brand-700 dark:border-brand-400 font-medium text-brand-800 dark:text-brand-300' => $viewAs === '',
            'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' => $viewAs !== '',
        ])
    >Casal</button>
    @foreach ($members as $membro)
        <button
            type="button"
            wire:click="setViewAs('{{ $membro->id }}')"
            @class([
                '-mb-px border-b-2 px-4 py-2 text-sm transition',
                'border-brand-700 dark:border-brand-400 font-medium text-brand-800 dark:text-brand-300' => $viewAs === $membro->id,
                'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' => $viewAs !== $membro->id,
            ])
        >{{ $membro->name }}</button>
    @endforeach
</div>
