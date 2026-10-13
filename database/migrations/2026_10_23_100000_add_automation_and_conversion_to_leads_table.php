<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads que o Cerne cria a partir da carteira (renovação de apólice, venda cruzada para um cliente): guardam de
     * qual cliente e de qual apólice vieram, e uma chave de automação com índice único, que é o que impede o cron de
     * criar o mesmo lead duas vezes. converted_at marca quando o lead virou "Emitida", para medir o tempo de venda.
     *
     * As chaves para perfil e apólice só soltam o vínculo ao apagar (nullOnDelete): o lead é do profissional e não
     * pode sumir porque o cliente foi excluído.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignUuid('client_profile_id')->nullable()->after('consultant_id')->constrained('financial_profiles')->nullOnDelete();
            $table->foreignUuid('source_policy_id')->nullable()->after('client_profile_id')->constrained('insurance_policies')->nullOnDelete();
            $table->string('automation_key', 120)->nullable()->after('source_policy_id');
            $table->timestamp('converted_at')->nullable()->after('lost_at');

            $table->unique(['consultant_id', 'automation_key'], 'leads_consultant_automation_unique');
        });

        // Os já convertidos não guardaram a data: a última alteração é a melhor aproximação disponível.
        DB::table('leads')->where('stage', 'converted')->whereNull('converted_at')->update(['converted_at' => DB::raw('updated_at')]);

        // "Não me mostre mais esta sugestão" (venda cruzada). Só sustenta o índice único (profissional + chave).
        Schema::create('lead_suggestion_dismissals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->string('suggestion_key', 120);
            $table->timestamps();

            $table->unique(['consultant_id', 'suggestion_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_suggestion_dismissals');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_consultant_automation_unique');
            $table->dropForeign(['client_profile_id']);
            $table->dropForeign(['source_policy_id']);
            $table->dropColumn(['client_profile_id', 'source_policy_id', 'automation_key', 'converted_at']);
        });
    }
};
