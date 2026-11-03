<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O acesso de um familiar passa a dizer O QUE ele vê: saúde (como sempre foi) e/ou finanças em leitura. Todo acesso que já existe
        // continua só de saúde.
        Schema::table('profile_caregivers', function (Blueprint $table) {
            $table->boolean('can_view_health')->default(true)->after('access_level');
            $table->boolean('can_view_finance')->default(false)->after('can_view_health');
            $table->boolean('notify_finance')->default(true)->after('notify_care_items');
            $table->boolean('notify_weekly')->default(true)->after('notify_finance');
            $table->boolean('notify_alerts')->default(true)->after('notify_weekly');
            // Convite assistido: quando o consultor monta o acesso em nome da pessoa, fica registrado quem, quando e com que base.
            $table->string('grant_basis', 20)->nullable()->after('invited_by_user_id');
            $table->foreignUuid('granted_by_user_id')->nullable()->after('grant_basis')->constrained('users')->nullOnDelete();
            $table->timestamp('granted_declared_at')->nullable()->after('granted_by_user_id');
        });

        // Limite de gasto que avisa a família (nulo = desligado).
        Schema::table('financial_profiles', function (Blueprint $table) {
            $table->decimal('family_alert_threshold', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_profiles', function (Blueprint $table) {
            $table->dropColumn('family_alert_threshold');
        });

        Schema::table('profile_caregivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by_user_id');
            $table->dropColumn(['can_view_health', 'can_view_finance', 'notify_finance', 'notify_weekly', 'notify_alerts', 'grant_basis', 'granted_declared_at']);
        });
    }
};