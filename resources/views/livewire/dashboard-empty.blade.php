{{-- Um consultor sem cliente aberto nunca chega aqui — Dashboard::mount()
     já redireciona pra Carteira antes do render. Esta tela só aparece pro
     caso raro de um cliente comum sem perfil ativo. --}}
<div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-6 py-12 text-center">
    <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum perfil financeiro ativo.</p>
</div>
