<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carteira recomendada por perfil de investidor deixa de ser digitada à
 * mão (não existe tela pra isso, só seed/teste faziam isso direto no
 * banco) e vira uma regra fixa por tipo (ver InvestorType::
 * recommendedAllocations()) — a mesma pra todo cliente. Esta migration
 * sincroniza quem já tem perfil de investidor cadastrado; daqui pra
 * frente, InvestmentsIndex::saveInvestorProfile() cuida disso sozinho a
 * cada vez que o perfil é salvo.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const PERCENTUAIS = [
        'conservative' => [
            'fixed_income' => '45.00', 'funds' => '25.00', 'equities_fiis' => '15.00',
            'digital_assets' => '5.00', 'fx_currencies' => '5.00', 'etfs' => '5.00',
        ],
        'moderate' => [
            'fixed_income' => '35.00', 'funds' => '20.00', 'equities_fiis' => '20.00',
            'digital_assets' => '7.50', 'fx_currencies' => '7.50', 'etfs' => '10.00',
        ],
        'aggressive' => [
            'fixed_income' => '25.00', 'funds' => '15.00', 'equities_fiis' => '25.00',
            'digital_assets' => '10.00', 'fx_currencies' => '10.00', 'etfs' => '15.00',
        ],
    ];

    public function up(): void
    {
        $agora = now();

        DB::table('investor_profiles')
            ->whereNotNull('investor_type')
            ->orderBy('id')
            ->get(['id', 'profile_id', 'investor_type'])
            ->each(function (object $perfil) use ($agora): void {
                $tabela = self::PERCENTUAIS[$perfil->investor_type] ?? null;

                if ($tabela === null) {
                    return;
                }

                foreach ($tabela as $classe => $percentual) {
                    $existente = DB::table('recommended_allocations')
                        ->where('investor_profile_id', $perfil->id)
                        ->where('asset_class', $classe)
                        ->first();

                    if ($existente !== null) {
                        DB::table('recommended_allocations')->where('id', $existente->id)->update([
                            'target_percentage' => $percentual,
                            'updated_at' => $agora,
                        ]);

                        continue;
                    }

                    DB::table('recommended_allocations')->insert([
                        'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                        'profile_id' => $perfil->profile_id,
                        'investor_profile_id' => $perfil->id,
                        'asset_class' => $classe,
                        'target_percentage' => $percentual,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                }

                // Categoria que sobrou de uma configuração manual antiga
                // (ex.: "international", que a regra nova não usa) não
                // pode continuar contando no total — senão a soma passa
                // de 100% e allocationIsValid() nunca mais fecha.
                DB::table('recommended_allocations')
                    ->where('investor_profile_id', $perfil->id)
                    ->whereNotIn('asset_class', array_keys($tabela))
                    ->delete();
            });
    }

    public function down(): void
    {
        // Dado de negócio (a carteira recomendada em si), não estrutura —
        // reverter apagaria uma configuração que pode ter sido ajustada
        // depois desta migration. Sem down de propósito.
    }
};
