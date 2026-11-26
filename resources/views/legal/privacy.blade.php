@php
    $c = config('cerne.legal');
    $versao = \Carbon\CarbonImmutable::parse($c['terms_version'])->translatedFormat('d \d\e F \d\e Y');
    $h2 = 'font-display text-lg font-semibold text-slate-900 dark:text-white';
@endphp
<x-layouts.marketing title="Política de Privacidade · Cerne">
    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Política de Privacidade</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Versão de {{ $versao }}. Vale junto com os <a href="{{ route('legal.terms') }}" class="underline">Termos de Uso</a>.</p>

        <div class="mt-8 space-y-6 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
            <section>
                <h2 class="{{ $h2 }}">1. Quem é o responsável pelos seus dados</h2>
                <p class="mt-2">
                    O controlador dos dados pessoais tratados no Cerne é
                    <strong>{{ $c['controller_name'] ?: 'o Cerne' }}</strong>@if ($c['controller_cnpj']), inscrito no CNPJ/CPF {{ $c['controller_cnpj'] }}@endif.
                    @if ($c['dpo_email'])
                        O encarregado pelo tratamento de dados pessoais (DPO) é <strong>{{ $c['dpo_name'] ?: 'o encarregado do Cerne' }}</strong>
                        e atende pelo e-mail <a href="mailto:{{ $c['dpo_email'] }}" class="underline">{{ $c['dpo_email'] }}</a>.
                    @else
                        Para falar sobre os seus dados, use o e-mail informado no rodapé do site.
                    @endif
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">2. Quais dados tratamos e para quê</h2>
                <div class="mt-2 space-y-3">
                    <p><strong>Cadastro e acesso:</strong> nome, e-mail, telefone, data de nascimento, senha (guardada em formato irreversível) e as preferências do app. Servem para criar a conta, entrar com segurança e falar com você. Base legal: execução do contrato.</p>
                    <p><strong>Cobrança:</strong> CPF ou CNPJ, endereço e os dados da assinatura. O cartão de crédito é digitado no ambiente da processadora de pagamentos e não passa pelos nossos servidores. Servem para cobrar e emitir nota fiscal. Base legal: execução do contrato e cumprimento de obrigação legal.</p>
                    <p><strong>Finanças:</strong> contas, lançamentos, cartões, investimentos, dívidas, objetivos, seguros e o que mais você cadastrar ou importar. Servem para o app funcionar. Base legal: execução do contrato.</p>
                    <p><strong>Documentos:</strong> os arquivos que você guarda no cofre (documentos pessoais, apólices, exames) e os arquivos (PDF ou CSV) que você envia para importação. Base legal: execução do contrato.</p>
                    <p>
                        <strong>Despesa por voz:</strong> quando você lança um gasto falando, o navegador transcreve a sua fala. Esse serviço de transcrição é do fabricante do navegador (como o Google, no Chrome) e segue a política dele.
                        Só o texto transcrito chega ao Cerne, que o envia à Anthropic (seção 3) para sugerir descrição, valor e categoria. Nada é lançado sem a sua confirmação. Base legal: execução do contrato.
                    </p>
                    <p>
                        <strong>Saúde (dado pessoal sensível):</strong> ficha de saúde (tipo sanguíneo, alergias, problemas de saúde com a situação, desde quando e de onde veio a informação, medicamentos, peso, altura e cintura com histórico), agenda de consultas e exames, itens de cuidado, check-ups e rotinas preventivas,
                        pedidos de exame, resultados de exames (valores, unidades, faixas de referência e conclusões de laudos), acompanhamentos de um problema de saúde (nome do problema, situação, anotações, nota de dor e documentos ligados), programa de atividades (orientação recebida, o que evitar,
                        exercícios que você cadastra, presenças e nota de dor), limitações físicas, inclusive as do passado (região do corpo, situação, desde quando, o que evitar, orientação recebida, profissional e o que ele respondeu sobre exercícios, esportes e corrida),
                        a lista dos seus médicos e profissionais, o diário e os programas de atividades físicas e os treinos de academia.
                        Só são tratados com o seu <strong>consentimento específico e destacado</strong>, pedido antes de você abrir a área de Saúde. Base legal: consentimento (LGPD, art. 11, I).
                        Você pode revogá-lo a qualquer momento em Minha conta.
                    </p>
                    <p>
                        <strong>Medidas do corpo (dado de saúde):</strong> o peso, a altura e a cintura que você registrar, com a data. O Cerne calcula o índice de massa corporal (IMC) e mostra a faixa de peso de referência para a sua altura, pela tabela do IMC. É uma triagem, não é diagnóstico: não separa músculo de gordura, usa outra faixa a partir dos 60 anos, não vale na gravidez e não é calculada antes dos 18 anos.
                        Se quiser, você define uma meta de peso para acompanhar, que só você vê. <strong>As medidas começam visíveis só para você.</strong> Você pode ligar, separadamente, o compartilhamento com quem vê a sua ficha, a exibição do peso e da altura (com a data) no QR de emergência e a exibição das limitações físicas ativas nesse QR. As três opções vêm desligadas, e o Cerne não manda aviso sobre o peso. Base legal: consentimento (LGPD, art. 11, I).
                    </p>
                    <p>
                        <strong>Leitura de laudo (opcional):</strong> quando você envia o PDF de um laudo para leitura, o arquivo vai à Anthropic (seção 3), que devolve os resultados. O laudo pode trazer o seu nome, documentos e outros dados.
                        A leitura é instruída a não copiar para o resultado os dados que identificam o paciente. Nada é guardado como resultado sem a sua revisão, e você pode digitar os resultados à mão sem enviar arquivo algum.
                        O Cerne destaca o valor que ficou fora da referência impressa no laudo: ele não interpreta o resultado nem faz diagnóstico. Base legal: consentimento (LGPD, art. 11, I).
                    </p>
                    <p>
                        Os acompanhamentos e o programa de atividades servem para você organizar o que um profissional orientou: o Cerne não faz diagnóstico e não escolhe exercício para tratar um problema de saúde.
                        Ligar um documento a uma consulta ou a um acompanhamento não muda quem pode vê-lo. O resumo em PDF para o médico só é gerado quando você pede, e quem receber o arquivo vê o que está nele.
                    </p>
                    <p>
                        <strong>Atividades físicas (dado de saúde):</strong> os seus objetivos, o que você já pratica, os dias preferidos de treino, o diário (esporte ou atividade, data, duração, distância, esforço, dor, batimento médio e observação),
                        os treinos de academia, o programa de caminhada e corrida e o que a revisão mudou no seu treino. Servem para montar a sua semana, mostrar a sua evolução, calcular a carga (minutos vezes esforço) e avisar sobre o encaixe das atividades.
                        Esses dados são digitados por você ou vêm dos treinos que você registra no Cerne: o Cerne não os busca em relógio nem em outro aplicativo. Base legal: consentimento (LGPD, art. 11, I).
                    </p>
                    <p>
                        <strong>Limitações físicas e sugestões para o treino:</strong> quando há uma limitação salva, o Cerne compara o que você marcou para evitar com o <strong>nome</strong> de cada exercício e sugere, por precaução, o que pausar.
                        Depois que você registra a orientação de um profissional, ele pode também listar opções de troca, que você escolhe, e propor a entrada dos exercícios que o profissional indicou. Nada muda no treino sem a sua aprovação.
                        Essas sugestões e os passos de caminhada e corrida são regras fixas: não são avaliação, diagnóstico nem prescrição, e podem deixar passar um exercício inadequado. Confira com um profissional.
                        A folha em PDF para levar à consulta só é gerada quando você pede. Ela traz o seu nome, as limitações consideradas (região e situação), os seus objetivos, a sua semana habitual e os exercícios do treino atual, e quem receber o arquivo vê o que está nele.
                    </p>
                    <p>
                        <strong>Prontidão e plano da semana (dado de saúde):</strong> as suas respostas a perguntas sobre coração, pressão, dor no peito, tontura, falta de ar, remédios, ossos e articulações, se você tem liberação médica e, tendo, quem liberou e quando; o objetivo, os dias e os tipos de aeróbico do plano, os minutos planejados e o que a atualização mudou no seu treino. Só você vê. Servem para decidir se o plano e o programa de corrida podem progredir e para montar a sua semana.
                        O Cerne guarda o que você informa e não confere a liberação. Com alguma resposta afirmativa e sem liberação, o plano fica leve, sem aumento, e o programa de corrida não começa nem avança: você muda isso atualizando as respostas. O plano é montado por regras fixas, sem inteligência artificial, não é prescrição, e nada muda no seu treino sem a sua aprovação. Você pode desfazer a atualização. Base legal: consentimento (LGPD, art. 11, I).
                    </p>
                    <p>
                        <strong>Médicos e profissionais:</strong> a lista guarda o que você escolher informar sobre cada um (nome, especialidade, registro profissional, local, endereço, telefone e observações) e quem ele atende, para você usar de novo em consultas, exames e acompanhamentos.
                        Nada é enviado a eles. São dados informados por você; o profissional pode pedir a correção ou a eliminação dos dados dele ao encarregado indicado na seção 1. Base legal: consentimento (LGPD, art. 11, I) quanto ao que revela sobre a sua saúde e legítimo interesse quanto aos dados de contato do profissional.
                    </p>
                    <p>
                        <strong>Pessoa acompanhada (dado pessoal sensível de terceiro):</strong> quem cuida de um familiar ou de outra pessoa pode cadastrá-la no Cerne para guardar a saúde e os documentos dela
                        (nome, ficha de saúde, consultas e exames, itens de cuidado, check-ups, pedidos e resultados de exames, acompanhamentos, programa de atividades, limitações físicas, médicos e profissionais que a atendem e os documentos que forem liberados). Essa pessoa pode não ter conta no Cerne. Base legal: consentimento dela ou,
                        quando ela não puder decidir sozinha, o de seu representante legal, conforme a seção 8.
                    </p>
                    <p>
                        <strong>Dados de quem é convidado:</strong> quando você convida alguém para acompanhar uma pessoa, ou para acompanhar você, guardamos o nome e o e-mail que você informou. Servem para enviar o convite
                        e para identificar o acesso na lista de quem acompanha. Base legal: execução do contrato.
                    </p>
                    <p>
                        <strong>Profissional que revisa as regras de atividades:</strong> um profissional de educação física convidado por nós revisa as regras que o Cerne usa. Dele guardamos o nome, o registro profissional (CREF), o e-mail informado no convite, os pareceres e a data do último acesso. O nome, o registro e a data aparecem no app junto das regras que ele aprovar. Ele acessa por um link, vê apenas as regras e o catálogo de exercícios do Cerne e <strong>não vê dado de nenhum usuário</strong>. Base legal: execução do que foi combinado com ele.
                    </p>
                    <p><strong>Relação com consultor ou corretor:</strong> quando você vincula um profissional, ele passa a ver os dados financeiros e de seguros combinados com você. Dados de saúde nunca são visíveis a consultores ou corretores.</p>
                    <p><strong>Registros técnicos:</strong> endereço de IP, tipo de navegador e erros do sistema, usados para segurança e para corrigir falhas. O registro de erros não guarda os seus dados financeiros. Base legal: legítimo interesse (segurança e melhoria do serviço). O uso de cookies está na seção 12.</p>
                </div>
            </section>

            <section>
                <h2 class="{{ $h2 }}">3. Com quem compartilhamos</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li><strong>Processadora de pagamentos (Asaas):</strong> cobrança, Pix e emissão de nota fiscal.</li>
                    <li><strong>Inteligência artificial (Anthropic):</strong> quando você envia um arquivo para importação (PDF ou CSV de extrato ou fatura, extrato de consórcio, apólice ou relatório de outro aplicativo), envia o PDF de um laudo de exame para leitura na Saúde (dado de saúde) ou fala um gasto, o conteúdo é enviado à Anthropic para ler e devolver os dados. Consultores e corretores que enviam a simulação em PDF de um possível cliente usam o mesmo serviço. Isso envolve transferência internacional (Estados Unidos). O resultado nunca vira lançamento nem resultado de exame sem a sua confirmação na tela (seção 13).</li>
                    <li><strong>Assistente de conversa (recurso opcional, &ldquo;Pergunte ao Cerne&rdquo;):</strong> quando estiver disponível na sua conta e você fizer uma pergunta, enviamos à Anthropic a pergunta e os números que ele precisar consultar para responder (como totais do mês, saldos, vencimentos e objetivos), somente do que você enxerga no perfil aberto. A conversa não fica gravada no Cerne, e o assistente só consulta: não lança, não paga e não altera nada.</li>
                    <li><strong>Envio de e-mails e notificações:</strong> o provedor de e-mail e os serviços de notificação dos fabricantes do navegador (como Google, Apple, Mozilla e Microsoft), que recebem o aviso para entregá-lo no seu aparelho. Isso pode envolver transferência internacional.</li>
                    <li><strong>Hospedagem:</strong> o provedor onde o Cerne roda guarda os dados em nosso nome.</li>
                    <li><strong>Consultor ou corretor vinculado:</strong> somente o que a seção 2 descreve, e só enquanto o vínculo estiver ativo.</li>
                    <li><strong>Cuidadores e pessoas que acompanham você:</strong> somente o que as seções 8 e 9 descrevem (saúde e/ou finanças em leitura, conforme o acesso), e só enquanto o acesso estiver ativo. O acesso é sempre dado por você ou pelo responsável pela pessoa acompanhada, nunca pelo Cerne nem pelo seu consultor ou corretor.</li>
                    <li><strong>Autoridades:</strong> quando a lei ou uma ordem judicial exigir.</li>
                </ul>
                <p class="mt-2">Não vendemos dados pessoais nem os usamos para publicidade de terceiros. As cotações de moedas que o app consulta (como CoinGecko, Binance e AwesomeAPI) não recebem nenhum dado seu.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">4. Quem da equipe do Cerne pode ver os seus dados</h2>
                <p class="mt-2">
                    O acesso da equipe a uma conta acontece só para dar suporte, com um motivo escrito, e cada acesso fica registrado (quem, quando e por quê). Você pode ver esses acessos na cópia dos seus dados.
                    Os campos mais sensíveis ficam cifrados no banco de dados: CPF/CNPJ, telefone, agência e número de conta, anotações, nome do segurado, os textos da ficha, as medidas do corpo, a meta de peso, as respostas de prontidão e quem deu a liberação, os textos da agenda de saúde, dos check-ups, dos pedidos e resultados de exames, dos acompanhamentos, do programa de atividades, das limitações físicas e da lista de médicos e profissionais, os seus objetivos de atividade, o nome das atividades que você criar e as observações do diário, os textos dos avisos e os dados lidos dos documentos que você importa.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">5. Por quanto tempo guardamos</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li><strong>Enquanto a conta existir:</strong> os dados que você cadastra ficam guardados para o app funcionar.</li>
                    <li><strong>Ao excluir a conta:</strong> todos os dados e arquivos ligados a ela são apagados, e a assinatura é cancelada. Ficam apenas as informações que a lei manda guardar (por exemplo, as de cobrança e nota fiscal, pelo prazo fiscal) e um registro sem dados pessoais de que a exclusão aconteceu.</li>
                    <li><strong>PDFs enviados para importação:</strong> o arquivo original é apagado {{ $c['source_pdf_days'] }} dias depois de a importação ser confirmada, descartada ou falhar. Os lançamentos que você confirmou continuam na sua conta.</li>
                    <li><strong>Laudos enviados para leitura:</strong> o PDF espera a sua revisão. Ao salvar, ele vai para os seus Documentos e fica enquanto você o mantiver; ao descartar, é apagado. Se você não fizer nem uma coisa nem outra, é apagado, com o que foi lido, em {{ $c['source_pdf_days'] }} dias.</li>
                    <li><strong>Pessoa acompanhada:</strong> os dados dela ficam guardados enquanto o espaço dela existir. Se a assinatura do responsável deixar de estar em vigor, o acesso fica suspenso para todos e os dados continuam guardados. Excluir a pessoa, ou a conta do responsável, apaga os dados e os arquivos dela. Quando um cuidador exclui a própria conta, ele apenas perde o acesso: os registros que fez permanecem no espaço da pessoa, sem o nome dele.</li>
                    <li><strong>Cópia dos seus dados:</strong> o arquivo para baixar fica disponível por 7 dias e depois é apagado.</li>
                    <li><strong>Contatos perdidos (para consultores e corretores):</strong> os anexos saem do servidor 12 meses depois de o contato ser marcado como perdido.</li>
                    <li><strong>Registro de acessos da equipe:</strong> guardado por 5 anos, como prova de quem acessou o quê.</li>
                    <li><strong>Cópias de segurança:</strong> fazemos cópias cifradas do banco de dados e dos arquivos, mantidas por 7 dias. Depois de uma exclusão, o dado pode continuar nessas cópias por até 7 dias, até elas serem substituídas.</li>
                    <li><strong>Registros técnicos:</strong> os registros do servidor ficam por 14 dias. Os registros de erro do sistema, que não trazem os seus dados financeiros, saem 90 dias depois de resolvidos ou ignorados e, se ficarem em aberto, depois de 180 dias.</li>
                    <li><strong>Navegadores confiáveis:</strong> a confiança num navegador, na verificação em dois passos, vale por até 30 dias (14 para consultores, corretores e administradores) ou até você removê-la em Minha conta.</li>
                </ul>
            </section>

            <section>
                <h2 class="{{ $h2 }}">6. Os seus direitos</h2>
                <p class="mt-2">Pela LGPD (art. 18), você pode, a qualquer momento:</p>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li>confirmar que tratamos os seus dados e acessá-los;</li>
                    <li>corrigi-los (você edita direto no app);</li>
                    <li><strong>baixar uma cópia</strong> em formato aberto, em Minha conta, que também serve para levar os dados a outro serviço;</li>
                    <li>pedir a anonimização, o bloqueio ou a eliminação de dados desnecessários, excessivos ou tratados em desacordo com a lei;</li>
                    <li><strong>excluir a sua conta e os seus dados</strong>, em Minha conta, com 7 dias de carência para desistir;</li>
                    <li><strong>revogar o consentimento</strong> para dados de saúde, em Minha conta;</li>
                    <li>pedir informações sobre com quem compartilhamos os dados e se opor a um tratamento;</li>
                    <li>saber o que acontece se você não der um consentimento (por exemplo, sem o consentimento de saúde, a área de Saúde fica fechada);</li>
                    <li>pedir a revisão de uma decisão tomada apenas de forma automatizada (seção 13);</li>
                    <li>reclamar à Autoridade Nacional de Proteção de Dados (ANPD).</li>
                </ul>
                <p class="mt-2">Se preferir, faça o pedido pelo e-mail @if ($c['dpo_email'])<a href="mailto:{{ $c['dpo_email'] }}" class="underline">{{ $c['dpo_email'] }}</a>@else do rodapé do site @endif e respondemos em até 15 dias.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">7. Casal e perfil compartilhado</h2>
                <p class="mt-2">
                    Em um perfil de casal, cada lançamento pode ser marcado como privado e então só quem o criou o vê. A regra vale igual para os dois.
                    O titular do perfil é quem o mantém: se o titular excluir a conta, o perfil compartilhado é apagado junto, e o app avisa isso antes de confirmar.
                </p>
                <p class="mt-2">
                    Na saúde, a marca de privado dos lançamentos não se aplica: a ficha, a agenda, os itens de cuidado, os check-ups, os pedidos e resultados de exames, os acompanhamentos, o programa de atividades e as limitações físicas de cada um são vistos pelos dois, e a lista de médicos e profissionais mostra a cada um os que atendem qualquer um dos dois.
                    <strong>Peso, altura e cintura são a exceção:</strong> cada um vê as suas medidas, e o outro só as vê se a pessoa ligar o compartilhamento.
                    Os treinos de academia (exercícios, cargas, séries e histórico), o perfil e o diário de atividades, a meta de peso, as respostas de prontidão, o plano da semana, o programa de caminhada e corrida e as mudanças feitas no treino são só de quem os registrou. Se você ligar uma fase da sua academia a um acompanhamento, quem vê o acompanhamento vê a presença do treino, e não as cargas nem as séries.
                    Os lembretes de consultas, de itens de cuidado e de check-ups vão para os dois, desde que cada um tenha dado o consentimento de saúde.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">8. Pessoa acompanhada e cuidadores</h2>
                <div class="mt-2 space-y-3">
                    <p>
                        <strong>Quem cadastra e o que declara.</strong> O responsável é quem cadastra a pessoa acompanhada e responde pela assinatura. Ao cadastrar, ele declara que a pessoa sabe e autorizou o cadastro dos dados de saúde dela
                        ou que ele é o representante legal dela (por procuração ou curatela). Sem essa declaração, a área de Saúde dessa pessoa fica fechada e ninguém recebe avisos sobre ela.
                        Quem presta uma declaração falsa responde por ela.
                    </p>
                    <p>
                        <strong>O que o cuidador vê.</strong> O cuidador é alguém que o responsável convida e que entra com a própria conta, sem precisar de assinatura nem de perfil financeiro. Ele vê apenas os dados de saúde da pessoa
                        (ficha, com as medidas do corpo, agenda de consultas e exames, itens de cuidado, check-ups, pedidos e resultados de exames, acompanhamentos, programa de atividades, limitações físicas e os médicos e profissionais que a atendem) e os documentos que o responsável liberar, além dos documentos que o próprio cuidador cadastrar. Ele <strong>nunca</strong> vê finanças, seguros, treinos de academia, diário de atividades ou qualquer outra área
                        do responsável. Consultores e corretores, mesmo vinculados ao responsável, também nunca abrem o espaço da pessoa acompanhada.
                    </p>
                    <p>
                        <strong>Leitura ou edição.</strong> O responsável escolhe, para cada cuidador, se o acesso é só de leitura ou também de edição. Quem só lê não altera nada. Quem edita cria e altera registros, mas só apaga o que ele mesmo registrou. Quem edita também pode enviar um laudo da pessoa para leitura por inteligência artificial (seção 13) e só deve fazer isso com a autorização dela ou do representante legal.
                        Cada registro mostra quem o fez (&ldquo;registrado por&rdquo;), para que todos saibam a origem da informação. Só o responsável convida, muda o nível, encerra o acesso e exclui a pessoa; o acesso encerrado deixa de valer imediatamente.
                    </p>
                    <p>
                        <strong>Avisos.</strong> Os lembretes de consultas e exames, as mudanças nelas (agendada, remarcada, cancelada, realizada ou não, e quem vai levar), os check-ups e rotinas que estão vencendo e as trocas de itens de cuidado são enviados ao responsável e aos cuidadores que ligaram esse tipo de aviso,
                        e à própria pessoa quando ela tem conta. Cada cuidador liga e desliga os avisos de consultas e os de itens separadamente; os de check-up seguem a escolha dos avisos de consultas. Quem fez a mudança não recebe o próprio aviso. Quando alguém se oferece para levar a pessoa a uma consulta, o nome dessa pessoa aparece para os participantes.
                    </p>
                    <p>
                        <strong>Quando a própria pessoa tem conta.</strong> O responsável pode convidá-la para entrar com o login dela. Os dados já cadastrados continuam no mesmo lugar, e o consentimento passa a ser dela. A partir daí ela pode ver quem tem acesso, encerrar o acesso de cada cuidador,
                        revogar o consentimento (a área de Saúde fecha e os avisos param) e apagar o espaço inteiro, com os dados e os arquivos. Nesse caso o responsável é avisado e deixa de pagar por ela.
                    </p>
                    <p>
                        <strong>Direitos da pessoa acompanhada.</strong> Os direitos da seção 6 pertencem a ela. Quando ela não tem conta, o pedido pode ser feito por ela, ou por seu representante legal, ao encarregado indicado na seção 1,
                        inclusive o de receber uma cópia dos dados dela e o de revogar o consentimento. A cópia automática em Minha conta traz os dados da conta de quem a pede; os dados de uma pessoa acompanhada sem conta são fornecidos por esse pedido ao encarregado.
                    </p>
                    <p>
                        <strong>Cobrança.</strong> Cada pessoa acompanhada soma um valor adicional à assinatura do responsável. Se a assinatura do responsável deixar de estar em vigor, o acesso à pessoa fica suspenso para todos, e os dados continuam guardados. Cuidadores não pagam.
                    </p>
                </div>
            </section>

            <section>
                <h2 class="{{ $h2 }}">9. Quem acompanha você: saúde e finanças</h2>
                <div class="mt-2 space-y-3">
                    <p>
                        Em qualquer perfil, você pode convidar alguém (um filho, um irmão, um cuidador) para acompanhar a <strong>sua própria</strong> saúde, as <strong>suas próprias</strong> finanças ou as duas coisas. Isso não exige assinatura, nem de quem convida nem de quem aceita.
                        Na saúde, a pessoa convidada vê a sua ficha, a sua agenda, os seus itens de cuidado, check-ups, pedidos e resultados de exames, acompanhamentos, programa de atividades, limitações físicas, os médicos e profissionais que atendem você e os documentos de saúde que forem seus, com acesso de leitura ou de edição, como você escolher, e vale o consentimento de saúde que você deu para si mesmo. As suas medidas do corpo só entram se você ligar o compartilhamento. Os seus treinos de academia, o seu diário de atividades, a sua meta de peso, as respostas de prontidão, o plano da semana e o seu programa de caminhada e corrida não entram.
                    </p>
                    <p>
                        <strong>Finanças são sempre somente leitura.</strong> Quem acompanha as suas finanças vê contas, cartões, lançamentos, contas a vencer e atrasadas, investimentos, dívidas, seguros e metas, e não altera nada.
                        Não vê número de conta, agência, final de cartão nem as suas observações. <strong>O que você marcou como privado nunca aparece</strong>, nem para quem acompanha, e os totais e gráficos que essa pessoa vê são calculados sem o que é privado.
                    </p>
                    <p>
                        O acesso é <strong>por pessoa</strong>, não por perfil. Em um perfil de casal, quem acompanha um dos dois não vê os dados do outro, e o nome do cônjuge nem aparece para essa pessoa. O que é conjunto (como uma conta conjunta) aparece para quem acompanha qualquer um dos dois.
                        Só você convida, muda o que a pessoa acompanha e encerra o acesso; o titular do perfil e o seu cônjuge não podem mexer nisso.
                    </p>
                    <p>
                        <strong>Avisos.</strong> Quem acompanha recebe, conforme o que escolher ligar, os lembretes das suas consultas e dos seus check-ups, o aviso de conta ou fatura que vence ou está atrasada, um resumo semanal das finanças e, se você combinar um limite de valor, o aviso de um gasto que chegue a esse limite.
                        Os avisos seguem a mesma regra do que a pessoa enxerga: nada que seja privado, e só o que é de quem ela acompanha. Quando um familiar se oferece para levar você a uma consulta, o nome dele aparece para quem acompanha a sua saúde.
                    </p>
                    <p>
                        A lista completa de quem tem acesso, desde quando, com que alcance e com que base, aparece na tela Pessoas e acessos e vai na cópia dos seus dados. Você pode encerrar qualquer acesso a qualquer momento.
                    </p>
                </div>
            </section>

            <section>
                <h2 class="{{ $h2 }}">10. Segurança</h2>
                <p class="mt-2">
                    Cada cliente só enxerga os próprios dados, com isolamento aplicado em todas as consultas. A conexão é protegida (HTTPS), as senhas são guardadas de forma irreversível,
                    campos sensíveis são cifrados, o acesso da equipe é registrado e arquivos pessoais ficam em área privada, sem endereço público.
                    Você pode ativar a verificação em dois passos, com um aplicativo autenticador, em Minha conta. Se houver um incidente de segurança que possa causar risco ou dano relevante, avisamos você e a ANPD no prazo da regulamentação.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">11. Crianças e adolescentes</h2>
                <p class="mt-2">O Cerne é destinado a maiores de 18 anos. Não coletamos dados de menores de forma intencional; se identificarmos, a conta é encerrada. Quem cadastra uma pessoa acompanhada que não decide sozinha o faz como representante legal, conforme a seção 8.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">12. Cookies e armazenamento no navegador</h2>
                <p class="mt-2">Usamos apenas o que é necessário para o app funcionar. Não usamos cookies de publicidade nem de rastreamento, e não há ferramentas de análise de terceiros no site nem no app.</p>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li><strong>Sessão e segurança:</strong> o cookie da sua sessão e o que protege os formulários contra envio forjado. Sem eles não é possível entrar.</li>
                    <li><strong>Manter conectado:</strong> se você marcar a opção ao entrar, um cookie mantém a sua sessão por até 30 dias.</li>
                    <li><strong>Confiar neste navegador:</strong> se você marcar a opção na verificação em dois passos, um cookie cifrado dispensa o código por até 30 dias (14 para consultores, corretores e administradores). Você remove cada navegador em Minha conta.</li>
                    <li><strong>Preferências:</strong> as suas preferências de aparência, como o tema e o menu lateral recolhido, ficam guardadas no seu navegador ou na sua conta.</li>
                    <li><strong>Arquivos do app:</strong> o navegador guarda cópias de arquivos estáticos (imagens, estilos e códigos da tela) para o app abrir mais rápido. Dados financeiros nunca entram nesse armazenamento.</li>
                </ul>
            </section>

            <section>
                <h2 class="{{ $h2 }}">13. Inteligência artificial e decisões automatizadas</h2>
                <p class="mt-2">
                    O Cerne usa inteligência artificial (Anthropic) para ler documentos e laudos de exames, sugerir a categoria de um gasto falado e, quando o recurso estiver disponível, responder perguntas no assistente de conversa.
                    Nenhuma decisão que afete os seus direitos é tomada apenas por esse tratamento automatizado: o que a inteligência artificial lê ou sugere só vira lançamento, baixa, cadastro ou resultado de exame depois da sua confirmação na tela,
                    e o assistente apenas consulta. Ela pode errar, por isso confira os valores. Você pode pedir a revisão de um tratamento automatizado ao encarregado indicado na seção 1.
                </p>
                <p class="mt-2">
                    Na área de Atividades e nas medidas do corpo não há inteligência artificial: o IMC e a faixa de referência, a prontidão, o plano da semana, as sugestões sobre o treino, os avisos de encaixe, a carga da semana e os passos de caminhada e corrida são regras fixas calculadas no próprio Cerne, e nada muda no seu treino sem a sua aprovação. A única restrição automática é a da prontidão (seção 2), que impede o plano e a corrida de progredir sem liberação médica: você pode atualizar as respostas ou pedir a revisão ao encarregado.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">14. Mudanças nesta política</h2>
                <p class="mt-2">Quando o texto mudar de forma que afete os seus direitos, pedimos que você leia e aceite a nova versão no próximo acesso. Guardamos a versão e a data do seu aceite.</p>
            </section>
        </div>
    </div>
</x-layouts.marketing>
