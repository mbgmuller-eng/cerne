<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta fixa só sabia ser mensal (due_day). `recurrence` generaliza pra
     * semanal e anual — due_weekday e due_month só fazem sentido para o
     * recurrence correspondente (semanal e anual, respectivamente); due_day
     * continua servindo mensal e anual.
     *
     * due_day precisa virar nullable (conta semanal não usa) — via SQL cru
     * porque MODIFY COLUMN exige doctrine/dbal, que este projeto não tem
     * (ver composer.json) e não vale a pena adicionar só por isso.
     */
    public function up(): void
    {
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->string('recurrence', 20)->default('monthly')->after('due_day');
            $table->unsignedTinyInteger('due_weekday')->nullable()->after('recurrence');
            $table->unsignedTinyInteger('due_month')->nullable()->after('due_weekday');
        });

        DB::statement('ALTER TABLE fixed_bills MODIFY due_day TINYINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fixed_bills MODIFY due_day TINYINT UNSIGNED NOT NULL');

        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'due_weekday', 'due_month']);
        });
    }
};
