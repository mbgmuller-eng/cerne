@php
    $c = config('cerne.legal');
    $versao = \Carbon\CarbonImmutable::parse($c['terms_version'])->translatedFormat('d \d\e F \d\e Y');
    $h2 = 'font-display text-lg font-semibold text-slate-900 dark:text-white';
@endphp
<x-layouts.marketing title="Política de Privacidade · Cerne">
    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <div class="mb-8 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
            Rascunho: este texto ainda não passou por revisão jurídica. Não é a versão final.
        </div>

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
                    <p><strong>Documentos:</strong> os arquivos que você guarda no cofre (documentos pessoais, apólices, exames) e os PDFs que você envia para importação. Base legal: execução do contrato.</p>
                    <p>
                        <strong>Saúde (dado pessoal sensível):</strong> ficha de saúde (tipo sanguíneo, alergias, condições, medicamentos), agenda de consultas e exames, itens de cuidado e treinos de academia.
                        Só são tratados com o seu <strong>consentimento específico e destacado</strong>, pedido antes de você abrir a área de Saúde. Base legal: consentimento (LGPD, art. 11, I).
                        Você pode revogá-lo a qualquer momento em Minha conta.
                    </p>
                    <p>
                        <strong>Pessoa acompanhada (dado pessoal sensível de terceiro):</strong> quem cuida de um familiar ou de outra pessoa pode cadastrá-la no Cerne para guardar a saúde e os documentos dela
                        (nome, ficha de saúde, consultas e exames, itens de cuidado e os documentos que forem liberados). Essa pessoa pode não ter conta no Cerne. Base legal: consentimento dela ou,
                        quando ela não puder decidir sozinha, o de seu representante legal, conforme a seção 8.
                    </p>
                    <p>
                        <strong>Dados de quem é convidado:</strong> quando você convida alguém para acompanhar uma pessoa, ou para acompanhar você, guardamos o nome e o e-mail que você informou. Servem para enviar o convite
                        e para identificar o acesso na lista de quem acompanha. Base legal: execução do contrato.
                    </p>
                    <p><strong>Relação com consultor ou corretor:</strong> quando você vincula um profissional, ele passa a ver os dados financeiros e de seguros combinados com você. Dados de saúde nunca são visíveis a consultores ou corretores.</p>
                    <p><strong>Registros técnicos:</strong> endereço de IP, tipo de navegador e erros do sistema, usados para segurança e para corrigir falhas. O registro de erros não guarda os seus dados financeiros. Base legal: legítimo interesse (segurança e melhoria do serviço).</p>
                </div>
            </section>

            <section>
                <h2 class="{{ $h2 }}">3. Com quem compartilhamos</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li><strong>Processadora de pagamentos (Asaas):</strong> cobrança, Pix e emissão de nota fiscal.</li>
                    <li><strong>Leitura de documentos por inteligência artificial (Anthropic):</strong> quando você envia um PDF (extrato, fatura, apólice, relatório) para importação, o conteúdo do arquivo é enviado para a Anthropic ler e devolver os dados. Isso envolve transferência internacional (Estados Unidos). O resultado nunca vira lançamento sem a sua confirmação na tela.</li>
                    <li><strong>Envio de e-mails e notificações:</strong> o provedor de e-mail e os serviços de notificação do seu navegador, para os avisos que você ligou.</li>
                    <li><strong>Hospedagem:</strong> o provedor onde o Cerne roda guarda os dados em nosso nome.</li>
                    <li><strong>Consultor ou corretor vinculado:</strong> somente o que a seção 2 descreve, e só enquanto o vínculo estiver ativo.</li>
                    <li><strong>Cuidadores e pessoas que acompanham você:</strong> somente o que as seções 8 e 9 descrevem, e só enquanto o acesso estiver ativo. O acesso é sempre dado por você (ou pelo responsável pela pessoa acompanhada), nunca pelo Cerne por conta própria.</li>
                    <li><strong>Autoridades:</strong> quando a lei ou uma ordem judicial exigir.</li>
                </ul>
                <p class="mt-2">Não vendemos dados pessoais nem os usamos para publicidade de terceiros. As cotações de moedas que o app consulta (como CoinGecko, Binance e AwesomeAPI) não recebem nenhum dado seu.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">4. Quem da equipe do Cerne pode ver os seus dados</h2>
                <p class="mt-2">
                    O acesso da equipe a uma conta acontece só para dar suporte, com um motivo escrito, e cada acesso fica registrado (quem, quando e por quê). Você pode ver esses acessos na cópia dos seus dados.
                    Alguns campos mais sensíveis (CPF/CNPJ e o conteúdo da ficha e da agenda de saúde) ficam cifrados no banco de dados.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">5. Por quanto tempo guardamos</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li><strong>Enquanto a conta existir:</strong> os dados que você cadastra ficam guardados para o app funcionar.</li>
                    <li><strong>Ao excluir a conta:</strong> todos os dados e arquivos ligados a ela são apagados, e a assinatura é cancelada. Ficam apenas as informações que a lei manda guardar (por exemplo, as de cobrança e nota fiscal, pelo prazo fiscal) e um registro sem dados pessoais de que a exclusão aconteceu.</li>
                    <li><strong>PDFs enviados para importação:</strong> o arquivo original é apagado {{ $c['source_pdf_days'] }} dias depois de a importação ser confirmada, descartada ou falhar. Os lançamentos que você confirmou continuam na sua conta.</li>
                    <li><strong>Pessoa acompanhada:</strong> os dados dela ficam guardados enquanto o espaço dela existir. Se a assinatura do responsável deixar de estar em vigor, o acesso fica suspenso para todos e os dados continuam guardados. Excluir a pessoa, ou a conta do responsável, apaga os dados e os arquivos dela. Quando um cuidador exclui a própria conta, ele apenas perde o acesso: os registros que fez permanecem no espaço da pessoa, sem o nome dele.</li>
                    <li><strong>Cópia dos seus dados:</strong> o arquivo para baixar fica disponível por 7 dias e depois é apagado.</li>
                    <li><strong>Contatos perdidos (para consultores e corretores):</strong> os anexos saem do servidor 12 meses depois de o contato ser marcado como perdido.</li>
                    <li><strong>Registro de acessos da equipe:</strong> guardado por 5 anos, como prova de quem acessou o quê.</li>
                </ul>
            </section>

            <section>
                <h2 class="{{ $h2 }}">6. Os seus direitos</h2>
                <p class="mt-2">Pela LGPD (art. 18), você pode, a qualquer momento:</p>
                <ul class="mt-2 list-disc space-y-2 pl-5">
                    <li>confirmar que tratamos os seus dados e acessá-los;</li>
                    <li>corrigi-los (você edita direto no app);</li>
                    <li><strong>baixar uma cópia</strong> em formato aberto, em Minha conta;</li>
                    <li><strong>excluir a sua conta e os seus dados</strong>, em Minha conta, com 7 dias de carência para desistir;</li>
                    <li><strong>revogar o consentimento</strong> para dados de saúde, em Minha conta;</li>
                    <li>pedir informações sobre com quem compartilhamos os dados e se opor a um tratamento;</li>
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
                        (ficha, agenda de consultas e exames e itens de cuidado) e os documentos que o responsável liberar, além dos documentos que o próprio cuidador cadastrar. Ele <strong>nunca</strong> vê finanças, seguros, treinos ou qualquer outra área
                        do responsável. Consultores e corretores, mesmo vinculados ao responsável, também nunca abrem o espaço da pessoa acompanhada.
                    </p>
                    <p>
                        <strong>Leitura ou edição.</strong> O responsável escolhe, para cada cuidador, se o acesso é só de leitura ou também de edição. Quem só lê não altera nada. Quem edita cria e altera registros, mas só apaga o que ele mesmo registrou.
                        Cada registro mostra quem o fez (&ldquo;registrado por&rdquo;), para que todos saibam a origem da informação. Só o responsável convida, muda o nível, encerra o acesso e exclui a pessoa; o acesso encerrado deixa de valer imediatamente.
                    </p>
                    <p>
                        <strong>Avisos.</strong> Os lembretes de consultas e exames, as mudanças nelas (agendada, remarcada, cancelada, realizada ou não, e quem vai levar) e as trocas de itens de cuidado são enviados ao responsável e aos cuidadores que ligaram esse tipo de aviso,
                        e à própria pessoa quando ela tem conta. Cada cuidador liga e desliga os avisos de consultas e os de itens separadamente. Quem fez a mudança não recebe o próprio aviso. Quando alguém se oferece para levar a pessoa a uma consulta, o nome dessa pessoa aparece para os participantes.
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
                <h2 class="{{ $h2 }}">9. Quem acompanha você</h2>
                <div class="mt-2 space-y-3">
                    <p>
                        Em qualquer perfil, você pode convidar alguém (um filho, um irmão, um cuidador) para ver a <strong>sua própria</strong> saúde. Isso não exige assinatura, nem de quem convida nem de quem aceita, e vale o consentimento de saúde que
                        você deu para si mesmo. A pessoa convidada passa a ver apenas a sua ficha de saúde, a sua agenda e os documentos de saúde que forem seus, com acesso de leitura ou de edição, como você escolher.
                    </p>
                    <p>
                        O acesso é <strong>por pessoa</strong>, não por perfil. Em um perfil de casal, quem acompanha um dos dois não vê os dados do outro, e o nome do cônjuge nem aparece para essa pessoa.
                        Só você convida, muda o nível e encerra o acesso a quem acompanha você; o titular do perfil e o seu cônjuge não podem mexer nisso. Os lembretes das suas consultas chegam a quem acompanha você, conforme os avisos que essa pessoa ligou.
                    </p>
                    <p>
                        A lista completa de quem tem acesso à sua saúde, desde quando e com qual nível, aparece na tela Pessoas e acessos e vai na cópia dos seus dados. Você pode encerrar qualquer acesso a qualquer momento.
                    </p>
                </div>
            </section>

            <section>
                <h2 class="{{ $h2 }}">10. Segurança</h2>
                <p class="mt-2">
                    Cada cliente só enxerga os próprios dados, com isolamento aplicado em todas as consultas. A conexão é protegida (HTTPS), as senhas são guardadas de forma irreversível,
                    campos sensíveis são cifrados, o acesso da equipe é registrado e arquivos pessoais ficam em área privada, sem endereço público.
                    Se houver um incidente de segurança que possa causar risco ou dano relevante, avisamos você e a ANPD no prazo da regulamentação.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">11. Crianças e adolescentes</h2>
                <p class="mt-2">O Cerne é destinado a maiores de 18 anos. Não coletamos dados de menores de forma intencional; se identificarmos, a conta é encerrada. Quem cadastra uma pessoa acompanhada que não decide sozinha o faz como representante legal, conforme a seção 8.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">12. Mudanças nesta política</h2>
                <p class="mt-2">Quando o texto mudar de forma que afete os seus direitos, pedimos que você leia e aceite a nova versão no próximo acesso.</p>
            </section>
        </div>
    </div>
</x-layouts.marketing>
