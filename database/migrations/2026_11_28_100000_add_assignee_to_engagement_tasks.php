<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Num casal "Cliente" são duas pessoas: a tarefa pode ser de uma delas. Vazio = dos dois (como sempre foi).
        Schema::table('engagement_tasks', function (Blueprint $table): void {
            $table->char('assignee_member_id', 36)->nullable()->after('owner');
            $table->foreign('assignee_member_id')->references('id')->on('profile_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('engagement_tasks', function (Blueprint $table): void {
            $table->dropForeign(['assignee_member_id']);
            $table->dropColumn('assignee_member_id');
        });
    }
};
