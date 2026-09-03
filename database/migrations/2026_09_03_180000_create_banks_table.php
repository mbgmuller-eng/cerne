<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Antes disso, o banco era digitado livre (bank_name já era string comum
 * em bank_accounts/credit_cards) mas a lista de sugestão + cor de marca
 * vinha de uma constante PHP (App\Support\KnownBanks) — só mudava com
 * deploy. Esta tabela usa o mesmo padrão de expense_categories
 * (profile_id nulo = aprovado, visível a todos; preenchido = sugestão de
 * um cliente, só ele vê até um admin aprovar), pra dar um caminho de
 * "virar oficial" sem precisar mexer em código.
 */
return new class extends Migration
{
    /** Nome de exibição => cor hex — os mesmos 29 que viviam em KnownBanks::LIST. */
    private const BANCOS_APROVADOS = [
        'Banco do Brasil' => '#FADB14',
        'Itaú' => '#EC7000',
        'Bradesco' => '#CC092F',
        'Santander' => '#EC0000',
        'Caixa Econômica Federal' => '#0033A0',
        'Nubank' => '#8A05BE',
        'Banco Inter' => '#FF7A00',
        'C6 Bank' => '#242424',
        'BTG Pactual' => '#001B48',
        'XP Investimentos' => '#000000',
        'PagBank' => '#00A868',
        'Sicoob' => '#00A651',
        'Sicredi' => '#7AB800',
        'Banco Original' => '#00A65E',
        'Neon' => '#00AAFF',
        'Banco Pan' => '#F58220',
        'Mercado Pago' => '#00B1EA',
        'PicPay' => '#21C25E',
        'Banco Safra' => '#003865',
        'BMG' => '#F26522',
        'Banco BV' => '#FF5500',
        'Banrisul' => '#0066B3',
        'Banco Sofisa' => '#E4032E',
        'Will Bank' => '#7B2FF7',
        'Next' => '#00E09E',
        'Banco Daycoval' => '#003C71',
        'Banco Modal' => '#0B0B0B',
        'Agibank' => '#FDB913',
        'Banco Master' => '#0B1F3A',
    ];

    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('color_hex', 7)->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index('profile_id');
        });

        $agora = now();

        DB::table('banks')->insert(collect(self::BANCOS_APROVADOS)->map(fn (string $cor, string $nome) => [
            'id' => (string) Str::orderedUuid(),
            'profile_id' => null,
            'name' => $nome,
            'color_hex' => $cor,
            'dismissed_at' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
