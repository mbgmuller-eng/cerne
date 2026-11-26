<x-layouts.guest title="Consentimento para dados de saúde · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Seus dados de saúde</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        A área de Saúde guarda informações que a lei considera <strong>sensíveis</strong>: ficha de saúde (tipo sanguíneo, alergias, condições, remédios), agenda de
        consultas e exames, itens de cuidado, check-ups e rotinas preventivas, pedidos de exame, resultados de exames, acompanhamentos de um problema de saúde (anotações, nota de dor e documentos ligados), programa de atividades
        (exercícios que você cadastra, presenças e nota de dor) e treinos de academia. Por isso pedimos o seu consentimento específico para tratá-los.
    </p>

    <ul class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300">
        <li><strong>Para quê:</strong> só para você usar a área de Saúde e receber os avisos que você ligou (consulta, check-up, troca de item, revisão do treino).</li>
        <li><strong>Quem vê:</strong> você e a pessoa do seu casal que divide o perfil, que vê a ficha, a agenda, os itens de cuidado, os check-ups, os pedidos e resultados de exames, os acompanhamentos e o programa de atividades. Os treinos de academia são só seus. Se você convidar alguém para acompanhar a sua saúde, essa pessoa também vê, com o acesso que você escolher e só até você encerrá-lo. Consultor e corretor <strong>nunca</strong> veem.</li>
        <li><strong>QR Code de emergência:</strong> só existe se você o gerar, e quem o escaneia vê o que você escolheu mostrar. Você pode trocá-lo quando quiser.</li>
        <li><strong>Como guardamos:</strong> os textos da ficha, da agenda, dos check-ups, dos exames, dos acompanhamentos e do programa de atividades ficam cifrados no banco.</li>
        <li><strong>Leitura de laudo por inteligência artificial:</strong> só quando você envia o PDF de um laudo para leitura. O arquivo vai à Anthropic, fora do Brasil, e pode trazer o seu nome e documentos. Nada é guardado como resultado sem a sua revisão, e você pode digitar à mão em vez de enviar.</li>
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
