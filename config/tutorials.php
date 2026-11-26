<?php

/*
 * Tutoriais das telas, indexados pelo NOME DA ROTA (é também a chave do que cada pessoa já viu).
 * Tela sem entrada aqui não ganha o botão "?" nem o cartão de primeira visita.
 *
 * Cada entrada: title (nome da tela), summary (uma frase de até 110 caracteres, aparece no cartão),
 * steps (de 3 a 6 passos curtos) e, se valer a pena, tip ("Bom saber"). Texto em tom formal, sem travessão
 * e sem "pra" ou "num"; TutorialCatalogTest confere. Só entra o que o código confirma: mudou o comportamento
 * da tela, o tutorial dela muda junto.
 */
return [
    'pages' => [
        'dashboard' => [
            'title' => 'Visão geral',
            'summary' => 'O resumo do seu patrimônio e do mês atual, com o que vence nos próximos dias.',
            'steps' => [
                'O cartão azul mostra o patrimônio investido: os investimentos menos as faturas de cartão em aberto.',
                'O saldo em conta aparece no cartão, mas não entra nessa soma.',
                'Abaixo, veja as receitas, as despesas e a sobra do mês atual, com a taxa de poupança.',
                'O gráfico compara receitas e despesas dos últimos 12 meses.',
                'Quando houver contas e faturas vencendo nos próximos 7 dias, elas aparecem em uma lista própria.',
            ],
            'tip' => 'Pagar a fatura do cartão não gera despesa: as compras já contam no mês em que foram feitas.',
        ],

        'cashflow.index' => [
            'title' => 'Fluxo de caixa',
            'summary' => 'Registre o que entrou e o que saiu, mês a mês, e veja quanto sobrou.',
            'steps' => [
                'Use “+ Despesa” e “+ Receita” para lançar, e as setas no alto para mudar de mês.',
                'Na despesa, “Conta / dinheiro” debita o saldo da conta na hora, e “Cartão de crédito” leva a compra para a fatura.',
                'Compra parcelada vira uma despesa por parcela, cada uma na fatura do seu ciclo.',
                'Marque “Estorno ou cashback” quando for dinheiro de volta: o valor abate os totais.',
                'Em casal, “Ocultar do meu cônjuge” deixa o lançamento só com você.',
                'Os filtros por necessidade, categoria e membro ajudam a achar o que procura.',
            ],
            'tip' => 'Despesa em fatura já paga fica bloqueada. Para alterar, estorne antes o pagamento da fatura.',
        ],

        'fixedbills.index' => [
            'title' => 'Contas fixas',
            'summary' => 'Contas e receitas que se repetem, com os vencimentos de cada mês.',
            'steps' => [
                'Use “+ Conta fixa” e informe a recorrência (semanal, mensal ou anual) e o dia do vencimento.',
                'Ao abrir a tela ou mudar de mês, os vencimentos do mês são gerados sozinhos.',
                'Marque “Pagar” quando quitar, ou “Pular” se não houver cobrança naquele mês.',
                'Em conta de valor variável, digite o valor na linha antes de pagar. Parcela de dívida e de consórcio aparece sozinha, e pagar aqui paga a parcela.',
                'Se o extrato já pagou uma conta, ela aparece em "Contas que parecem já pagas pelo extrato": concilie em vez de pagar, para não contar duas vezes.',
                'A aba “Receitas recorrentes” funciona do mesmo jeito, com “Receber”.',
            ],
            'tip' => 'A despesa só é lançada no Fluxo de caixa se a conta tiver categoria e conta para debitar. Sem isso, “Pagar” apenas marca como paga.',
        ],

        'search.index' => [
            'title' => 'Buscar',
            'summary' => 'Ache um lançamento, conta, dívida, objetivo, investimento, apólice ou documento digitando parte do nome.',
            'steps' => [
                'Digite pelo menos 2 letras: o resultado aparece enquanto você escreve.',
                'Os resultados vêm separados por tipo. Toque em um para abrir a tela dele.',
                'Em qualquer tela, aperte a tecla “/” para vir direto à busca.',
            ],
            'tip' => 'A busca olha nomes e descrições. Dados protegidos, como o nome do segurado e as anotações, não entram.',
        ],

        'assistant.index' => [
            'title' => 'Pergunte ao Cerne',
            'summary' => 'Converse com o assistente sobre as finanças do perfil: gastos, saldos, vencimentos e objetivos.',
            'steps' => [
                'Escreva a pergunta do jeito que falaria, como “quanto gastei com delivery este mês?”.',
                'O assistente consulta os seus dados e responde com os valores. Ele vê o mesmo que você vê.',
                'Ele só consulta: para lançar, pagar ou alterar algo, use as telas do app.',
                'Use “Nova conversa” para recomeçar. A conversa não fica gravada.',
            ],
            'tip' => 'Confira os valores importantes nas telas: o assistente pode errar.',
        ],

        'cashflow.analytics' => [
            'title' => 'Análises do fluxo de caixa',
            'summary' => 'Para onde vai o dinheiro, como cada categoria evolui e em que ritmo você gasta no mês.',
            'steps' => [
                'Escolha o mês no alto. Os gráficos acompanham o mês escolhido.',
                'Na rosca, clique em uma categoria para ver as subcategorias dela.',
                'O gráfico de linhas compara a despesa acumulada do mês com a do mês anterior, dia a dia.',
                'Use “Ver em tabela” sob cada gráfico para conferir os números e “Baixar PDF” para guardar ou enviar.',
            ],
            'tip' => 'O que um cônjuge marcou como privado não entra nos seus totais.',
        ],

        'debts.analytics' => [
            'title' => 'Análises de dívidas',
            'summary' => 'Quanto de cada parcela de um financiamento ou empréstimo é juro, e quanto amortiza a dívida.',
            'steps' => [
                'Escolha a dívida no alto, quando houver mais de uma com juros.',
                'As colunas mostram as próximas parcelas divididas em juros, amortização e taxas.',
                'Os cartões somam o que já foi pago em juros e o que o contrato ainda vai cobrar.',
                'Baixe o PDF para levar a conversa ao banco ou ao seu consultor.',
            ],
            'tip' => 'Os números vêm do cronograma do contrato, sem correção que o credor aplique por fora.',
        ],

        'investments.analytics' => [
            'title' => 'Análises de investimentos',
            'summary' => 'Onde o seu patrimônio está concentrado, por instituição.',
            'steps' => [
                'A barra em destaque é a instituição que mais pesa no seu patrimônio.',
                'Passe o mouse (ou toque) em uma barra para ver o valor e a parte exata.',
                'Para rentabilidade e proventos ao longo do tempo, use a tela de Desempenho.',
            ],
            'tip' => 'O Cerne mostra a distribuição, mas não recomenda quanto manter em cada instituição.',
        ],

        'accounts.index' => [
            'title' => 'Contas e cartões',
            'summary' => 'Os saldos das suas contas e as faturas de cartão ainda em aberto.',
            'steps' => [
                'Use “+ Conta” e digite o saldo atual: depois dele, o saldo se move com os lançamentos que usam a conta.',
                'Use “+ Cartão” e informe o dia de fechamento e o de vencimento da fatura.',
                'Conta conjunta aparece sempre para os dois. Nas demais, escolha se o cônjuge vê e se entra no consolidado.',
                'Em “Ver fatura”, “Marcar como paga” debita a conta escolhida, e “Estornar pagamento” devolve o saldo.',
            ],
            'tip' => 'Pagar a fatura não cria despesa, porque as compras já foram lançadas no Fluxo de caixa.',
        ],

        'debts.index' => [
            'title' => 'Dívidas',
            'summary' => 'Financiamentos, empréstimos, consórcios e dívidas, com as parcelas ligadas às despesas.',
            'steps' => [
                'Use “Nova dívida” e escolha o tipo: financiamento, empréstimo, consórcio ou dívida em aberto.',
                'Ao cadastrar, a dívida entra sozinha em Contas fixas, com o vencimento de cada parcela, e sai de lá quando acabar.',
                'Abra a dívida e use “Pagar” em cada parcela: o pagamento cria a despesa e reduz o saldo devedor.',
                'Se a parcela já foi lançada no Fluxo de caixa, use “Vincular despesa” para não contar duas vezes. “Desfazer” solta a ligação e nunca apaga a despesa.',
                'Antes da contemplação, o consórcio é investimento: o que vale é a carta, que não entra no total devedor.',
                '“Importar extrato do consórcio” cadastra tudo a partir do PDF, com revisão antes de gravar.',
            ],
            'tip' => 'Dívida não altera o patrimônio investido: ela aparece à parte, em Dívidas.',
        ],

        'investments.index' => [
            'title' => 'Investimentos',
            'summary' => 'A carteira, a rentabilidade e as movimentações dos seus investimentos.',
            'steps' => [
                'Use “+ Investimento” para cadastrar um ativo. No alto, veja o patrimônio, o custo de aquisição e o ganho.',
                'Movimente com “Comprar mais”, “Vender”, “Cotação” e “Provento”.',
                '“Editar” não muda a quantidade nem o preço médio: para isso, use Comprar mais, Vender ou Cotação.',
                'Ao editar o valor, informe a data dele: o gráfico do mês é atualizado.',
                'Em USDT, BTC e ETH, digite a quantidade: o valor em reais vem da cotação do dia.',
                'As abas “Performance” e “Transações” mostram a evolução e o histórico.',
            ],
            'tip' => 'Em casal, você pode ocultar um investimento do cônjuge no cadastro.',
        ],

        'goals.index' => [
            'title' => 'Sonhos e objetivos',
            'summary' => 'Seus sonhos e metas por ordem de prioridade, com quanto falta para cada um.',
            'steps' => [
                'Use “Novo objetivo” e informe o nome, o valor, a data-alvo e a prioridade (1 é a mais importante).',
                'Escolha como ele avança: pelo dinheiro que você informa, pelo saldo de investimentos ou pelas parcelas pagas de um consórcio.',
                'Com investimentos ligados (pode marcar mais de um), o acumulado é a soma dos saldos deles, não um valor digitado.',
                '“Guardar por mês” só aparece quando o objetivo tem data-alvo no futuro.',
                '“Marcar como conquistado” leva o objetivo para a lista de conquistados, e “Reabrir” o traz de volta.',
            ],
            'tip' => 'Excluir um objetivo não apaga o investimento nem o consórcio ligados a ele.',
        ],

        'budget.index' => [
            'title' => 'Regra de orçamento',
            'summary' => 'Defina quanto da receita vai para cada área e veja, no mês, o que deveria ir e o que foi.',
            'steps' => [
                'Escolha um modelo pronto, o seu (se você é consultor) ou "Personalizado", que começa em branco. Ajuste os percentuais de essencial, supérfluo e investimentos.',
                'Dentro de investimentos, divida em grupos (reservas, aposentadoria, objetivos) e em itens ligados às categorias de investimento.',
                'Seguro de vida e parcela de consórcio entram como compromisso fixo: saem do grupo antes, com o valor que está cadastrado.',
                'A receita do mês é o que já entrou mais o previsto nas receitas recorrentes. As metas saem dela.',
                'O realizado vem dos lançamentos de investimento do fluxo de caixa. Transferência para a conta de investimento não conta: só a compra do ativo.',
                'Em "Dividir o balde de objetivos", o Cerne sugere quanto guardar por mês em cada objetivo, e você aplica se concordar.',
            ],
            'tip' => 'Mudar a regra vale a partir do mês que você escolher: os meses anteriores seguem a regra que valia.',
        ],

        'consultant.budget-templates' => [
            'title' => 'Modelos de regra',
            'summary' => 'Guarde a sua forma de dividir a receita e aplique a vários clientes de uma vez.',
            'steps' => [
                'Use "Novo modelo" e comece por um modelo pronto. Ajuste os percentuais de essencial, supérfluo e investimentos.',
                'Divida investimentos em grupos e itens, ligados às categorias de investimento. Seguro de vida e consórcio entram como compromisso fixo.',
                'Em "Aplicar a clientes", marque quem vai receber e o mês em que a regra passa a valer.',
                'Aplicar copia o modelo para o cliente. Mudar o modelo depois não altera quem já recebeu.',
                'O cliente continua podendo ajustar a própria regra, e você enxerga o resultado em "Regra da carteira".',
            ],
            'tip' => 'Só os clientes com vínculo ativo aparecem para receber o modelo.',
        ],

        'consultant.portfolio.budget' => [
            'title' => 'Regra da carteira',
            'summary' => 'Veja, no mês, como cada cliente está em relação à regra de orçamento dele.',
            'steps' => [
                'Cada linha mostra a receita do mês e, por área, o que foi lançado contra a meta ou o limite.',
                'Essencial e supérfluo têm limite: passar dele aparece em vermelho. Em investimentos, o que importa é bater a meta.',
                'No alto, veja quantos clientes estão dentro dos limites e quantos bateram a meta de investimento.',
                'Use as setas para ver outros meses e "Abrir perfil" para ajustar a regra de um cliente.',
            ],
            'tip' => 'Os números enxergam tudo do cliente, inclusive o que é privado entre o casal.',
        ],

        'documents.index' => [
            'title' => 'Importar arquivo',
            'summary' => 'Envie o PDF ou o CSV de um extrato ou fatura: o Cerne lê e você confere antes de gravar.',
            'steps' => [
                'Escolha o arquivo (PDF, ou CSV no caso de extrato e fatura), o tipo e a conta ou o cartão. Depois, “Enviar”.',
                'A leitura é automática, mas nada é gravado sem a sua revisão.',
                'Quando o status for “Aguardando revisão”, abra “Revisar” e confira cada linha, com necessidade e categoria.',
                'Possíveis duplicatas vêm desmarcadas. Marque apenas o que deseja importar.',
                'Pagamento de fatura e transferência entre suas contas não viram despesa: use a opção própria na linha.',
                'O limite é de 32 MB e 600 páginas por arquivo; o CSV aceita até 500 lançamentos por envio.',
            ],
            'tip' => 'Apólice e extrato de consórcio não são importados aqui: a apólice vai em Seguros, e o consórcio em Dívidas.',
        ],

        'insurance.index' => [
            'title' => 'Apólices',
            'summary' => 'Suas apólices por tipo de seguro, com coberturas, custo mensal e vencimentos.',
            'steps' => [
                'Use “+ Nova apólice” para cadastrar à mão, ou “Importar apólice (PDF)” para o Cerne ler o arquivo.',
                'Na importação, nada entra na lista antes de você conferir os dados lidos.',
                'Avisos em amarelo mostram as apólices que vencem em até 30 dias.',
                '“Por categoria de risco” mostra a cobertura e o custo mensal de cada tipo de seguro.',
                'Em cada apólice, use “Editar”, “Ver apólice (PDF)” e “Histórico da apólice”.',
            ],
            'tip' => 'Apólice marcada como “Renova automaticamente” não gera aviso de vencimento. Excluir apaga a apólice, sem lixeira.',
        ],

        'documents.vault.index' => [
            'title' => 'Documentos',
            'summary' => 'CNH, passaporte, certificados, apólices e exames, organizados em pastas.',
            'steps' => [
                'Use “+ Novo documento” e informe a categoria, o título, a pessoa, a pasta e a validade. Aceita PDF, JPG ou PNG, até 10 MB.',
                'Crie pastas com “+ Nova pasta” ou use “Criar pastas sugeridas”.',
                'Em cada documento, use “Ver”, “Baixar”, “Editar” e “remover”.',
                'A validade aparece em vermelho quando o documento está vencido.',
                'Consultor e corretor só veem apólices e documentos “Outro” que você marcar como visíveis. Nunca veem CNH, passaporte nem exames.',
            ],
            'tip' => 'Remover um documento apaga também o arquivo. Remover uma pasta não apaga os documentos: eles sobem para a pasta de cima.',
        ],

        'health.card.index' => [
            'title' => 'Ficha de saúde',
            'summary' => 'Tipo sanguíneo, alergias, doenças, remédios e o QR Code de emergência.',
            'steps' => [
                'Escolha o tipo sanguíneo: ele é salvo na hora.',
                'Adicione alergias e doenças. O botão “remover” age na hora, sem pedir confirmação.',
                'Use “+ Remédio” para registrar dose, horário e motivo. Remédio não é excluído, apenas suspenso, e o histórico fica guardado.',
                'O QR Code de emergência abre uma página sem login, com tipo sanguíneo, alergias, doenças, remédios em uso e contatos.',
                'Use “Compartilhar link” ou “Baixar imagem” para levar o QR Code com você.',
                '“Gerar novo código” invalida o QR Code que já foi impresso ou compartilhado.',
            ],
            'tip' => 'Em casal, a ficha é visível para os dois. Consultor e corretor não têm acesso à saúde.',
        ],

        'health.appointments.index' => [
            'title' => 'Agenda de saúde',
            'summary' => 'Consultas e exames, com quem atende, onde fica e um aviso um dia antes.',
            'steps' => [
                'Use “+ Agendar” e informe a pessoa, o tipo, o profissional, a data, a hora e onde fica.',
                'Alterne entre Calendário e Lista para ver os compromissos do jeito que preferir.',
                'Depois da data, responda “Foi realizada” ou “Não foi realizada”. Até lá, a consulta fica aguardando confirmação.',
                '“Cancelada” pode ser marcada a qualquer momento, e “desfazer” devolve o compromisso para agendado.',
                'Use “Adicionar à agenda” para levar o compromisso ao Google Agenda ou a um arquivo .ics.',
            ],
            'tip' => 'Remover um compromisso é definitivo. Para guardar o registro, prefira marcar como cancelado.',
        ],

        'health.care.index' => [
            'title' => 'Cuidados e itens',
            'summary' => 'Itens que pedem troca ou revisão de tempos em tempos, com aviso antes da data.',
            'steps' => [
                'Use “+ Novo item” e informe o que é trocado, de quanto em quanto tempo e a última vez que foi feito.',
                'O Cerne calcula a próxima vez e avisa um dia antes e no próprio dia.',
                'Ao fazer a troca, use “Feito hoje”: o Cerne grava a data de hoje e recalcula a próxima.',
                '“Pausar” interrompe os avisos. Ao retomar, a contagem recomeça de hoje.',
            ],
            'tip' => '“Feito hoje” usa sempre a data de hoje. Se a troca foi em outro dia, corrija a data em “Editar”. Remédios de uso contínuo ficam na Ficha de saúde.',
        ],

        'health.routines.index' => [
            'title' => 'Check-ups e rotinas',
            'summary' => 'O que repetir de tempos em tempos, se está em dia e o histórico de cada ciclo.',
            'steps' => [
                'Use “+ Nova rotina” e diga de quanto em quanto tempo se repete, como check-up geral todo ano.',
                'Ligue à rotina as consultas e os exames, daqui ou na Agenda de Saúde. A próxima data sai da última consulta realizada.',
                'Exame pedido sem data fica em “Pedidos sem data” até você marcar.',
                'O histórico mostra cada ciclo: feito, em andamento ou não registrado.',
            ],
            'tip' => 'Avisamos 60 dias antes e no dia, a não ser que já exista um compromisso marcado ligado à rotina.',
        ],

        'health.followups.index' => [
            'title' => 'Acompanhamentos',
            'summary' => 'Um problema de saúde por vez, com consultas, exames, documentos, anotações e atividades.',
            'steps' => [
                'Use “+ Novo” ou abra a partir de uma consulta da Agenda. A consulta continua no check-up, se estiver em um.',
                'Na linha do tempo, acrescente consultas, exames pedidos, documentos e anotações com nota de dor.',
                'No programa de atividades, registre o que o profissional orientou, os exercícios e as presenças.',
                'Use “PDF para o médico” para levar o resumo a outro profissional.',
            ],
            'tip' => 'Quem vê a Agenda de Saúde da pessoa vê o acompanhamento. Cargas e séries da Academia continuam só suas, e o consultor não vê nada.',
        ],

        'health.exams.index' => [
            'title' => 'Exames',
            'summary' => 'Os pedidos do médico, o que falta agendar e os laudos para revisar.',
            'steps' => [
                'Use “+ Novo pedido” e marque na lista o que o médico pediu.',
                'Dentro do pedido, selecione os exames do mesmo dia e use “Marcar data”. Os outros continuam esperando.',
                'Quando sair o laudo, envie o PDF para leitura ou digite o resultado à mão.',
                'Confira o que foi lido antes de salvar: nada vira resultado sem a sua revisão.',
            ],
            'tip' => 'Guarde também os laudos de anos anteriores: eles entram na comparação.',
        ],

        'health.exams.create' => [
            'title' => 'Novo pedido de exame',
            'summary' => 'Escolha o tipo, marque o que foi pedido e escreva o detalhe de cada exame.',
            'steps' => [
                'Escolha o tipo, como Sangue ou Cardiologia, ou busque pelo nome.',
                'Marque o que está no pedido do médico. O que não estiver na lista você digita.',
                'Use o detalhe do pedido para região, lado ou preparo de um exame.',
                'No check-up seguinte, “Repetir o pedido” traz a lista anterior para você ajustar.',
            ],
            'tip' => 'Nada vem marcado: o Cerne registra o que foi pedido e não sugere exames.',
        ],

        'health.exams.results' => [
            'title' => 'Comparar resultados',
            'summary' => 'Os valores de cada data lado a lado, com gráfico, e as conclusões dos laudos.',
            'steps' => [
                'Em Valores, cada linha é um resultado e cada coluna é uma data.',
                'Toque no nome para ver o gráfico. A faixa sombreada é a referência do laudo mais recente.',
                'O aviso de acima ou abaixo compara o valor com a referência do próprio laudo.',
                'Em Laudos, veja as conclusões do mesmo exame em cada data.',
            ],
            'tip' => 'O Cerne não interpreta resultado. Leve ao médico o PDF do acompanhamento ou os laudos.',
        ],

        'health.gym.index' => [
            'title' => 'Academia',
            'summary' => 'Seu plano de treino, o próximo treino e as últimas sessões. Só você vê estes dados.',
            'steps' => [
                'Use “Montar plano” ou “Treino automático”. No automático, nada é gravado até você revisar e salvar.',
                '“Iniciar treino” começa o próximo da sequência, e “Ver treino” mostra os exercícios antes.',
                'Nos cartões A, B e C, “Iniciar este” permite treinar fora de ordem. A sequência continua a partir do último concluído.',
                'Em “Para olhar”, veja os exercícios em que já dá para subir a carga e os que estão parados.',
                'Quando a fase passar do ponto de troca, o aviso oferece criar o treino novo.',
            ],
            'tip' => 'O treino automático segue regras fixas e é orientação geral, não prescrição profissional.',
        ],

        'engagement.index' => [
            'title' => 'Acompanhamento',
            'summary' => 'Reuniões, tarefas e demandas com o consultor financeiro, em um só lugar.',
            'steps' => [
                'Em “Próxima reunião”, veja a pauta e o que levar. Depois da reunião, o consultor registra a ata.',
                'Em “Tarefas”, use “Nova tarefa” com prazo e responsável. Em casal, escolha uma das pessoas ou as duas.',
                'Em “Demandas”, o cliente envia uma pergunta ou pedido ao consultor e acompanha a resposta.',
                'O “Passo a passo” é montado pelo consultor. Os itens automáticos são marcados pelo próprio Cerne.',
                'Em “Notas”, o que é visível para os dois aparece para você e para o consultor. A nota privada é só do consultor.',
            ],
            'tip' => 'Encerrar o vínculo com o consultor não apaga o histórico do acompanhamento.',
        ],

        'consultant.portfolio' => [
            'title' => 'Painel da carteira',
            'summary' => 'O panorama dos clientes com vínculo ativo: patrimônio, seguros e o que pede atenção.',
            'steps' => [
                'Os cartões do alto mostram o patrimônio investido, os clientes vinculados, os prêmios por mês e quem tem 2 ou mais apólices.',
                'Em “Ações pendentes”, veja os convites aguardando aceite e as faturas vencendo.',
                'Use “+ Convidar cliente”. Com e-mail novo, vai um convite de cadastro. Com e-mail que já tem conta, vai um pedido de autorização de vínculo.',
                'Em “Por cliente”, busque, filtre por status e ordene. “Abrir perfil” entra no perfil do cliente.',
                'A ordem “Precisa de atenção” põe primeiro quem está ativo e sem seguro de vida.',
            ],
            'tip' => 'Os totais vêm só dos clientes com vínculo ativo. Pendentes e inativos aparecem esmaecidos na lista.',
        ],
    ],
];
