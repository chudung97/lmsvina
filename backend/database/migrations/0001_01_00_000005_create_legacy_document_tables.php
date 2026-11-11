<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tài liệu PDF và quy trình ký kiểu cũ (chuỗi người ký cố định). Quy trình chuyển tay mới
 * nằm ở 2026_11_06_000001_create_document_routing, migration đó đọc dữ liệu cũ từ các bảng này.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->increments('id');
                $table->string('name');
                $table->text('file_path');
                $table->integer('owner_id')->nullable();
                $table->dateTime('uploaded_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();

                $table->index(['owner_id', 'completed_at'], 'documents_owner_completed');
                $table->foreign('owner_id', 'documents_owner_fk')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('workflow_state')) {
            Schema::create('workflow_state', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->unsignedTinyInteger('id')->primary();
                $table->integer('current_user_id');
                $table->unsignedInteger('current_document_id')->nullable();
                $table->boolean('finalized')->default(false);

                $table->foreign('current_document_id', 'workflow_state_document_fk')
                    ->references('id')->on('documents')->nullOnDelete();
                $table->foreign('current_user_id', 'workflow_state_user_fk')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('owner_workflows')) {
            Schema::create('owner_workflows', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->integer('owner_id')->primary();
                $table->unsignedInteger('current_document_id')->nullable();
                $table->boolean('finalized')->default(false);

                $table->foreign('current_document_id', 'owner_workflows_document_fk')
                    ->references('id')->on('documents')->nullOnDelete();
                $table->foreign('owner_id', 'owner_workflows_owner_fk')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('document_signers')) {
            Schema::create('document_signers', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->unsignedInteger('document_id');
                $table->integer('user_id');
                $table->unsignedInteger('sequence');

                $table->primary(['document_id', 'user_id']);
                $table->unique(['document_id', 'sequence'], 'document_signers_order');
                $table->foreign('document_id', 'document_signers_document_fk')
                    ->references('id')->on('documents')->cascadeOnDelete();
                $table->foreign('user_id', 'document_signers_user_fk')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('seal_positions')) {
            Schema::create('seal_positions', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->integer('user_id');
                $table->unsignedInteger('document_id');
                $table->unsignedInteger('page')->default(1);
                $table->double('x');
                $table->double('y');
                $table->double('width');
                $table->double('height');
                $table->double('rotation')->default(0);
                $table->dateTime('signed_at')->nullable();

                $table->primary(['user_id', 'document_id']);
                $table->foreign('document_id', 'seal_positions_document_fk')
                    ->references('id')->on('documents')->cascadeOnDelete();
                $table->foreign('user_id', 'seal_positions_user_fk')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('completed_seals')) {
            Schema::create('completed_seals', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->unsignedInteger('document_id');
                $table->integer('user_id');
                $table->string('user_name', 120);
                $table->string('seal_text', 3);
                $table->string('signature_name', 120);
                $table->char('color', 7);
                $table->unsignedInteger('page');
                $table->double('x');
                $table->double('y');
                $table->double('width');
                $table->double('height');
                $table->double('rotation')->default(0);

                $table->primary(['document_id', 'user_id']);
                $table->foreign('document_id', 'completed_seals_document_fk')
                    ->references('id')->on('documents')->cascadeOnDelete();
                $table->foreign('user_id', 'completed_seals_user_fk')->references('id')->on('users');
            });
        }

        if (! Schema::hasTable('workflow_events')) {
            Schema::create('workflow_events', function (Blueprint $table): void {
                $this->tableOptions($table);
                $table->increments('id');
                $table->text('message');
                $table->integer('owner_id')->nullable();
                $table->unsignedInteger('document_id')->nullable();
                $table->dateTime('created_at')->useCurrent();

                $table->foreign('document_id', 'workflow_events_document_fk')
                    ->references('id')->on('documents')->cascadeOnDelete();
                $table->foreign('owner_id', 'workflow_events_owner_fk')->references('id')->on('users');
            });
        }
    }

    private function tableOptions(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }

    public function down(): void
    {
        // Không xóa: documents vẫn được quy trình chuyển tay mới tham chiếu. Trên máy phát
        // triển, dùng `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
