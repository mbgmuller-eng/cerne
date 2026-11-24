<?php
    // Cache-busting das capturas de tela: o header delas é "Cache-Control: public, max-age=604800" (ver public/.htaccess). Sem o ?v=, atualizar a
    // imagem no servidor não adianta para quem já abriu a página, o navegador segue servindo a versão velha por até 7 dias.
    $marketingImage = fn (string $nome) => asset("images/marketing/{$nome}.jpg").'?v='.filemtime(public_path("images/marketing/{$nome}.jpg"));

    // Pacotes do usuário final do mais barato ao mais completo.
    $pacotesPorPreco = collect(\App\Enums\SubscriptionBundle::cases())
        ->sortBy(fn ($pacote) => config('billing.prices.'.$pacote->value))
        ->values();

    $precoPessoaAcompanhada = \App\Support\Money::format(config('billing.care_seat_price'));

    // Telas da vitrine: [chave, rótulo da aba, imagem, texto alternativo, título, legenda, cor da aba ativa]
    $telas = [
        ['painel', 'Visão geral', 'painel', 'Visão geral do painel financeiro no Cerne', 'Seu mês em uma tela',
            'Patrimônio, receitas, despesas e sobra do mês, com a variação contra o mês anterior, o que vence nos próximos dias e o saldo das contas hoje, no início do mês e previsto. Escolha o mês que quiser.', 'bg-brand-800'],
        ['fluxo', 'Fluxo de caixa', 'fluxo-de-caixa', 'Fluxo de caixa do mês no Cerne', 'Cada real no lugar dele',
            'Lance receitas e despesas (ou fale a despesa), filtre por necessidade, categoria, subcategoria e pessoa, e veja a composição entre essencial, supérfluo e investimento.', 'bg-brand-800'],
        ['extrato', 'Extrato por dia', 'extrato', 'Extrato de uma conta bancária, dia a dia, com saldo ao fim de cada dia', 'O saldo de cada dia',
            'O extrato de cada conta mostra o que entrou e saiu em cada dia, o saldo ao fim do dia e o que ainda está previsto no mês. Um toque em "Efetivar" dá baixa na conta fixa ou na receita prevista.', 'bg-brand-800'],
        ['contas', 'Contas e cartões', 'contas', 'Contas bancárias e cartões de crédito com limite usado', 'Contas, cartões e faturas',
            'Saldo de cada conta, limite usado de cada cartão, fatura aberta com fechamento e vencimento e o total que ainda está para pagar. Conta conjunta aparece para os dois.', 'bg-brand-800'],
        ['regra', 'Regra de orçamento', 'regra', 'Regra de orçamento com essencial, supérfluo e investimentos', 'Quanto da receita vai para cada área',
            'Escolha 50/20/30, 50/30/20 ou monte a sua. O Cerne mostra quanto deveria ir para essencial, supérfluo e investimentos, quanto já foi e para onde vai cada aporte: reservas, aposentadoria e objetivos.', 'bg-brand-800'],
        ['objetivos', 'Objetivos', 'objetivos', 'Objetivos com anel de progresso e previsão de conclusão', 'Do sonho ao prazo',
            'Cada objetivo mostra o progresso, quanto falta, quanto guardar por mês para chegar a tempo e a data prevista no seu ritmo. Avança pelo dinheiro guardado ou pelo saldo de investimentos ligados a ele.', 'bg-brand-800'],
        ['dividas', 'Dívidas', 'dividas', 'Financiamentos, empréstimos e consórcios', 'Dívidas sob controle',
            'Financiamento, empréstimo, consórcio e dívida em aberto, com as parcelas ligadas às despesas, saldo devedor, qual quitar primeiro, simulação de acordo e o saldo para o Imposto de Renda.', 'bg-brand-800'],
        ['investimentos', 'Investimentos', 'investimentos', 'Carteira de investimentos com perfil do investidor e alocação por classe de ativo', 'Carteira com perfil do investidor',
            'Alocação por classe de ativo contra a meta do seu perfil, reservas de paz e de oportunidade, rendimento de cada investimento, proventos, preço médio e posições em moedas como BTC e USDT.', 'bg-brand-800'],
        ['seguros', 'Seguros', 'seguros', 'Apólices de seguro organizadas no Cerne', 'Todas as apólices juntas',
            'Apólices por tipo, pessoa e seguradora, com valor segurado, prêmio mensal e vencimento. Envie o PDF da apólice e o Cerne lê os dados, para você conferir antes de salvar.', 'bg-seguros-800'],
        ['documentos', 'Documentos', 'documentos', 'Cofre de documentos em pastas no Cerne', 'Seus documentos, em pastas do seu jeito',
            'CNH, passaporte, certificados, apólices e exames, com a validade de cada um e controle de quem vê. O seu consultor ou corretor só enxerga o que você autorizar.', 'bg-documentos-800'],
        ['saude-agenda', 'Agenda de saúde', 'saude-agenda', 'Agenda de consultas e exames com lembretes', 'Consultas e exames, sem esquecer',
            'Consultas e exames com quem atende, onde fica e como chegar, lembrete um dia antes e atalho para o Google Agenda ou arquivo de calendário. Cada pessoa da família na sua cor.', 'bg-saude-800'],
        ['saude-ficha', 'Ficha de saúde', 'saude-ficha', 'Ficha de saúde com QR Code de emergência', 'O essencial, à mão em uma emergência',
            'Tipo sanguíneo, alergias, doenças e remédios com histórico, mais um QR Code: quem ler vê o que importa em uma emergência, sem precisar de login.', 'bg-saude-800'],
        ['saude', 'Academia', 'saude', 'Treino de academia acompanhado no Cerne', 'Treino com histórico',
            'Monte o seu treino ou gere um automático pelo seu objetivo, registre as séries, acompanhe a evolução em gráficos e baixe o relatório em PDF. Só você vê.', 'bg-saude-800'],
    ];

    $recursos = [
        ['upload', 'Importe e confira', 'Envie o extrato ou a fatura em PDF ou CSV. A inteligência artificial lê e sugere cada lançamento, e nada é gravado sem a sua revisão, linha por linha.'],
        ['bills', 'Contas fixas que não se perdem', 'Os vencimentos aparecem sozinhos, com aviso antes de vencer, baixa em um toque e conciliação com o extrato. A conta que está no cartão entra direto na fatura certa.'],
        ['users', 'Casal, com privacidade de verdade', 'Cada lançamento pode ser marcado como privado e some para o cônjuge, valendo igual para os dois. O que é conjunto aparece para ambos.'],
        ['debt', 'Dívidas e consórcio', 'Parcelas ligadas às despesas e entrando sozinhas em Contas fixas, simulador de antecipação e de acordo, reajuste e contemplação do consórcio e o relatório para o Imposto de Renda.'],
        ['invest', 'Investimentos completos', 'Preço médio, proventos, desdobramentos, desempenho, Imposto de Renda e moedas como BTC, ETH e USDT com cotação diária.'],
        ['target', 'Objetivos que se acompanham', 'Previsão a partir do quanto você guarda por mês, histórico de aportes e aviso quando o seu ritmo ficar abaixo do planejado.'],
        ['mic', 'Fale a despesa', 'Diga "gastei 45 reais de Uber" e o Cerne preenche o lançamento. Você confere e salva.'],
        ['search', 'Busque em tudo', 'Lançamentos, contas, dívidas, objetivos, investimentos, apólices e documentos em uma busca só. Aperte "/" em qualquer tela para começar.'],
        ['pie', 'Análises com gráficos e PDF', 'Rosca por categoria, evolução mês a mês, ritmo de gasto contra o mês anterior, juros de cada parcela e concentração por instituição, com um PDF de cada área para guardar ou enviar.'],
        ['bills', 'Apoio ao Imposto de renda', 'O saldo de cada dívida em 31/12 e o resumo dos investimentos do ano, em tela, PDF e CSV, para conferir antes de declarar. É conferência com o que está registrado, não a declaração.'],
        ['bell', 'Avisos no momento certo', 'Contas e faturas a vencer, parcelas de dívida, apólices a renovar, consultas e exames, objetivos abaixo do planejado: no app, por e-mail e no celular.'],
    ];

    $faq = [
        'Tem período de teste?' => 'Sim, 7 dias grátis em qualquer plano, para usar e decidir com calma. Você só é cobrado depois que o teste termina.',
        'Como funciona a cobrança?' => 'Mensal, processada pela Asaas, só em cartão de crédito ou Pix. Você escolhe a forma de pagamento perto do fim do teste, e avisamos 3 dias antes por e-mail e dentro do Cerne. Depois do último dia de teste, o acesso fica bloqueado até o pagamento ser confirmado. Nos meses seguintes você recebe o link de pagamento e paga com um clique; quem escolhe Pix recebe um aviso por e-mail 3 dias antes de cada vencimento.',
        'Posso cancelar quando quiser?' => 'Sim, pela sua área de conta, sem precisar falar com ninguém. O acesso é encerrado na hora do cancelamento, sem desconto proporcional do período já pago.',
        'Como funciona a importação de extrato e fatura?' => 'Você envia o PDF (ou o CSV) do extrato ou da fatura, a inteligência artificial lê e sugere os lançamentos, e você revisa antes de qualquer coisa ser gravada. O arquivo original é apagado 90 dias depois de a importação ser confirmada ou descartada.',
        'Posso usar no celular?' => 'Sim. O Cerne funciona no navegador do celular e pode ser adicionado à tela inicial, como um aplicativo. O menu, o botão de lançar e a busca foram pensados para a tela pequena.',
        'Minha assinatura cobre meu cônjuge também?' => 'Sim. A assinatura é por perfil financeiro, não por pessoa: se vocês dividem o mesmo perfil de casal, uma assinatura libera o acesso para os dois.',
        'O que é privado entre mim e meu cônjuge?' => 'Cada lançamento pode ser marcado como privado, e então só quem o criou o vê. A regra vale igual para os dois, sem "dono" que enxerga tudo. O que é conjunto, como uma conta conjunta, aparece para ambos.',
        'Posso cuidar da saúde dos meus pais pelo Cerne?' => 'Sim. Você cadastra a pessoa que acompanha, mesmo que ela não use o aplicativo, e guarda ficha de saúde, consultas, exames e documentos dela. Cada pessoa acompanhada soma '.$precoPessoaAcompanhada.' por mês à sua assinatura, e os cuidadores que você convidar não pagam nada.',
        'O consultor ou corretor vê meus dados de Saúde?' => 'Não, nunca, em nenhuma circunstância, mesmo vinculado e com acesso ativo ao resto do seu perfil.',
        'Qual a diferença entre assinar direto e ter um profissional vinculado?' => 'Se o seu consultor ou corretor já tem assinatura profissional ativa, seu acesso vem sem custo por esse vínculo. Assinar direto só é necessário se você quiser usar o Cerne sozinho, sem nenhum profissional.',
        'Meus dados são compartilhados com terceiros?' => 'Não vendemos dados. Compartilhamos só o necessário para o serviço: a Asaas processa a cobrança e, quando você importa um arquivo ou fala um gasto, a Anthropic lê o conteúdo para sugerir os lançamentos. Os detalhes estão na Política de Privacidade.',
        'Posso levar meus dados embora ou excluir a conta?' => 'Sim, em Minha conta: você baixa uma cópia dos seus dados em formato aberto e pede a exclusão da conta, com 7 dias de carência para desistir.',
        'Sou profissional. Posso usar o Cerne com os meus clientes?' => 'Sim. Existe uma página própria para profissionais, com os preços por número de clientes e as telas de acompanhamento.',
    ];
?>
<x-layouts.marketing title="Cerne: finanças, seguros, documentos e saúde em um só lugar"
    description="Fluxo de caixa, investimentos, dívidas, seguros, documentos e saúde da família em um só lugar, com privacidade para o casal. Importe extratos com IA e confira antes de gravar. 7 dias grátis.">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl md:mx-auto md:max-w-3xl md:text-center">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne para você</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Finanças, seguros, documentos, saúde: tudo o que sustenta sua vida, em um só lugar.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Sozinho, ou com quem te ajuda a cuidar dela, com a privacidade que você escolher.
            </p>
            <p class="mt-3 text-base text-slate-500 dark:text-slate-400">
                Importe o extrato e confira, acompanhe investimentos, dívidas e objetivos, guarde seus documentos e cuide da saúde da família, no computador e no celular.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 md:justify-center">
                <a href="#planos" class="btn-primary px-6 py-3 text-base">Começar 7 dias grátis</a>
                <a href="#por-dentro" class="btn-secondary px-6 py-3 text-base">Ver por dentro</a>
            </div>
            <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
                É consultor, corretor ou outro profissional?
                <a href="{{ route('professionals') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Conheça o Cerne para profissionais</a>
            </p>
        </div>
    </section>

    {{-- Vitrine de telas --}}
    <section id="por-dentro" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6" x-data="{ aba: 'painel' }">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Veja o Cerne por dentro</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">As mesmas telas que você vai usar, com dados de demonstração.</p>

        <div class="mt-6 flex flex-wrap gap-2">
            @foreach ($telas as [$chave, $rotulo, , , , , $corAtiva])
                <button type="button" @click="aba = '{{ $chave }}'" :class="aba === '{{ $chave }}' ? '{{ $corAtiva }} text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">{{ $rotulo }}</button>
            @endforeach
        </div>

        <div class="mt-6">
            @foreach ($telas as [$chave, , $imagem, $alt, $titulo, $legenda])
                <div x-show="aba === '{{ $chave }}'" @if (! $loop->first) x-cloak @endif data-vitrine="{{ $chave }}">
                    <x-screenshot-frame src="{{ $marketingImage($imagem) }}" alt="{{ $alt }}" />
                    <div class="mt-4 max-w-3xl">
                        <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $titulo }}</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $legenda }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Para quem --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto grid max-w-6xl gap-5 px-4 sm:px-6 md:grid-cols-2">
            <div class="card p-6">
                <p class="eyebrow">Para você</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Assine direto</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Você cria sua conta, escolhe o plano e já começa a acompanhar sua vida inteira,
                    com a privacidade de escolher o que fica só seu e o que é do casal.
                </p>
                <a href="#planos" class="mt-4 inline-block text-sm font-medium text-brand-700 underline dark:text-brand-300">Ver os planos</a>
            </div>

            <div class="card p-6">
                <p class="eyebrow">Com acompanhamento</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Seu consultor ou corretor já usa o Cerne?</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Se a assinatura dele está em dia, o seu acesso vem por ele, sem custo para você. Vocês acompanham juntos:
                    reuniões com lembrete, tarefas, demandas e um passo a passo do plano. Ele vê só a área dele: Saúde nunca, e Documentos apenas o que você autorizar.
                </p>
                <a href="{{ route('professionals') }}" class="mt-4 inline-block text-sm font-medium text-brand-700 underline dark:text-brand-300">Indicar o Cerne ao seu profissional</a>
            </div>
        </div>
    </section>

    {{-- Módulos --}}
    <section id="modulos" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">O que o Cerne organiza</h2>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Quatro áreas que conversam entre si. Você assina as que fazem sentido para você.</p>
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['bg-brand-800', 'Finanças', ['Fluxo de caixa, contas fixas e cartões', 'Regra de orçamento do mês', 'Investimentos, proventos e moedas', 'Dívidas, financiamentos e consórcios', 'Objetivos com previsão e histórico', 'Importação de extrato e fatura com IA']],
                ['bg-seguros-800', 'Seguros', ['Apólices por tipo, pessoa e seguradora', 'Importação da apólice em PDF', 'Datas importantes e renovação', 'Acompanhamento com seu corretor']],
                ['bg-documentos-800', 'Documentos', ['CNH, passaporte, certificados e exames', 'Pastas do seu jeito', 'Validade registrada em cada documento', 'Visibilidade por categoria']],
                ['bg-saude-800', 'Saúde', ['Ficha de saúde com QR de emergência', 'Agenda de consultas com lembrete', 'Treino de academia com histórico e PDF', 'Itens de cuidado com data de troca', 'Cuidado de pais e familiares']],
            ] as [$cor, $nome, $itens])
                <div class="card overflow-hidden">
                    <div class="h-1.5 {{ $cor }}"></div>
                    <div class="p-5">
                        <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $nome }}</h3>
                        <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                            @foreach ($itens as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Recursos --}}
    <section id="recursos" class="scroll-mt-28 border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="eyebrow">Recursos</p>
            <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Feito para o dia a dia de verdade</h2>
            <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">O que costuma dar trabalho em planilha e em outros aplicativos, resolvido.</p>

            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($recursos as [$icone, $titulo, $texto])
                    <div class="card p-5" data-recurso>
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-800 dark:bg-white/10 dark:text-brand-200"><x-nav-icon :name="$icone" /></span>
                        <h3 class="mt-4 font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $titulo }}</h3>
                        <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-300">{{ $texto }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Cuidado --}}
    <section id="cuidado" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6">
        <p class="eyebrow">Cuidado em família</p>
        <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Cuide de quem você cuida</h2>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Saúde e documentos de um pai, de uma mãe ou de quem você ajuda, em um lugar que a família toda acessa, cada um com o seu nível de acesso.</p>

        <div class="mt-8 grid items-start gap-8 lg:grid-cols-2">
            <div class="grid gap-4 sm:grid-cols-2" data-cuidado>
                @foreach ([
                    ['Pessoa acompanhada', 'Cadastre quem você acompanha, mesmo que a pessoa não use o aplicativo: ficha de saúde, consultas, exames, itens de cuidado e documentos. Cada pessoa soma '.$precoPessoaAcompanhada.' por mês à sua assinatura.'],
                    ['Cuidadores', 'Convide irmãos e cuidadores para ver ou editar a saúde e os documentos que você liberar. Eles não pagam e não veem as suas finanças.'],
                    ['Quem leva?', 'Na consulta de quem você acompanha, qualquer pessoa pode se oferecer para levar, e todos são avisados de remarcações e cancelamentos.'],
                    ['Família acompanha as finanças', 'Se quiser, um filho ou familiar acompanha as suas finanças só em leitura, com um resumo semanal. O que você marcou como privado nunca aparece para ele.'],
                ] as [$titulo, $texto])
                    <div class="card p-5">
                        <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">{{ $titulo }}</h3>
                        <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-300">{{ $texto }}</p>
                    </div>
                @endforeach
            </div>

            <div class="space-y-4">
                <x-screenshot-frame src="{{ $marketingImage('saude-ficha') }}" alt="Ficha de saúde com QR Code de emergência" />
                <p class="text-xs text-slate-500 dark:text-slate-400">A saúde só é tratada com o seu consentimento específico, pedido antes de você abrir a área, e você pode revogá-lo quando quiser.</p>
            </div>
        </div>
    </section>

    {{-- Celular --}}
    <section id="celular" class="scroll-mt-28 border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2">
            <div>
                <p class="eyebrow">No celular</p>
                <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Do computador ao celular, o mesmo Cerne</h2>
                <ul class="mt-5 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                    <li class="flex gap-2.5"><span class="text-accent-600 dark:text-accent-400">✓</span><span><strong class="text-slate-900 dark:text-white">Menu lateral</strong> com as áreas em grupos, que recolhem para não virar uma lista longa.</span></li>
                    <li class="flex gap-2.5"><span class="text-accent-600 dark:text-accent-400">✓</span><span><strong class="text-slate-900 dark:text-white">Botão de lançar</strong> sempre à mão: receita, despesa ou falar a despesa.</span></li>
                    <li class="flex gap-2.5"><span class="text-accent-600 dark:text-accent-400">✓</span><span><strong class="text-slate-900 dark:text-white">Busca</strong> no topo, para achar um lançamento, uma apólice ou um documento.</span></li>
                    <li class="flex gap-2.5"><span class="text-accent-600 dark:text-accent-400">✓</span><span><strong class="text-slate-900 dark:text-white">Adicione à tela inicial</strong> e use como um aplicativo, com avisos no aparelho.</span></li>
                    <li class="flex gap-2.5"><span class="text-accent-600 dark:text-accent-400">✓</span><span><strong class="text-slate-900 dark:text-white">Tema claro ou escuro</strong>, o que for melhor para os seus olhos.</span></li>
                </ul>
            </div>

            <div class="flex min-w-0 items-start justify-center gap-4 sm:gap-5" data-celulares>
                @foreach ([['celular-painel', 'Painel do Cerne no celular'], ['celular-menu', 'Menu lateral do Cerne no celular']] as $i => [$nome, $alt])
                    <div @class(['w-36 shrink-0 overflow-hidden rounded-[2rem] border-[6px] border-slate-900 bg-slate-900 shadow-card sm:w-52', 'mt-8' => $i === 1])>
                        <img src="{{ $marketingImage($nome) }}" alt="{{ $alt }}" class="w-full" loading="lazy">
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Segurança --}}
    <section id="seguranca" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6">
        <p class="eyebrow">Segurança e privacidade</p>
        <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Seus dados, sob o seu controle</h2>
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-seguranca>
            @foreach ([
                ['Cada cliente só vê o que é seu', 'O isolamento entre contas vale em todas as consultas, e o que um cônjuge marcou como privado não aparece para o outro, nem em totais.'],
                ['Campos sensíveis cifrados', 'CPF/CNPJ, telefone, número de conta, anotações, nome do segurado e o conteúdo da ficha de saúde ficam cifrados no banco de dados.'],
                ['Verificação em dois passos', 'Ative um aplicativo autenticador na sua conta. Para consultores, corretores e administradores ela é obrigatória.'],
                ['Baixe ou exclua quando quiser', 'Em Minha conta você baixa uma cópia dos seus dados em formato aberto e pede a exclusão da conta, com 7 dias de carência.'],
                ['Saúde só com o seu consentimento', 'A área de Saúde só abre depois de um consentimento específico, que você revoga quando quiser. Consultor e corretor nunca a veem.'],
                ['Acesso da equipe registrado', 'Quando a nossa equipe precisa entrar em uma conta para dar suporte, registramos quem entrou, quando e por quê.'],
            ] as [$titulo, $texto])
                <div class="card p-5">
                    <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">{{ $titulo }}</h3>
                    <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-300">{{ $texto }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">Leia a <a href="{{ route('legal.privacy') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Política de Privacidade</a> completa.</p>
    </section>

    {{-- Planos --}}
    <section id="planos" class="scroll-mt-28 border-t border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="text-center">
                <p class="eyebrow">Planos</p>
                <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Escolha o seu plano</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">7 dias grátis em qualquer plano. Depois, cobrança mensal, sem fidelidade.</p>
            </div>

            <div class="mt-12 grid items-stretch gap-6 lg:grid-cols-3">
                @foreach ($pacotesPorPreco as $pacote)
                    @php
                        $destaque = $pacote === \App\Enums\SubscriptionBundle::Completo;
                        [$reais, $centavos] = explode(',', \App\Support\Money::format(config('billing.prices.'.$pacote->value), false));
                    @endphp
                    <div @class([
                        'relative flex flex-col rounded-2xl bg-white p-7 shadow-card dark:bg-slate-800',
                        'ring-1 ring-brand-950/10 dark:ring-white/10' => ! $destaque,
                        'ring-2 ring-accent-500 shadow-[0_0_48px_-12px_rgb(16_185_129_/_0.45)] lg:scale-[1.03]' => $destaque,
                    ])>
                        @if ($destaque)
                            <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-accent-600 px-4 py-1 text-[11px] font-bold tracking-wider text-white uppercase shadow-lg">Mais completo</span>
                        @endif

                        <h3 class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ collect($pacote->modules())->map(fn ($m) => $m->label())->join(' · ') }}
                        </p>

                        <p class="mt-6 flex items-baseline gap-1 text-slate-900 dark:text-white">
                            <span class="text-xl font-semibold text-slate-500 dark:text-slate-400">R$</span>
                            <span class="font-display text-6xl font-semibold tracking-tight">{{ $reais }}</span>
                            <span class="text-2xl font-semibold">,{{ $centavos }}</span>
                            <span class="text-sm font-normal text-slate-400">/mês</span>
                        </p>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Cartão de crédito ou Pix · cancele quando quiser</p>
                        <p class="mt-3 w-fit rounded-full bg-accent-500/10 px-3 py-1 text-xs font-semibold text-accent-700 dark:text-accent-400">7 dias grátis, sem cobrança hoje</p>

                        <ul class="mt-6 space-y-3 border-t border-slate-200/70 pt-6 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">
                            @foreach ($pacote->modules() as $modulo)
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                    <span><strong class="text-slate-900 dark:text-white">{{ $modulo->label() }}:</strong> {{ $modulo->summary() }}</span>
                                </li>
                            @endforeach
                            @foreach (['Perfil individual ou de casal, na mesma assinatura', 'Sem fidelidade: cancele quando quiser'] as $item)
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-auto pt-8">
                            <a href="{{ route('checkout.show', $pacote->value) }}" class="btn-primary w-full py-3 text-base">Começar 7 dias grátis</a>
                            <p class="mt-2 text-center text-xs text-slate-400">A primeira cobrança só acontece depois do teste.</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-10 text-center text-sm text-slate-500 dark:text-slate-400" data-aviso-cuidado>
                Quer cuidar de um familiar? Cada pessoa acompanhada soma {{ $precoPessoaAcompanhada }} por mês à sua assinatura. Cuidadores convidados não pagam.
            </p>
            <p class="mt-3 text-center text-sm text-slate-500 dark:text-slate-400">
                Já tem um consultor ou corretor parceiro? Se a assinatura dele está em dia, seu acesso
                vem por ele, sem custo para você. Vale perguntar antes de assinar.
            </p>
        </div>
    </section>

    {{-- Perguntas frequentes --}}
    <section id="perguntas" class="scroll-mt-28 border-t border-slate-200/70 py-16 dark:border-white/10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Perguntas frequentes</h2>

            <div class="mt-8 space-y-3">
                @foreach ($faq as $pergunta => $resposta)
                    <div class="card overflow-hidden" x-data="{ open: false }">
                        <button type="button" @click="open = ! open" class="flex w-full items-center justify-between gap-4 p-5 text-left">
                            <span class="font-medium text-slate-900 dark:text-white">{{ $pergunta }}</span>
                            <span class="shrink-0 text-xl leading-none text-slate-400" x-text="open ? '−' : '+'"></span>
                        </button>
                        <p class="px-5 pb-5 text-sm text-slate-600 dark:text-slate-300" x-show="open" x-cloak>{{ $resposta }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA final --}}
    <section class="mx-auto max-w-6xl px-4 py-16 text-center sm:px-6">
        <h2 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Pronto para começar?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="#planos" class="btn-primary px-6 py-3 text-base">Começar 7 dias grátis</a>
            @auth
                <a href="{{ route('dashboard') }}" class="btn-secondary px-6 py-3 text-base">Ir para o painel</a>
            @else
                <a href="{{ route('login') }}" class="btn-secondary px-6 py-3 text-base">Já tenho conta</a>
            @endauth
        </div>
        <div class="mt-6 flex flex-wrap justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span class="badge bg-slate-100 dark:bg-slate-800">7 dias grátis</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cancele quando quiser</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Sem taxa de adesão</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cartão de crédito ou Pix</span>
        </div>
        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
            É profissional?
            <a href="{{ route('professionals') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Veja o Cerne para profissionais</a>
        </p>
    </section>

</x-layouts.marketing>
