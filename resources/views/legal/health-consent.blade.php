<x-layouts.guest title="Consentimento para dados de saúde · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Seus dados de saúde</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        A área de Saúde guarda informações que a lei considera <strong>sensíveis</strong>: ficha de saúde (tipo sanguíneo, alergias, condições, remédios), agenda de
        consultas e exames, itens de cuidado e treinos de academia. Por isso pedimos o seu consentimento específico para tratá-los.
    </p>

    <ul class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300">
        <li><strong>Para quê:</strong> só para você usar a área de Saúde e receber os avisos que você ligou (consulta, troca de item, revisão do treino).</li>
        <li><strong>Quem vê:</strong> você e, na ficha e na agenda, a pessoa do seu casal que divide o perfil. Consultor e corretor <strong>nunca</strong> veem.</li>
        <li><strong>QR Code de emergência:</strong> só existe se você o gerar, e quem o escaneia vê o que você escolheu mostrar. Você pode trocá-lo quando quiser.</li>
        <li><strong>Como guardamos:</strong> os textos da ficha e da agenda ficam cifrados no banco.</li>
        <li><strong>Seus direitos:</strong> você pode revogar este consentimento a qualquer momento em Minha conta (a área de Saúde fecha e os avisos param),
            baixar seus dados e apagar a conta, o que apaga também os dados de saúde.</li>
    </ul>

    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
        Detalhes na <a href="{{ route('legal.privacy') }}" target="_blank" class="underline">Política de Privacidade</a>.
        Sem o consentimento, o restante do Cerne continua funcionando normalmente.
    </p>

    <form method="POST" action="{{ route('health.consent.store') }}" class="mt-6 space-y-4">
        @csrf
        <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
            <input type="checkbox" name="consent" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>Consinto com o tratamento dos meus dados de saúde pelo Cerne, para as finalidades acima.</span>
        </label>
        @error('consent') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

        <button type="submit" class="btn-primary w-full py-2.5">Dar consentimento e continuar</button>
    </form>

    <a href="{{ route('dashboard') }}" class="mt-3 block text-center text-sm text-slate-500 underline dark:text-slate-400">Agora não</a>
</x-layouts.guest>
