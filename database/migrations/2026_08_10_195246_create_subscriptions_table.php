<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('plan', 20);
            $table->string('status', 20);
            $table->date('started_at');
            $table->date('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Identificador no gateway de pagamento — a escolha do gateway
            // ficou fora do escopo técnico da especificação.
            $table->string('external_subscription_id')->nullable()->index();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
