<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_appointments', function (Blueprint $table): void {
            // Quem vai levar a pessoa à consulta (um dos que cuidam dela). Nulo = ninguém definido ainda.
            $table->foreignUuid('escort_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('profile_caregivers', function (Blueprint $table): void {
            // `notify_reminders` passa a valer só para consultas e exames; a troca de itens de cuidado tem a sua própria chave.
            $table->boolean('notify_care_items')->default(true)->after('notify_reminders');
        });
    }

    public function down(): void
    {
        Schema::table('profile_caregivers', function (Blueprint $table): void {
            $table->dropColumn('notify_care_items');
        });

        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('escort_user_id');
        });
    }
};
