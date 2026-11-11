# Workdesk Laravel API

This folder contains the PHP/Laravel backend. The React application remains in the project root and calls the `/api` routes defined in `routes/api.php`.

## Local setup

1. Install PHP 8.3 with `openssl`, `mbstring`, `pdo_mysql`, `fileinfo`, `curl`, and `zip` extensions and install Composer.
2. Run `composer install` in this directory.
3. Copy `.env.example` to `.env`, configure the existing MySQL database, and run `php artisan key:generate`.
4. From the project root, run `npm install` and `npm run dev`.

The database schema is maintained in `../database.mysql.sql`. For the existing LMS database, back up `lms_vina` and `workdesk`, configure `backend/.env`, then run `php artisan workdesk:install-lms-vina`. The installer preserves LMS records and stops if old Workdesk feature tables contain data it cannot safely merge.

LMS employee rows have no Workdesk login email or password. Provision the first administrator with `php artisan workdesk:provision-admin <user_code> <email>`; enter the password only at the secure prompt. Users can sign in with their LMS `user_code` or email. After signing in, use **Tài khoản** to assign email addresses and passwords to other employees.

Node.js is also required by the root frontend build and by two narrow compatibility utilities: legacy scrypt-password verification and the existing PDF stamp renderer.

`npm run start:lan` from the project root starts Laravel and Reverb together. Laravel serves the API and built frontend on port 3001; Reverb serves authenticated, per-user WebSocket updates on port 8091.
