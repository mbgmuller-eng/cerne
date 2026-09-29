<x-layouts.guest title="Emergência · {{ $member->name }} · Cerne">
    <div class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-300">
        Informação de emergência — mostre a quem estiver atendendo.
    </div>

    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $member->name }}</h1>

    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Tipo sanguíneo</p>
            <p class="mt-0.5 text-slate-800 dark:text-slate-200">{{ $card->blood_type ?: '—' }}</p>
        </div>
    </div>

    <div class="mt-5">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Alergias</p>
        @if ($allergies->isEmpty())
            <p class="mt-1 text-sm text-slate-400">Nenhuma registrada.</p>
        @else
            <ul class="mt-1 space-y-0.5 text-sm text-slate-800 dark:text-slate-200">
                @foreach ($allergies as $alergia)
                    <li>{{ $alergia->description }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-5">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Doenças e comorbidades</p>
        @if ($conditions->isEmpty())
            <p class="mt-1 text-sm text-slate-400">Nenhuma registrada.</p>
        @else
            <ul class="mt-1 space-y-0.5 text-sm text-slate-800 dark:text-slate-200">
                @foreach ($conditions as $condicao)
                    <li>{{ $condicao->description }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-5">
        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Remédios em uso</p>
        @if ($medications->isEmpty())
            <p class="mt-1 text-sm text-slate-400">Nenhum registrado.</p>
        @else
            <ul class="mt-1 space-y-0.5 text-sm text-slate-800 dark:text-slate-200">
                @foreach ($medications as $remedio)
                    <li>{{ $remedio->name }}{{ $remedio->dose ? ' — '.$remedio->dose : '' }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($contacts->isNotEmpty())
        <div class="mt-5">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Contatos</p>
            <ul class="mt-1 space-y-0.5 text-sm text-slate-800 dark:text-slate-200">
                @foreach ($contacts as $contato)
                    <li>{{ $contato['name'] }} — {{ $contato['phone'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="mt-6 text-xs text-slate-400">Gerado pelo Cerne. Este link não exige login e mostra só o essencial para uma emergência.</p>
</x-layouts.guest>
