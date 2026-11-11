<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== users table ===\n";
echo DB::select('SHOW CREATE TABLE users')[0]->{'Create Table'} . "\n\n";

echo "=== users ===\n";
print_r(DB::table('users')->select('id', 'email', 'role', 'active')->get());

echo "=== FKs referencing users ===\n";
$fks = DB::select("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'users' AND TABLE_SCHEMA = DATABASE()");
print_r($fks);

echo "=== user_permissions ===\n";
print_r(DB::table('user_permissions')->get());

echo "=== departments ===\n";
print_r(DB::table('departments')->get());