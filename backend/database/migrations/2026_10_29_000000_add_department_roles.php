<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department_roles')) {
            Schema::create('department_roles', function (Blueprint $table): void {
                $table->char('id', 36)->collation('utf8mb4_0900_ai_ci')->primary();
                $table->char('department_id', 36)->collation('utf8mb4_0900_ai_ci');
                $table->char('user_id', 36)->collation('utf8mb4_0900_ai_ci');
                $table->string('role', 40);
                $table->unsignedTinyInteger('level')->default(1);
                $table->char('parent_user_id', 36)->collation('utf8mb4_0900_ai_ci')->nullable();
                $table->timestamps();
                $table->unique(['department_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_roles');
    }
};
