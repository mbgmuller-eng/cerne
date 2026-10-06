{{-- No celular o Sair não cabe mais no cabeçalho da área do profissional;
     no PC ele continua na lateral. --}}
<form method="POST" action="{{ route('logout') }}" class="lg:hidden">
    @csrf
    <button type="submit" class="btn-ghost w-full justify-center border border-slate-200 dark:border-white/10">
        <x-nav-icon name="logout" class="h-4 w-4" />
        <span class="ml-2">Sair da conta</span>
    </button>
</form>
