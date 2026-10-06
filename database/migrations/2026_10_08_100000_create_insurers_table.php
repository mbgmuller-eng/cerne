<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A seguradora da apólice sempre foi texto livre (insurance_policies.
 * insurer_name), sem lista, sem cor de marca e com o selo pintado por um
 * hash do nome. Esta tabela segue o padrão de banks (profile_id nulo =
 * aprovada, visível a todos; preenchido = sugestão de um cliente, só ele vê
 * até um admin aprovar) e continua sem apontar a apólice pro id daqui.
 *
 * Duas cargas iniciais, ambas só de dado:
 *  1. as seguradoras conhecidas, já aprovadas. As cores são aproximações da
 *     marca e o admin pode corrigir cada uma na tela de seguradoras;
 *  2. cada nome DISTINTO já usado em apólice que não bata com a lista (nem
 *     por apelido) entra como sugestão pendente do primeiro perfil que o
 *     usou, pra o admin revisar em vez de o cadastro nascer sem saber
 *     desses nomes.
 */
return new class extends Migration
{
    /** Nome de exibição => cor hex aproximada. */
    private const APROVADAS = [
        'Porto Seguro' => '#0072CE',
        'Allianz' => '#003781',
        'Bradesco Seguros' => '#CC092F',
        'SulAmérica' => '#F58220',
        'Tokio Marine' => '#00A651',
        'HDI' => '#00A859',
        'Mapfre' => '#D81E05',
        'Liberty Seguros' => '#1A1446',
        'Icatu Seguros' => '#003C71',
        'Azos' => '#6C2BD9',
        'MetLife' => '#0090DA',
        'Prudential' => '#0066B2',
        'Zurich' => '#2167AE',
        'Sompo' => '#E60012',
        'Youse' => '#FF3C5F',
        'Amil' => '#0072BC',
        'Unimed' => '#00995D',
        'Itaú Seguros' => '#EC7000',
        'Caixa Seguradora' => '#0033A0',
        'BB Seguros' => '#FADB14',
        'Generali' => '#C6002B',
        'Chubb' => '#2A2A2A',
    ];

    /** Mesmos apelidos de App\Models\Insurer::ALIASES (cópia: migration não depende do model). */
    private const ALIASES = [
        'porto' => 'Porto Seguro', 'porto seguros' => 'Porto Seguro', 'sul america' => 'SulAmérica',
        'bradesco' => 'Bradesco Seguros', 'bradesco seguro' => 'Bradesco Seguros', 'icatu' => 'Icatu Seguros',
        'tokio' => 'Tokio Marine', 'tokio marine seguradora' => 'Tokio Marine', 'liberty' => 'Liberty Seguros',
        'mapfre seguros' => 'Mapfre', 'zurich seguros' => 'Zurich', 'sompo seguros' => 'Sompo',
        'hdi seguros' => 'HDI', 'allianz seguros' => 'Allianz', 'azos seguros' => 'Azos',
        'metlife seguros' => 'MetLife', 'prudential do brasil' => 'Prudential', 'caixa seguros' => 'Caixa Seguradora',
        'bb seguros' => 'BB Seguros', 'itau' => 'Itaú Seguros', 'itau seguros' => 'Itaú Seguros',
    ];

    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('color_hex', 7)->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->index('profile_id');
        });

        $agora = now();

        DB::table('insurers')->insert(collect(self::APROVADAS)->map(fn (string $cor, string $nome) => [
            'id' => (string) Str::orderedUuid(),
            'profile_id' => null,
            'name' => $nome,
            'color_hex' => $cor,
            'dismissed_at' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->values()->all());

        $conhecidas = collect(self::APROVADAS)->keys()->map(fn (string $n) => $this->normalizar($n))->all();

        $pendentes = DB::table('insurance_policies')
            ->select('insurer_name', 'profile_id')
            ->whereNotNull('insurer_name')
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($p) => trim($p->insurer_name) !== '')
            ->groupBy(fn ($p) => $this->normalizar($p->insurer_name))
            ->reject(fn ($grupo, string $chave) => in_array($chave, $conhecidas, true) || isset(self::ALIASES[$chave]))
            ->map(fn ($grupo) => [
                'id' => (string) Str::orderedUuid(),
                'profile_id' => $grupo->first()->profile_id,
                'name' => trim($grupo->first()->insurer_name),
                'color_hex' => null,
                'dismissed_at' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])
            ->values()
            ->all();

        if ($pendentes !== []) {
            DB::table('insurers')->insert($pendentes);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurers');
    }

    private function normalizar(string $nome): string
    {
        return strtr(mb_strtolower(trim($nome)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
};
