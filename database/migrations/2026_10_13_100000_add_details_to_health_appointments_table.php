<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consulta detalhada (sugestão de quem testa o app): quem atende, onde fica, como
 * falar com o local e quem fez o agendamento — o que costuma faltar na hora de
 * remarcar ou de chegar lá. `location` (já existia) passa a ser o nome do
 * estabelecimento; o endereço e o telefone ficam à parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->string('professional_name', 120)->nullable()->after('title');
            $table->string('specialty', 80)->nullable()->after('professional_name');
            $table->string('address', 200)->nullable()->after('location');
            $table->string('phone', 30)->nullable()->after('address');
            // Quem da família agendou, e com quem (secretária ou atendente) — texto livre.
            $table->string('booked_by_name', 120)->nullable()->after('phone');
            $table->string('booked_with_name', 120)->nullable()->after('booked_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->dropColumn(['professional_name', 'specialty', 'address', 'phone', 'booked_by_name', 'booked_with_name']);
        });
    }
};
