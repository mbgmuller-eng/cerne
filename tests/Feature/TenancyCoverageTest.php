<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\Subscription;
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

        // Vínculos consultor-cliente: ligam dois usuários, sem profile_id.
        ConsultantClient::class => 'liga consultor e cliente por user_id — não tem profile_id',
        ConsultantInvite::class => 'o convite existe antes de qualquer perfil ser criado',

        // Resolvidos ANTES de existir um ProfileContext ativo — ver
        // SetProfileContext::resolveProfile() e FinancialProfilePolicy::isMember().
        // Um escopo que dependesse do profile_id ativo criaria uma
        // dependência circular com o próprio código que decide qual é o
        // profile_id ativo. Cada consulta a estes models já filtra
        // profile_id explicitamente no ponto de uso.
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
