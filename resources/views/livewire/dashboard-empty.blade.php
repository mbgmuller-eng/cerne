<div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-6 py-12 text-center">
    <p class="text-sm text-stone-600">Nenhum perfil financeiro ativo.</p>

    @if ($isConsultant)
        <a href="{{ route('consultant.clients') }}" class="mt-2 inline-block text-sm text-brand-800 hover:underline">
            Escolher um cliente
        </a>
    @endif
</div>
