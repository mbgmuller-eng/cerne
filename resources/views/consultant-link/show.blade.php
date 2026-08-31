<x-layouts.app>
    <div class="mx-auto max-w-lg space-y-6 py-10">
        <div class="card space-y-5 p-6">
            <div>
                <p class="eyebrow text-slate-400">Pedido de vínculo</p>
                <h1 class="mt-1 font-display text-xl font-semibold text-slate-900 dark:text-white">
                    {{ $vinculo->consultant->name }} quer ser seu consultor
                </h1>
            </div>

            <p class="text-sm text-slate-600 dark:text-slate-300">
                Autorizando, <strong>{{ $vinculo->consultant->name }}</strong> passa a enxergar suas
                informações financeiras — inclusive o que for privado entre você e seu cônjuge, se
                houver perfil de casal.
            </p>

            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('link.accept', $vinculo) }}">
                    @csrf
                    <button type="submit" class="btn-primary">Autorizar</button>
                </form>
                <form method="POST" action="{{ route('link.decline', $vinculo) }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Recusar</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
