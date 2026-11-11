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
                'Em conta de valor variável, digite o valor na linha antes de pagar.',
                'A aba “Receitas recorrentes” funciona do mesmo jeito, com “Receber”.',
            ],
            'tip' => 'A despesa só é lançada no Fluxo de caixa se a conta tiver categoria e conta para debitar. Sem isso, “Pagar” apenas marca como paga.',
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
                'Abra a dívida e use “Pagar” em cada parcela: o pagamento cria a despesa e reduz o saldo devedor.',
                'Se a parcela já foi lançada no Fluxo de caixa, use “Vincular despesa” para não contar duas vezes.',
                '“Desfazer” solta a ligação do pagamento e nunca apaga a despesa.',
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
                'Escolha como ele avança: pelo dinheiro que você informa, pelo saldo de um investimento ou pelas parcelas pagas de um consórcio.',
                'Com um investimento ligado, o acumulado é o saldo do investimento inteiro, não um valor digitado.',
                '“Guardar por mês” só aparece quando o objetivo tem data-alvo no futuro.',
                '“Marcar como conquistado” leva o objetivo para a lista de conquistados, e “Reabrir” o traz de volta.',
            ],
            'tip' => 'Excluir um objetivo não apaga o investimento nem o consórcio ligados a ele.',
        ],

        'documents.index' => [
            'title' => 'Importar PDF',
            'summary' => 'Envie o PDF de um extrato ou fatura: o Cerne lê e você confere antes de gravar.',
            'steps' => [
                'Escolha o arquivo PDF, o tipo (extrato bancário, fatura de cartão e outros) e a conta ou o cartão. Depois, “Enviar”.',
                'A leitura é automática, mas nada é gravado sem a sua revisão.',
                'Quando o status for “Aguardando revisão”, abra “Revisar” e confira cada linha, com necessidade e categoria.',
                'Possíveis duplicatas vêm desmarcadas. Marque apenas o que deseja importar.',
                'Pagamento de fatura e transferência entre suas contas não viram despesa: use a opção própria na linha.',
                'O limite é de 32 MB e 600 páginas por arquivo.',
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
