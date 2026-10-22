<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_appointments', function (Blueprint $table): void {
            // agendada, realizada, não realizada ou cancelada. Quem acompanha a pessoa precisa saber se a consulta aconteceu.
            $table->string('status', 12)->default('scheduled')->after('kind');
            $table->timestamp('status_at')->nullable()->after('status');
            $table->foreignUuid('status_by_user_id')->nullable()->after('status_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_by_user_id');
            $table->dropColumn(['status', 'status_at']);
        });
    }
};
