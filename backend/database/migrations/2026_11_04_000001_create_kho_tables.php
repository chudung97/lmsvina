<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Quản lý kho HR-GA (VPP, vật tư máy in, đồng phục, bảo hộ lao động).
 *
 * Mọi bảng dùng tiền tố kho_ và không thay đổi bảng hiện có. Các cột tham chiếu
 * users/departments chỉ lưu id (không tạo khóa ngoại) để thao tác trên users và
 * departments của LMS/Workdesk không bị ràng buộc bởi dữ liệu kho.
 *
 * Chạy riêng file này từ thư mục backend:
 * php artisan migrate --path=database/migrations/2026_11_04_000001_create_kho_tables.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        $departmentColumn = function (Blueprint $table, string $name) {
            $column = $table->char($name, 36);
            if (DB::getDriverName() === 'mysql') {
                $column->collation('utf8mb4_0900_ai_ci');
            }

            return $column;
        };

        if (! Schema::hasTable('kho_lock')) {
            Schema::create('kho_lock', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
            });
        }
        DB::table('kho_lock')->insertOrIgnore(['id' => 1]);

        if (! Schema::hasTable('kho_staff')) {
            Schema::create('kho_staff', function (Blueprint $table): void {
                $table->integer('user_id')->primary();
                $table->string('staff_role', 20);
                $table->integer('assigned_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('kho_settings')) {
            Schema::create('kho_settings', function (Blueprint $table): void {
                $table->string('setting_key', 40)->primary();
                $table->text('setting_value');
                $table->timestamp('updated_at')->nullable();
            });
        }
        DB::table('kho_settings')->insertOrIgnore([
            'setting_key' => 'scheduleStart',
            'setting_value' => json_encode(Carbon::now('Asia/Ho_Chi_Minh')->toDateString()),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('kho_families')) {
            Schema::create('kho_families', function (Blueprint $table): void {
                $table->string('code', 40)->primary();
                $table->string('name', 160);
                $table->string('size_type', 10)->default('none');
            });
        }

        if (! Schema::hasTable('kho_policies')) {
            Schema::create('kho_policies', function (Blueprint $table): void {
                $table->string('target_group', 80);
                $table->string('family_code', 40);
                $table->primary(['target_group', 'family_code']);
                $table->foreign('family_code')->references('code')->on('kho_families')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('kho_department_groups')) {
            Schema::create('kho_department_groups', function (Blueprint $table) use ($departmentColumn): void {
                $departmentColumn($table, 'department_id')->primary();
                $table->string('target_group', 80);
            });
        }

        if (! Schema::hasTable('kho_employee_sizes')) {
            Schema::create('kho_employee_sizes', function (Blueprint $table): void {
                $table->integer('user_id')->primary();
                $table->string('shirt_size', 10)->default('');
                $table->string('pants_size', 10)->default('');
                $table->string('shoe_size', 10)->default('');
                $table->integer('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('kho_items')) {
            Schema::create('kho_items', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code', 60)->unique();
                $table->string('name', 160);
                $table->string('item_group', 40);
                $table->string('unit', 20);
                $table->string('color', 60)->default('');
                $table->string('size', 20)->default('');
                $table->string('spec', 160)->default('');
                $table->string('supplier', 160)->default('');
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->unsignedInteger('min_qty')->default(0);
                $table->unsignedInteger('max_qty')->default(0);
                $table->unsignedInteger('lead_days')->default(7);
                $table->unsignedInteger('cycle_months')->default(0);
                $table->unsignedInteger('qty_per_person')->default(0);
                $table->unsignedInteger('moq')->default(1);
                $table->unsignedInteger('opening_qty')->default(0);
                $table->string('family_code', 40)->default('');
                $table->string('note', 500)->default('');
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index('family_code', 'kho_items_family_index');
            });
        }

        if (! Schema::hasTable('kho_purchase_orders')) {
            Schema::create('kho_purchase_orders', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('po_no', 20)->unique();
                $table->date('order_date');
                $table->unsignedInteger('item_id');
                $table->unsignedInteger('quantity');
                $table->string('status', 20)->default('pending_approval');
                $table->string('order_type', 20)->default('periodic');
                $table->string('note', 500)->default('');
                $table->integer('created_by')->nullable();
                $table->integer('approved_by')->nullable();
                $table->timestamps();
                $table->index(['item_id', 'status'], 'kho_purchase_orders_item_status_index');
                $table->foreign('item_id')->references('id')->on('kho_items');
            });
        }

        if (! Schema::hasTable('kho_receipts')) {
            Schema::create('kho_receipts', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('voucher_no', 30);
                $table->date('receipt_date');
                $table->char('receipt_time', 8);
                $table->unsignedInteger('item_id');
                $table->unsignedInteger('quantity');
                $table->string('receipt_type', 30);
                $table->unsignedInteger('purchase_order_id')->nullable();
                $table->string('status', 20)->default('received');
                $table->string('invoice_no', 60)->default('');
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->string('note', 500)->default('');
                $table->string('receiver_name', 120)->default('');
                $table->integer('created_by')->nullable();
                $table->integer('cancelled_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->timestamps();
                $table->index('voucher_no', 'kho_receipts_voucher_index');
                $table->index('receipt_date', 'kho_receipts_date_index');
                $table->index(['item_id', 'status'], 'kho_receipts_item_status_index');
                $table->foreign('item_id')->references('id')->on('kho_items');
                $table->foreign('purchase_order_id')->references('id')->on('kho_purchase_orders');
            });
        }

        if (! Schema::hasTable('kho_issues')) {
            Schema::create('kho_issues', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('voucher_no', 30);
                $table->date('issue_date')->nullable();
                $table->char('issue_time', 8)->nullable();
                $table->integer('recipient_user_id');
                $table->unsignedInteger('item_id');
                $table->unsignedInteger('quantity');
                $table->string('purpose', 30);
                $table->string('status', 20)->default('draft');
                $table->string('note', 1000)->default('');
                $table->string('giver_name', 120)->default('');
                $table->integer('created_by')->nullable();
                $table->integer('confirmed_by')->nullable();
                $table->integer('cancelled_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->timestamps();
                $table->index('voucher_no', 'kho_issues_voucher_index');
                $table->index('recipient_user_id', 'kho_issues_recipient_index');
                $table->index(['item_id', 'status'], 'kho_issues_item_status_index');
                $table->index('issue_date', 'kho_issues_date_index');
                $table->foreign('item_id')->references('id')->on('kho_items');
            });
        }

        if (! Schema::hasTable('kho_reminders')) {
            Schema::create('kho_reminders', function (Blueprint $table): void {
                $table->string('reminder_key', 120)->primary();
                $table->date('reminded_on');
                $table->string('result', 160)->default('');
                $table->integer('reminded_by')->nullable();
            });
        }

        if (! Schema::hasTable('kho_budgets')) {
            Schema::create('kho_budgets', function (Blueprint $table): void {
                $table->unsignedSmallInteger('budget_year');
                $table->unsignedTinyInteger('budget_month');
                $table->decimal('amount', 15, 2)->default(0);
                $table->primary(['budget_year', 'budget_month']);
            });
        }

        if (! Schema::hasTable('kho_logs')) {
            Schema::create('kho_logs', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('action', 80);
                $table->string('reference', 60)->default('');
                $table->text('detail');
                $table->integer('user_id')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index('created_at', 'kho_logs_created_index');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'kho_logs',
            'kho_budgets',
            'kho_reminders',
            'kho_issues',
            'kho_receipts',
            'kho_purchase_orders',
            'kho_items',
            'kho_employee_sizes',
            'kho_department_groups',
            'kho_policies',
            'kho_families',
            'kho_settings',
            'kho_staff',
            'kho_lock',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
