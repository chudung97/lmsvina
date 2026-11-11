<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== "mysql") {
            return;
        }

        // Migration này dựng lại bảng users và chỉ giữ một số cột cũ. Khi id đã là số
        // tự tăng (hệ thống đang chạy), chạy lại sẽ làm mất user_code, timekeeping_code,
        // org_level... nên phải bỏ qua.
        if (! Schema::hasTable("users")
            || in_array(Schema::getColumnType("users", "id"), ["bigint", "int", "integer", "mediumint", "smallint"], true)) {
            return;
        }

        $users = DB::table("users")->select("id")->orderByRaw("FIELD(id, \"u1\",\"u2\",\"u3\") DESC, id ASC")->get();
        $map = [];
        $newId = 1;
        foreach ($users as $user) {
            $map[$user->id] = $newId++;
        }

        Schema::disableForeignKeyConstraints();

        try { DB::statement("DROP TABLE IF EXISTS users_new2"); } catch (\Throwable $e) {}

        DB::statement("CREATE TABLE users_new2 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            seal_text VARCHAR(255) NULL,
            signature_name VARCHAR(255) NULL,
            color VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NULL,
            role VARCHAR(255) NOT NULL DEFAULT \"member\",
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL,
            department_id CHAR(36) NULL,
            UNIQUE KEY users_email_unique (email)
        )");

        foreach ($users as $user) {
            $old = $user->id;
            DB::table("users_new2")->insert([
                "id" => $map[$old],
                "name" => DB::table("users")->where("id", $old)->value("name"),
                "seal_text" => DB::table("users")->where("id", $old)->value("seal_text"),
                "signature_name" => DB::table("users")->where("id", $old)->value("signature_name"),
                "color" => DB::table("users")->where("id", $old)->value("color"),
                "email" => DB::table("users")->where("id", $old)->value("email"),
                "password_hash" => DB::table("users")->where("id", $old)->value("password_hash"),
                "role" => DB::table("users")->where("id", $old)->value("role"),
                "active" => DB::table("users")->where("id", $old)->value("active"),
                "created_at" => DB::table("users")->where("id", $old)->value("created_at"),
                "department_id" => DB::table("users")->where("id", $old)->value("department_id"),
            ]);
        }

        $refs = [
            ["auth_sessions", "user_id"],
            ["completed_seals", "user_id"],
            ["departments", "manager_user_id"],
            ["document_signers", "user_id"],
            ["documents", "owner_id"],
            ["gate_permits", "approver_id"],
            ["gate_permits", "guard_id"],
            ["gate_permits", "requester_id"],
            ["meeting_participants", "user_id"],
            ["meetings", "created_by"],
            ["owner_workflows", "owner_id"],
            ["seal_positions", "user_id"],
            ["user_permissions", "user_id"],
            ["workflow_events", "owner_id"],
            ["workflow_state", "current_user_id"],
        ];

        foreach ($refs as $ref) {
            $table = $ref[0]; $col = $ref[1];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                continue;
            }
            $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col' AND REFERENCED_TABLE_NAME='users'");
            foreach ($fks as $fk) {
                try { DB::statement("ALTER TABLE `$table` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`"); } catch (\Throwable $e) {}
            }
            try { DB::statement("ALTER TABLE `$table` MODIFY `$col` BIGINT UNSIGNED NULL"); } catch (\Throwable $e) {
                try { DB::statement("ALTER TABLE `$table` CHANGE `$col` `$col` BIGINT UNSIGNED NULL"); } catch (\Throwable $e2) {}
            }
            foreach ($map as $old => $new) {
                if (!is_numeric($old)) {
                    DB::table($table)->where($col, $old)->update([$col => $new]);
                }
            }
        }

        Schema::drop("users");
        DB::statement("RENAME TABLE users_new2 TO users");

        if (Schema::hasTable("departments") && Schema::hasColumn("departments", "manager_user_id")) {
            try { DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_manager_user_id_foreign FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}
        }
        if (Schema::hasTable("auth_sessions") && Schema::hasColumn("auth_sessions", "user_id")) {
            try { DB::statement("ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (\Throwable $e) {}
        }
        if (Schema::hasTable("user_permissions") && Schema::hasColumn("user_permissions", "user_id")) {
            try { DB::statement("ALTER TABLE user_permissions ADD CONSTRAINT user_permissions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE"); } catch (\Throwable $e) {}
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
    }
};
