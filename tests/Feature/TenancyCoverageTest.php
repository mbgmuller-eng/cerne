<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\BillingDetail;
use App\Models\AssetFeeAgreement;
use App\Models\CommissionRule;
use App\Models\CurrencyRate;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\DebtCommission;
use App\Models\DataExport;
use App\Models\ErrorLog;
use App\Models\ImpersonationLog;
use App\Models\SecurityIncident;
use App\Models\EngagementPlanTemplate;
use App\Models\EngagementReminder;
use App\Models\FeeAgreement;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\GymExerciseSuggestion;
use App\Models\InsurancePolicyRenewal;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAlertDigest;
use App\Models\LeadAttachment;
use App\Models\LeadQuote;
use App\Models\LeadSuggestionDismissal;
use App\Models\PartnerInvite;
use App\Models\ProfessionalInvite;
use App\Models\PolicyCommission;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionNotice;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use Tests\TestCase;

/**
 * Varredura estrutural do CLAUDE.md, regra 1: todo model de domínio precisa
 * de BelongsToProfile ou BelongsToProfileOrShared, porque o MySQL não tem
 * Row Level Security — o isolamento entre clientes é só o que este escopo
 * global garante.
 *
 * Se este teste falhar porque você criou um model novo, a resposta quase
 * sempre é adicionar a trait — não estender a lista de exceções abaixo.
 * Cada exceção existente tem o motivo escrito ao lado dela; se você não
 * entende por que um model é exceção, não é seguro assumir que o seu também é.
 */
class TenancyCoverageTest extends TestCase
{
    /** @var array<class-string, string> */
    private const EXEMPT = [
        // O tenant raiz: nada isola o perfil "de si mesmo".
        FinancialProfile::class => 'é o próprio tenant — todo isolamento é definido EM RELAÇÃO a ele, não por ele',

        // Identidade do usuário, não dado de um perfil financeiro.
        User::class => 'é a conta que acessa perfis, não um dado que pertence a um perfil',
        Subscription::class => 'assinatura é do usuário (user_id) — não existe por perfil',
        BillingDetail::class => 'dados fiscais de quem paga a assinatura (user_id) — o profissional paga sem ter perfil financeiro algum, então não existe por perfil',
        SubscriptionCharge::class => 'cobrança mensal do Pix Automático da assinatura do usuário (subscription_id) — não existe por perfil; sustenta o índice único por ciclo',
        SubscriptionNotice::class => 'registro de aviso de cobrança já enviado — sustenta um índice único (assinatura, tipo, data), pertence à assinatura do usuário, não a um perfil',
        SubscriptionWebhookEvent::class => 'log de idempotência do webhook da Asaas — só existe pra sustentar um índice único em asaas_event_id, nunca consultado por perfil nenhum',

        // Metodologia do consultor: não é dado de cliente nem de perfil algum; o escopo do próprio model restringe ao consultor logado.
        EngagementPlanTemplate::class => 'modelo de passo a passo do consultor (consultant_id) — metodologia dele, sem perfil; o escopo do model só deixa o próprio consultor ver',

        // Registro técnico do envio de lembretes do acompanhamento.
        EngagementReminder::class => 'lembrete do acompanhamento já enviado a um usuário (user_id): só sustenta o índice único (usuário, assunto, tipo), nunca é consultado por perfil',

        // Treino é dado de Saúde: a fila de sugestões ao catálogo é anônima por desenho.
        GymExerciseSuggestion::class => 'fila anônima de sugestões de exercício ao catálogo — de propósito NÃO guarda perfil, membro nem usuário (treino é dado de Saúde); só nome, grupo, tipo e contador',

        // Renda do profissional: é dele (professional_id) e de mais ninguém, nem do cliente nem do outro profissional do cliente.
        IncomeEntry::class => 'recebimento do profissional (professional_id) — sigiloso: nem o cliente nem outro profissional do mesmo cliente podem ver; o profile_id é só o cliente de origem, sem escopo de perfil',
        CommissionRule::class => 'regra de comissionamento do profissional (professional_id) — sigilosa como o resto dos recebimentos; não existe por perfil',
        PolicyCommission::class => 'liga o profissional a uma apólice em que ele tem comissão (professional_id) — sigiloso; a apólice em si segue isolada por perfil',
        AssetFeeAgreement::class => 'percentual sobre patrimônio que o consultor cobra de um cliente (professional_id) — mesmo sigilo de IncomeEntry; o profile_id aponta o cliente, não isola o dado',
        DebtCommission::class => 'liga o consultor a um consórcio de cliente em que ele tem comissão (professional_id) — sigiloso; o consórcio em si segue isolado por perfil',
        FeeAgreement::class => 'honorário que o profissional combinou com um cliente (professional_id) — mesmo sigilo de IncomeEntry; o profile_id aponta o cliente, não isola o dado',

        // Privacidade (LGPD): registros da plataforma sobre a conta do usuário, sem perfil.
        DataExport::class => 'cópia dos dados pedida pelo próprio usuário (user_id) — pertence à conta, não a um perfil; o download confere o dono',
        ImpersonationLog::class => 'registro de quando um administrador entrou em uma conta (admin_id, target_user_id) — só o administrador lê e sobrevive à conta, por isso não tem perfil',
        SecurityIncident::class => 'registro de incidente de segurança da plataforma — só o administrador lê; não pertence a um perfil',

        // Operação da plataforma, só do administrador.
        ErrorLog::class => 'erro da plataforma agrupado por classe, arquivo e linha — acompanhamento técnico que só o administrador lê; de propósito não guarda dado de cliente nem perfil (mensagem limpa, rota em padrão, usuário só por id)',

        // Dado público de mercado, igual para todo mundo.
        CurrencyRate::class => 'cotação diária de USDT, BTC e ETH em reais — dado público de mercado, sem dono; a rotina escreve e o preço dos investimentos lê',

        // Vínculos consultor-cliente: ligam dois usuários, sem profile_id.
        ConsultantClient::class => 'liga consultor e cliente por user_id — não tem profile_id',
        ConsultantInvite::class => 'o convite existe antes de qualquer perfil ser criado',
        Lead::class => 'contato que ainda não é cliente — existe antes de qualquer perfil, mesmo raciocínio de ConsultantInvite; pertence ao consultor (consultant_id)',
        LeadActivity::class => 'segue o Lead — nunca consultada fora do escopo de um lead_id já resolvido',
        LeadAlertDigest::class => 'registro do resumo diário de leads já enviado a um profissional (consultant_id): só sustenta o índice único (profissional, dia), nunca é consultado por perfil',
        LeadSuggestionDismissal::class => 'sugestão de venda cruzada que o profissional dispensou (consultant_id): só sustenta o índice único (profissional, chave), nunca é consultada por perfil',
        LeadQuote::class => 'cotação de um Lead — sem profile_id (o contato ainda não é cliente); só é lida a partir de um lead já resolvido por consultant_id, mesmo raciocínio de LeadActivity',
        LeadAttachment::class => 'arquivo de um Lead — sem profile_id; o controlador confere lead.consultant_id antes de abrir (LeadAttachmentController) e o serviço só mexe nos de um lead já resolvido',
        InsurancePolicyRenewal::class => 'segue a InsurancePolicy — sem profile_id próprio, nunca consultada fora do escopo de um insurance_policy_id já resolvido (mesmo raciocínio de LeadActivity)',
        PartnerInvite::class => 'consultado pelo consultor sobre um perfil que não é o contexto ativo dele — filtra profile_id explicitamente, mesmo raciocínio de ConsultantInvite',
        ProfessionalInvite::class => 'convite de conta profissional (Consultor/Corretor) — a conta que nasce dele não tem perfil próprio nenhum, mesmo raciocínio de ConsultantInvite',

        // Resolvidos ANTES de existir um ProfileContext ativo — ver
        // SetProfileContext::resolveProfile() e FinancialProfilePolicy::isMember().
        // Um escopo que dependesse do profile_id ativo criaria uma
        // dependência circular com o próprio código que decide qual é o
        // profile_id ativo. Cada consulta a estes models já filtra
        // profile_id explicitamente no ponto de uso.
        ProfileCaregiver::class => 'decide se alguém abre o perfil de uma pessoa acompanhada (como ProfileMember, é consultado ANTES de existir contexto); o cuidador nunca vê dado fora da lista de permitidos do ProfileScope',
        ProfileMember::class => 'usado para RESOLVER o perfil ativo — não pode depender do contexto que ele ajuda a formar',
    ];

    public function test_todo_model_de_dominio_usa_belongs_to_profile(): void
    {
        $semCobertura = [];

        foreach ($this->domainModelClasses() as $class) {
            if (array_key_exists($class, self::EXEMPT)) {
                continue;
            }

            $traits = class_uses_recursive($class);

            $coberto = in_array(BelongsToProfile::class, $traits, true)
                || in_array(BelongsToProfileOrShared::class, $traits, true);

            if (! $coberto) {
                $semCobertura[] = $class;
            }
        }

        self::assertSame(
            [],
            $semCobertura,
            "Estes models não usam BelongsToProfile nem BelongsToProfileOrShared:\n  "
                .implode("\n  ", $semCobertura)
                ."\n\nSe são dado de perfil, adicione a trait. Se são exceção legítima, "
                .'documente o motivo em '.self::class.'::EXEMPT.'
        );
    }

    /** A lista de exceções não pode citar um model que não existe mais. */
    public function test_lista_de_excecoes_nao_tem_entrada_morta(): void
    {
        $existentes = $this->domainModelClasses();

        foreach (array_keys(self::EXEMPT) as $class) {
            self::assertContains(
                $class,
                $existentes,
                "{$class} está na lista de exceções mas não existe mais em app/Models — remova a entrada."
            );
        }
    }

    /** @return list<class-string> */
    private function domainModelClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/*.php')) as $arquivo) {
            $classes[] = 'App\\Models\\'.pathinfo($arquivo, PATHINFO_FILENAME);
        }

        return $classes;
    }
}
