@if ($perfis->isEmpty())
    <p class="px-5 py-4 text-sm text-slate-400 dark:text-slate-500">{{ $vazio }}</p>
@else
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-slate-500 dark:text-slate-400">
            <tr>
                <th class="px-5 py-2 font-medium">Perfil</th>
                <th class="px-5 py-2 font-medium">Dono</th>
                <th class="px-5 py-2 font-medium">Tipo</th>
                <th class="px-5 py-2 font-medium">Criado em</th>
                <th class="px-5 py-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($perfis as $p)
                <tr>
                    <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">{{ $p->profile_name }}</td>
                    <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->owner->email }}</td>
                    <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->profile_type->label() }}</td>
                    <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->created_at->format('d/m/Y') }}</td>
                    <td class="px-5 py-2.5 text-right whitespace-nowrap">
                        @unless ($p->owner_user_id === auth()->id())
                            <button type="button" wire:click="entrarComo('{{ $p->owner_user_id }}')" class="text-sm text-accent-700 hover:underline dark:text-accent-400">Entrar como</button>
                            <button type="button" wire:click="pedirExclusao('{{ $p->owner_user_id }}')" class="ml-3 text-sm text-red-700 hover:underline dark:text-red-400">Excluir</button>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
