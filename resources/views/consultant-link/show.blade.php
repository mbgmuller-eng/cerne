<x-layouts.app>
    <div class="mx-auto max-w-lg space-y-6 py-10">
        <div class="card space-y-5 p-6">
            <div>
                <p class="eyebrow text-slate-400">Pedido de vínculo</p>
                <h1 class="mt-1 font-display text-xl font-semibold text-slate-900 dark:text-white">
                    @if ($vinculo->consultant->isBroker())
                        {{ $vinculo->consultant->name }} quer ser seu corretor de seguros
                    @else
                        {{ $vinculo->consultant->name }} quer ser seu consultor
                    @endif
                </h1>
            </div>

            @if ($vinculo->consultant->isBroker())
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Autorizando, <strong>{{ $vinculo->consultant->name }}</strong> passa a ver a tela de
                    Seguros da sua conta — mas só as apólices que você escolher liberar abaixo. Nada mais
                    das suas informações financeiras fica visível pra ele.
                </p>
            @else
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Autorizando, <strong>{{ $vinculo->consultant->name }}</strong> passa a enxergar suas
                    informações financeiras — inclusive o que for privado entre você e seu cônjuge, se
                    houver perfil de casal.
                </p>
            @endif

            <form method="POST" action="{{ route('link.accept', $vinculo) }}" class="space-y-4">
                @csrf

                @if ($vinculo->consultant->isBroker() && $apolices->isNotEmpty())
                    <div class="rounded-xl border border-slate-100 dark:border-white/10">
                        <p class="border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs font-medium text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                            Apólices já cadastradas — marque as que quer liberar pra {{ $vinculo->consultant->name }}
                        </p>
                        <ul class="divide-y divide-slate-100 px-4 dark:divide-white/10">
                            @foreach ($apolices as $apolice)
                                <li class="flex items-start gap-3 py-3">
                                    <input
                                        type="checkbox"
                                        name="apolices_compartilhadas[]"
                                        value="{{ $apolice->id }}"
                                        id="apolice-{{ $apolice->id }}"
                                        class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                    >
                                    <label for="apolice-{{ $apolice->id }}" class="text-sm text-slate-700 dark:text-slate-300">
                                        {{ $apolice->insurance_type->label() }} · {{ $apolice->insurer_name }}
                                        @if ($apolice->personLabel())
                                            <span class="text-xs text-slate-400">({{ $apolice->personLabel() }})</span>
                                        @endif
                                        @if ($apolice->broker_id !== null && $apolice->broker_id !== $vinculo->consultant_id)
                                            <br><span class="text-xs text-amber-700 dark:text-amber-400">
                                                Já compartilhada com {{ $apolice->broker->name }} — marcar aqui troca pra {{ $vinculo->consultant->name }}.
                                            </span>
                                        @endif
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @elseif ($vinculo->consultant->isBroker())
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Você ainda não tem apólices cadastradas — quando cadastrar, poderá escolher
                        compartilhar com corretores vinculados a qualquer momento.
                    </p>
                @endif

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-primary">Autorizar</button>
                    <button type="submit" formaction="{{ route('link.decline', $vinculo) }}" class="btn-secondary">Recusar</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
