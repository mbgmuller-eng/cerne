<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cerne Saúde: o pedido de exame passa a ter uma LISTA (cada exame marca data e recebe resultado por conta própria), e os resultados são
 * guardados para comparar com os próximos.
 *
 * `health_exam_requests` continua sendo o pedido ("Exames de sangue", "Avaliação cardiológica"). Os pedidos que já existiam viram um pedido com um
 * item só, com o mesmo nome e a mesma data marcada. A coluna `appointment_id` do pedido deixa de ser usada (a data é de cada item) e fica como
 * está, para uma volta de versão não perder o vínculo.
 *
 * Nome de exame, valores, conclusões e arquivo são texto cifrado: nada aqui é filtrado nem ordenado em SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_exam_requests', function (Blueprint $table): void {
            $table->string('category', 20)->nullable()->after('kind');
            $table->text('notes')->nullable()->after('requested_by');
        });

        // Um laudo: o de valores (sangue, urina) ou o de conclusão (imagem, cardiologia).
        Schema::create('health_exam_reports', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('request_id')->nullable()->constrained('health_exam_requests')->nullOnDelete();
            $table->string('kind', 10);
            $table->date('performed_on');
            $table->text('lab')->nullable();
            $table->text('doctor')->nullable();
            $table->text('exam_name')->nullable();
            $table->text('conclusion')->nullable();
            $table->text('highlight')->nullable();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $this->actors($table);
        });

        Schema::create('health_exam_values', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('report_id')->constrained('health_exam_reports')->cascadeOnDelete();
            $table->text('name');
            $table->text('value');
            $table->text('unit')->nullable();
            $table->text('reference')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $this->actors($table);
        });

        Schema::create('health_exam_items', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('request_id')->constrained('health_exam_requests')->cascadeOnDelete();
            $table->text('name');
            $table->text('detail')->nullable();
            // Vários exames podem ser feitos no mesmo compromisso. Apagar o compromisso devolve os itens para "falta agendar".
            $table->foreignUuid('appointment_id')->nullable()->constrained('health_appointments')->nullOnDelete();
            $table->foreignUuid('report_id')->nullable()->constrained('health_exam_reports')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $this->actors($table);
        });

        // Leitura do PDF por IA, aguardando a revisão da pessoa: nada vira resultado antes dela.
        Schema::create('health_exam_readings', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('request_id')->nullable()->constrained('health_exam_requests')->nullOnDelete();
            $table->string('status', 20);
            // Onde o PDF espera a revisão; vazio quer dizer que o arquivo já saiu (foi para os Documentos, foi descartado ou venceu o prazo).
            $table->string('source_path')->default('');
            $table->text('original_filename');
            $table->longText('summary')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignUuid('report_id')->nullable()->constrained('health_exam_reports')->nullOnDelete();
            $this->actors($table);
            $table->index(['profile_id', 'created_at']);
        });

        $this->backfill();
    }

    /** Cada pedido antigo vira um pedido com um item. O texto cifrado é copiado como está (mesma chave, mesma forma de cifrar). Idempotente. */
    public function backfill(): void
    {
        DB::table('health_exam_requests')->orderBy('id')->chunkById(200, function ($pedidos): void {
            foreach ($pedidos as $pedido) {
                if (DB::table('health_exam_items')->where('request_id', $pedido->id)->exists()) {
                    continue;
                }

                DB::table('health_exam_items')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'profile_id' => $pedido->profile_id,
                    'member_id' => $pedido->member_id,
                    'request_id' => $pedido->id,
                    'name' => $pedido->title,
                    'appointment_id' => $pedido->appointment_id,
                    'position' => 0,
                    'created_by_member_id' => $pedido->created_by_member_id,
                    'created_by_user_id' => $pedido->created_by_user_id,
                    'changed_by_user_id' => $pedido->changed_by_user_id,
                    'created_at' => $pedido->created_at,
                    'updated_at' => $pedido->updated_at,
                ]);
            }
        });
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }

    private function actors(Blueprint $table): void
    {
        $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
        $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('health_exam_readings');
        Schema::dropIfExists('health_exam_items');
        Schema::dropIfExists('health_exam_values');
        Schema::dropIfExists('health_exam_reports');

        Schema::table('health_exam_requests', function (Blueprint $table): void {
            $table->dropColumn(['category', 'notes']);
        });
    }
};
