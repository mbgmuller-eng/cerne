<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pessoa acompanhada e cuidadores. O perfil de cuidado é um FinancialProfile do tipo `care` (sem finanças, com um único membro sem login); quem
 * acompanha entra por `profile_caregivers`, com leitura ou edição, e só enxerga a saúde e os documentos liberados.
 */
return new class extends Migration
{
    /** Tabelas de saúde em que o cuidador (que não tem membro no perfil) precisa aparecer como autor. */
    private const HEALTH_TABLES = ['health_allergies', 'health_conditions', 'health_medications', 'health_appointments', 'health_care_items'];

    public function up(): void
    {
        Schema::create('profile_caregivers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invited_email', 190);
            $table->string('invited_name', 120);
            $table->string('access_level', 10)->default('read');
            $table->string('status', 10)->default('invited');
            // Hash do token do link (o token em claro só existe no e-mail), como nos outros convites.
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Lembrete de consulta e de troca de item: a pessoa acompanhada não lê o app, quem cuida recebe.
            $table->boolean('notify_reminders')->default(true);
            $table->timestamps();

            $table->unique(['profile_id', 'invited_email']);
            $table->unique(['profile_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('financial_profiles', function (Blueprint $table): void {
            // Declaração de quem cadastra a pessoa: ela autorizou, ou quem cadastra é o representante legal (LGPD, art. 11).
            $table->foreignUuid('care_consent_by_user_id')->nullable()->after('reference_month')->constrained('users')->nullOnDelete();
            $table->timestamp('care_consent_at')->nullable()->after('care_consent_by_user_id');
            $table->string('care_consent_basis', 30)->nullable()->after('care_consent_at');
            $table->string('care_consent_version', 20)->nullable()->after('care_consent_basis');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('shared_with_caregivers')->default(false)->after('visible_to_professional');
            $table->foreignUuid('created_by_user_id')->nullable()->after('created_by_member_id')->constrained('users')->nullOnDelete();
        });

        foreach (self::HEALTH_TABLES as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        Schema::table('health_medication_changes', function (Blueprint $table): void {
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('health_medication_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('changed_by_user_id');
        });

        foreach (self::HEALTH_TABLES as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by_user_id');
            });
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('shared_with_caregivers');
        });

        Schema::table('financial_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('care_consent_by_user_id');
            $table->dropColumn(['care_consent_at', 'care_consent_basis', 'care_consent_version']);
        });

        Schema::dropIfExists('profile_caregivers');
    }
};
