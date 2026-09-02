<?php

namespace Database\Seeders;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\IncomeCategory;
use Illuminate\Database\Seeder;

/**
 * Taxonomia padrão do sistema (seção 13 da especificação).
 *
 * Tudo entra com profile_id NULO — são categorias compartilhadas por todos
 * os perfis, e por isso estas tabelas não usam o escopo de tenancy.
 *
 * Nenhuma categoria tem "Outros": quando falta uma subcategoria, o usuário
 * cria na hora e ela nasce vinculada ao perfil dele. Um balaio "Outros"
 * acumula tudo que não coube e depois não se analisa.
 *
 * Idempotente — pode rodar de novo sem duplicar.
 */
class TaxonomySeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const DESPESAS = [
        'Habitação' => [
            'Aluguel', 'Condomínio', 'Prestação da casa', 'Aluguel vaga', 'Diarista',
            'Luz', 'Água', 'Gás', 'Celular', 'Internet', 'Streamings', 'Terreno',
            'Manutenção', 'Móveis', 'Tv a Cabo', 'Manutenção Celular', 'Decoração',
            'Utensílios Domésticos', 'Eletrodomésticos', 'Eletroeletrônicos', 'IPTU',
        ],
        'Filhos' => [
            'Natação', 'Escola', 'Colônia de Férias', 'Festa Aniversário',
            'Lanche na escola', 'Play Kids', 'Contraturno', 'Ballet', 'Judô',
            'Tênis', 'Jogos Internet',
        ],
        'Transporte' => [
            'Prestação Carro', 'Combustível', 'Estacionamento', 'Uber', 'Seguro Carro',
            'Licenciamento', 'Multas', 'Manutenção', 'Aluguel de Carro',
            'Assinatura do carro', 'Lavagem Carro', 'Guincho Carro',
            'Sem Parar/Pedágio', 'IPVA',
        ],
        'Saúde' => [
            'Terapia', 'Plano de saúde', 'Psiquiatra', 'Gineco', 'Dermatologista',
            'Fisioterapeuta', 'Médico', 'Dentista', 'Nutricionista', 'Exames', 'Farmácia',
        ],
        'Educação' => [
            'Pós-Graduação', 'Consultoria Financeira', 'Livros', 'Cursos', 'Papelaria',
        ],
        'Alimentação' => [
            'Supermercado', 'Padaria/café/Doceria', 'Almoço/Jantar', 'Marmita',
            'Delivery', 'Restaurantes', 'Vinhos', 'Doces', 'Café',
        ],
        'Cuidados Pessoais' => ['Salão', 'Academia', 'Crossfit', 'Manicure'],
        'Lazer' => [
            'Viagens/Hoteis', 'Delivery', 'Restaurantes', 'Ingresso show', 'Correios',
            'Presentes', 'Compras diversas', 'Bar', 'Cinema', 'Sócio Torcedor',
        ],
        'Vestuário' => ['Roupas', 'Calçados', 'Meia'],
        'Pets' => [
            'Ração', 'Vacina', 'Vermífugo', 'Banho e Tosa', 'Remédios',
            'Petiscos', 'Brinquedos',
        ],
        'Financeiros' => [
            'Juros', 'IOF de Lis', 'Empréstimo', 'Tarifa Conta', 'Seg Cartão',
            'Anuidade', 'Saques Cx Eletrônico', 'Pix Diversos',
        ],
        // Necessidade "Investimento" não tinha categoria nenhuma pra cair —
        // a lista de categoria do formulário passou a filtrar por
        // necessidade (CashFlowIndex::getExpenseFormCategoriesProperty()),
        // e sem isso ela ficava vazia quando a pessoa escolhia Investimento.
        'Investimentos' => ['Aporte', 'Previdência Privada', 'Tesouro Direto', 'Compra de Ativos'],
        // Subcategorias por membro entram no onboarding do perfil —
        // é o que suporta o conceito de mesada dentro do orçamento conjunto.
        'Família' => [],
    ];

    /** Categoria cuja necessidade é fixa — só aparece pra quem escolheu essa necessidade. Ausente = qualquer necessidade. */
    private const NECESSIDADE_POR_CATEGORIA = [
        'Investimentos' => Necessity::Investment,
    ];

    /** @var list<string> */
    private const RECEITAS = [
        'Salário', 'Bônus', 'VR/VA', 'Reembolsos', 'Restituição IR',
        'Vale Combustível', 'Aluguéis', 'Dividendos', 'Participação de Lucros',
    ];

    public function run(): void
    {
        $ordem = 0;

        foreach (self::DESPESAS as $categoria => $subcategorias) {
            // withoutTaxonomyScope: o seeder roda sem perfil ativo e precisa
            // enxergar o que já existe para não duplicar.
            $cat = ExpenseCategory::withoutTaxonomyScope()->firstOrCreate(
                ['profile_id' => null, 'name' => $categoria],
                [
                    'is_default' => true, 'is_active' => true, 'sort_order' => $ordem++,
                    'necessity' => self::NECESSIDADE_POR_CATEGORIA[$categoria] ?? null,
                ],
            );

            $ordemSub = 0;
            foreach ($subcategorias as $sub) {
                ExpenseSubcategory::withoutTaxonomyScope()->firstOrCreate(
                    ['category_id' => $cat->id, 'profile_id' => null, 'name' => $sub],
                    ['is_customizada' => false, 'is_active' => true, 'sort_order' => $ordemSub++],
                );
            }
        }

        $ordem = 0;
        foreach (self::RECEITAS as $receita) {
            IncomeCategory::withoutTaxonomyScope()->firstOrCreate(
                ['profile_id' => null, 'name' => $receita],
                ['is_default' => true, 'is_active' => true, 'sort_order' => $ordem++],
            );
        }

        $this->command?->info(sprintf(
            'Taxonomia: %d categorias de despesa, %d subcategorias, %d categorias de receita.',
            ExpenseCategory::withoutTaxonomyScope()->whereNull('profile_id')->count(),
            ExpenseSubcategory::withoutTaxonomyScope()->whereNull('profile_id')->count(),
            IncomeCategory::withoutTaxonomyScope()->whereNull('profile_id')->count(),
        ));
    }
}
