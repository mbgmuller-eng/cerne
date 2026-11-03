<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToProfile;
use App\Support\PrivacyGovernedModels;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * Um model FILHO de um model com privacidade do casal (fatura de cartão, vencimento de conta fixa, ocorrência de receita...) herda a privacidade do pai,
 * mas não a filtra sozinho. Quem soma ou lista o filho direto (painel, avisos, telas) entregaria o que o pai marcou como privado. Este teste acha todo filho
 * de um model governado que NÃO tem proteção nenhuma, e trava a lista dos que são lidos só pela relação do pai (por isso não precisam de escopo).
 * Model novo na lista é decisão de privacidade: dê a ele o escopo (ParentPrivacyScope) ou justifique aqui.
 */
class ChildPrivacyCoverageTest extends TestCase
{
    /** Lidos sempre a partir do pai já filtrado (relação) e nunca somados ou listados direto. Justifique cada um. */
    private const READ_THROUGH_PARENT = [
        'InstallmentGroup',        // relação de ExpenseRecord; só serve para agrupar parcelas de uma compra
        'InsurancePolicyRevision', // histórico lido por $apolice->revisions
        'InvestmentSnapshot',      // lido sempre com whereIn(investment_id) dos investimentos já visíveis
        'ExpenseCategorizationRule', // configuração: o alvo (conta fixa) é lido pela relação já filtrada e a tela trata o nulo
        'IncomeCategorizationRule',  // idem, para a receita recorrente
        'PaymentMethod',           // model sem nenhum uso nas telas (o PaymentMethod de cobrança é um enum)
    ];

    /** Protegem o filho pelo pai ou por regra própria. */
    private const PROTECTING_SCOPES = [
        \App\Models\Scopes\MemberPrivacyScope::class,
        \App\Models\Scopes\ParentPrivacyScope::class,
        \App\Models\Scopes\InheritedInvestmentPrivacyScope::class,
        \App\Models\Scopes\UploadPrivacyScope::class,
        \App\Models\Scopes\DocumentVisibilityScope::class, // Document tem a regra própria de visibilidade (categoria e pessoa)
    ];

    public function test_todo_filho_de_model_com_privacidade_tem_escopo_ou_esta_justificado_na_lista(): void
    {
        $pais = array_map('class_basename', PrivacyGovernedModels::ALL);
        $semProtecao = [];

        foreach (glob(app_path('Models/*.php')) as $arquivo) {
            $classe = 'App\\Models\\'.pathinfo($arquivo, PATHINFO_FILENAME);

            if (! is_subclass_of($classe, Model::class) || ! in_array(BelongsToProfile::class, class_uses_recursive($classe), true) || in_array(class_basename($classe), $pais, true)) {
                continue;
            }

            if (! preg_match_all('/belongsTo\(\s*(\w+)::class/', (string) file_get_contents($arquivo), $achados) || array_intersect($achados[1], $pais) === []) {
                continue;
            }

            $escopos = array_keys((new $classe)->getGlobalScopes());

            if (array_intersect($escopos, self::PROTECTING_SCOPES) === []) {
                $semProtecao[] = class_basename($classe);
            }
        }

        sort($semProtecao);
        $esperado = self::READ_THROUGH_PARENT;
        sort($esperado);

        $this->assertSame($esperado, $semProtecao, 'Há um model filho de um model com privacidade sem escopo. Se a tela o soma ou lista direto, dê a ele o ParentPrivacyScope; se é lido só pelo pai, justifique na lista do teste.');
    }
}
