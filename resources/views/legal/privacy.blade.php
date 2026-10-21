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
                <h2 class="{{ $h2 }}">8. Segurança</h2>
                <p class="mt-2">
                    Cada cliente só enxerga os próprios dados, com isolamento aplicado em todas as consultas. A conexão é protegida (HTTPS), as senhas são guardadas de forma irreversível,
                    campos sensíveis são cifrados, o acesso da equipe é registrado e arquivos pessoais ficam em área privada, sem endereço público.
                    Se houver um incidente de segurança que possa causar risco ou dano relevante, avisamos você e a ANPD no prazo da regulamentação.
                </p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">9. Crianças e adolescentes</h2>
                <p class="mt-2">O Cerne é destinado a maiores de 18 anos. Não coletamos dados de menores de forma intencional; se identificarmos, a conta é encerrada.</p>
            </section>

            <section>
                <h2 class="{{ $h2 }}">10. Mudanças nesta política</h2>
                <p class="mt-2">Quando o texto mudar de forma que afete os seus direitos, pedimos que você leia e aceite a nova versão no próximo acesso.</p>
            </section>
        </div>
    </div>
</x-layouts.marketing>
