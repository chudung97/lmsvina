<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('workdesk:install-lms-vina', function (): int {
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'lms_vina') {
        $this->error('This installer only runs against the lms_vina MySQL database.');

        return Command::FAILURE;
    }

    foreach (['id', 'user_code', 'full_name', 'UserEnable'] as $requiredColumn) {
        if (! Schema::hasColumn('users', $requiredColumn)) {
            $this->error("The lms_vina.users table is missing required column {$requiredColumn}.");

            return Command::FAILURE;
        }
    }
    $idColumn = DB::selectOne(
        "SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'",
    );
    if ($idColumn->DATA_TYPE !== 'int' || str_contains($idColumn->COLUMN_TYPE, 'unsigned')) {
        $this->error('lms_vina.users.id must remain a signed INT so Workdesk can preserve LMS user IDs.');

        return Command::FAILURE;
    }

    $sourceTables = [
        'auth_sessions',
        'completed_seals',
        'departments',
        'document_signers',
        'documents',
        'gate_permits',
        'meeting_booking_lock',
        'meeting_participants',
        'meeting_rooms',
        'meetings',
        'migrations',
        'owner_workflows',
        'seal_positions',
        'user_permissions',
        'workflow_events',
        'workflow_state',
    ];
    foreach (['users', ...$sourceTables] as $table) {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            ['workdesk', $table],
        );
        if ((int) $exists->total !== 1) {
            $this->error("The source table workdesk.{$table} was not found; no changes were made.");

            return Command::FAILURE;
        }
    }
    $targetUsers = (int) DB::table('users')->count();
    $sourceUsers = (int) DB::table('workdesk.users')->count();
    $matchedUsers = (int) DB::selectOne(
        'SELECT COUNT(*) AS total FROM workdesk.users AS source
        JOIN lms_vina.users AS target ON source.user_code = target.user_code',
    )->total;
    if ($targetUsers !== $sourceUsers || $matchedUsers !== $targetUsers) {
        $this->error('User rows do not match one-to-one by user_code; no changes were made.');

        return Command::FAILURE;
    }
    $invalidNames = DB::table('users')
        ->whereNull('full_name')
        ->orWhere('full_name', '')
        ->orWhereRaw('CHAR_LENGTH(full_name) > 120')
        ->exists();
    if ($invalidNames) {
        $this->error('Every LMS full_name must contain at most 120 characters before installing Workdesk.');

        return Command::FAILURE;
    }
    foreach ($sourceTables as $table) {
        if ((int) DB::table("workdesk.{$table}")->count() !== 0) {
            $this->error("workdesk.{$table} contains data; import has been stopped to avoid losing it.");

            return Command::FAILURE;
        }
    }
    $nonNullLegacyEmails = DB::table('users')->whereNotNull('Email')->count();
    if ($nonNullLegacyEmails !== 0) {
        $this->error('lms_vina.users.Email contains values; the legacy decimal Email column was not changed.');

        return Command::FAILURE;
    }

    $columns = [
        'name' => 'VARCHAR(120) NULL',
        'seal_text' => 'VARCHAR(3) NULL',
        'signature_name' => 'VARCHAR(120) NULL',
        'color' => 'CHAR(7) NULL',
        'password_hash' => 'VARCHAR(256) NULL',
        'role' => "ENUM('admin', 'member') NOT NULL DEFAULT 'member'",
        'active' => 'TINYINT(1) NULL',
        'created_at' => 'DATETIME NULL',
        'department_id' => 'CHAR(36) COLLATE utf8mb4_0900_ai_ci NULL',
    ];
    foreach ($columns as $column => $definition) {
        if (! Schema::hasColumn('users', $column)) {
            DB::statement("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
        }
    }

    $emailColumn = DB::selectOne(
        "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND LOWER(COLUMN_NAME) = 'email'",
    );
    if (! $emailColumn) {
        DB::statement('ALTER TABLE `users` ADD COLUMN `email` VARCHAR(254) NULL');
    } elseif ($emailColumn->DATA_TYPE !== 'varchar') {
        DB::statement('ALTER TABLE `users` MODIFY COLUMN `Email` VARCHAR(254) NULL');
    }

    DB::statement(
        "UPDATE `users` SET
            `name` = COALESCE(NULLIF(`name`, ''), LEFT(`full_name`, 120)),
            `seal_text` = COALESCE(NULLIF(`seal_text`, ''), LEFT(`full_name`, 3)),
            `signature_name` = COALESCE(NULLIF(`signature_name`, ''), LEFT(`full_name`, 120)),
            `color` = COALESCE(NULLIF(`color`, ''), '#0f766e'),
            `active` = COALESCE(`active`, IF(`UserEnable` = 1, 1, 0)),
            `created_at` = COALESCE(`created_at`, CURRENT_TIMESTAMP)",
    );
    DB::statement("ALTER TABLE `users`
        MODIFY COLUMN `name` VARCHAR(120) NOT NULL,
        MODIFY COLUMN `seal_text` VARCHAR(3) NOT NULL,
        MODIFY COLUMN `signature_name` VARCHAR(120) NOT NULL,
        MODIFY COLUMN `color` CHAR(7) NOT NULL,
        MODIFY COLUMN `active` TINYINT(1) NOT NULL DEFAULT 0,
        MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN `department_id` CHAR(36) COLLATE utf8mb4_0900_ai_ci NULL");

    $emailIndex = DB::selectOne(
        "SELECT COUNT(*) AS total FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'users_email_unique'",
    );
    if ((int) $emailIndex->total === 0) {
        $duplicateEmails = DB::selectOne(
            'SELECT COUNT(*) AS total FROM (
                SELECT `email` FROM `users` WHERE `email` IS NOT NULL
                GROUP BY `email` HAVING COUNT(*) > 1
            ) AS duplicate_emails',
        );
        if ((int) $duplicateEmails->total !== 0) {
            $this->error('Duplicate Workdesk email values must be resolved before creating the unique index.');

            return Command::FAILURE;
        }
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `users_email_unique` (`email`)');
    }

    $schemaPath = base_path('../database.mysql.sql');
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException("Could not read the Workdesk schema at {$schemaPath}.");
    }
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            DB::unprepared($statement);
        }
    }

    foreach ([
        'auth_sessions',
        'completed_seals',
        'departments',
        'document_signers',
        'documents',
        'gate_permits',
        'meeting_booking_lock',
        'meeting_participants',
        'meeting_rooms',
        'meetings',
        'owner_workflows',
        'seal_positions',
        'user_permissions',
        'workflow_events',
        'workflow_state',
    ] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Workdesk schema installation did not create table {$table}.");
        }
    }

    $this->info("Workdesk schema installed in lms_vina; {$targetUsers} LMS users were preserved and matched by user_code.");
    $this->warn('The source Workdesk feature tables were empty. Email and password values were not imported; provision a Workdesk administrator before signing in.');

    return Command::SUCCESS;
})->purpose('Safely add the Workdesk schema to lms_vina without replacing LMS users');

Artisan::command('workdesk:provision-admin {user_code} {email}', function (string $user_code, string $email): int {
    if (DB::connection()->getDatabaseName() !== 'lms_vina') {
        $this->error('This command only provisions administrators in lms_vina.');

        return Command::FAILURE;
    }
    if (! Schema::hasColumn('users', 'password_hash') || ! Schema::hasColumn('users', 'user_code')) {
        $this->error('Install the Workdesk schema before provisioning an administrator.');

        return Command::FAILURE;
    }
    $user = DB::table('users')->where('user_code', trim($user_code))->first(['id', 'name', 'UserEnable']);
    if (! $user) {
        $this->error('No LMS user has that user_code.');

        return Command::FAILURE;
    }
    if ((int) $user->UserEnable !== 1) {
        $this->error('Only an enabled LMS employee can be provisioned as the first administrator.');

        return Command::FAILURE;
    }

    $email = strtolower(trim($email));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
        $this->error('A valid email address is required.');

        return Command::FAILURE;
    }
    if (DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->exists()) {
        $this->error('That email address is already assigned to another user.');

        return Command::FAILURE;
    }
    if (DB::table('users')->where('role', 'admin')->where('active', 1)->exists()) {
        $this->error('An active Workdesk administrator already exists.');

        return Command::FAILURE;
    }

    $password = $this->secret('Initial password (at least 6 characters)');
    $confirmation = $this->secret('Confirm initial password');
    if (! is_string($password) || mb_strlen($password) < 6 || mb_strlen($password) > 1024
        || $password !== $confirmation) {
        $this->error('The password must be at least 6 characters and both entries must match.');

        return Command::FAILURE;
    }

    DB::table('users')->where('id', $user->id)->update([
        'email' => $email,
        'password_hash' => Hash::make($password),
        'role' => 'admin',
        'active' => 1,
    ]);
    $this->info("Workdesk administrator provisioned for {$user->name} ({$email}).");

    return Command::SUCCESS;
})->purpose('Set up the first Workdesk administrator for an existing LMS employee');

Artisan::command('workdesk:install-organization-hierarchy', function (): int {
    if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'lms_vina') {
        $this->error('This installer only runs against the lms_vina MySQL database.');

        return Command::FAILURE;
    }

    foreach (['users', 'departments'] as $table) {
        if (! Schema::hasTable($table)) {
            $this->error("The {$table} table is missing; install the Workdesk schema first.");

            return Command::FAILURE;
        }
    }
    if (! Schema::hasColumn('users', 'org_level')) {
        DB::statement("ALTER TABLE `users`
            ADD COLUMN `org_level` ENUM('ceo', 'division_head', 'department_head', 'team_lead', 'employee')
            NOT NULL DEFAULT 'employee'");
    }
    if (! Schema::hasColumn('departments', 'division_id')) {
        DB::statement('ALTER TABLE `departments` ADD COLUMN `division_id` CHAR(36) COLLATE utf8mb4_0900_ai_ci NULL');
    }

    DB::unprepared("CREATE TABLE IF NOT EXISTS `divisions` (
        `id` CHAR(36) NOT NULL PRIMARY KEY,
        `name` VARCHAR(120) NOT NULL,
        `manager_user_id` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `divisions_name_unique` (`name`),
        UNIQUE KEY `divisions_manager_unique` (`manager_user_id`),
        CONSTRAINT `divisions_manager_fk`
            FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    DB::unprepared("CREATE TABLE IF NOT EXISTS `teams` (
        `id` CHAR(36) NOT NULL PRIMARY KEY,
        `department_id` CHAR(36) COLLATE utf8mb4_0900_ai_ci NOT NULL,
        `name` VARCHAR(120) NOT NULL,
        `manager_user_id` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `teams_department_name_unique` (`department_id`, `name`),
        UNIQUE KEY `teams_manager_unique` (`manager_user_id`),
        CONSTRAINT `teams_department_fk`
            FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
        CONSTRAINT `teams_manager_fk`
            FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    DB::unprepared("CREATE TABLE IF NOT EXISTS `team_members` (
        `user_id` INT NOT NULL PRIMARY KEY,
        `team_id` CHAR(36) COLLATE utf8mb4_0900_ai_ci NOT NULL,
        CONSTRAINT `team_members_user_fk`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `team_members_team_fk`
            FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    foreach (['org_level' => ['users', 'org_level'], 'division_id' => ['departments', 'division_id']] as $table) {
        if (! Schema::hasColumn($table[0], $table[1])) {
            throw new RuntimeException("Organization hierarchy migration did not add {$table[0]}.{$table[1]}.");
        }
    }
    foreach (['divisions', 'teams', 'team_members'] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Organization hierarchy migration did not create {$table}.");
        }
    }

    $this->info('Organization hierarchy schema installed in lms_vina. Existing users and departments were preserved.');
    $this->comment('Create divisions and teams, then assign leaders and employees from the organization management screen.');

    return Command::SUCCESS;
})->purpose('Add organization hierarchy tables and columns to lms_vina safely');
