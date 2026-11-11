# Workdesk

Workdesk stores application data in MySQL and uploaded PDFs on disk. MySQL Server is the database engine; MySQL Workbench is a desktop client for creating the database, running SQL, and browsing its tables.

- `src/App.jsx` provides feature navigation. Frontend screens and styles are grouped in `src/features/` by feature (`meetings`, `permits`, `pdf-seal`, and `accounts`).
- The React frontend is in `src/`. Laravel API controllers, middleware, and routes are in `backend/app/` and `backend/routes/api.php`; the frontend communicates with the API rather than accessing MySQL.
- The production API is Laravel. Node.js also runs the ZKTeco attendance collector and the SQLite import tool.
- Run `npm run dev` to start the Vite frontend, Laravel API, local Reverb WebSocket server, and attendance collector. The frontend is at `http://localhost:5173`; Vite proxies API calls to Laravel on port 8000.
- Laravel Reverb sends private, per-user update events on port 8091. The frontend refreshes meeting, permit, account, and signing data when a relevant change arrives or the socket reconnects.
- This Windows setup uses PHP 8.3 and Composer for Laravel dependencies. On a fresh checkout, install PHP 8.3 with `openssl`, `mbstring`, `pdo_mysql`, `fileinfo`, `curl`, and `zip` enabled; run `composer install` in `backend`, then `npm install` in the project root. Copy `backend/.env.example` to `backend/.env`, set its MySQL and Reverb values, and run `php artisan key:generate`.
- For a production build, run `npm run build`, then `npm run start:lan`. Laravel serves the built frontend and API on port 3001, while Reverb listens on port 8091. From another device on the same trusted network, open `http://<this-computer-LAN-IP>:3001`; allow TCP ports 3001 and 8091 on the Windows Firewall **Private** profile if prompted.
- MySQL Server 8.0 is installed on this Windows machine as the `MySQL80` service, bound to `127.0.0.1:3306`. The Workdesk API uses the existing `lms_vina` database; local connection settings are in the ignored `backend/.env` file.
- To inspect this database in MySQL Workbench, connect to host `127.0.0.1`, port `3306`, and schema `lms_vina`. Do not commit `backend/.env`.
- The whole MySQL schema is managed by Laravel migrations in `backend/database/migrations`. On a new machine, create an empty `utf8mb4` database, configure `backend/.env`, then run `php artisan migrate` from `backend`; no MySQL command-line client is needed. The original Workdesk/LMS tables are created by the `0001_01_00_00000N_*` migrations, written with the Laravel schema builder: `create_workdesk_users_table`, `create_organization_tables`, `create_auth_sessions_table`, `create_meeting_tables`, `create_legacy_document_tables`, `create_gate_permits_table` and `create_attendance_tables` (`cham_cong`, `bang_cham_cong_ngay`). Each one skips tables that already exist, so running `php artisan migrate` on the existing server only records them. Their `down()` methods intentionally keep the data; use `php artisan migrate:fresh` on a development machine to start over. `users.id` is a signed `int`, and every foreign key to `users` uses `int`. Every later migration also checks for existing tables/columns. Back up the database before migrating a server with real data. `database.mysql.sql` is kept only as the historical reference for the old Node schema.
- To merge an older Workdesk database into an existing LMS database, back up both `lms_vina` and the old `workdesk` database, configure `backend/.env`, then run `php artisan workdesk:install-lms-vina` from `backend`. The installer preserves existing LMS rows, adds Workdesk profile/authentication fields to `users`, matches the legacy user list by `user_code`, and stops if old Workdesk feature tables contain rows it cannot safely merge.
- After installing the base Workdesk schema, back up `lms_vina` and run `php artisan workdesk:install-organization-hierarchy` from `backend` to add organization levels, divisions, teams, and team membership without replacing existing LMS users or departments. The hierarchy is System admin → CEO → division head → department head → team lead → employee/worker. Employees and workers share the same level; each employee belongs to at most one department and one team.
- Admins and CEOs can manage the full organization. Division heads, department heads, and team leads can view and manage users within their reporting scope and assign lower levels. Managers can grant any of the available business permissions to their subordinates: meeting room administration, permit approval, gate confirmation, and PDF signing. System admins and CEOs have these business permissions automatically.
- Existing LMS employees have no Workdesk login email or password. Provision the first administrator from `backend` with `php artisan workdesk:provision-admin <user_code> <email>`; enter the initial password only at the secure prompt. Workdesk users can sign in with their LMS `user_code` or email. The administrator can then add email addresses and issue credentials to other employee accounts.
- To copy the existing SQLite records into a new, empty MySQL database, back up `data/seal-demo.sqlite` first, then run `npm run db:migrate:sqlite`. The migration refuses to copy into a database containing application records and prints row counts for verification. It does not delete the original SQLite file or uploaded PDFs.
- Uploaded PDF files are stored in `data/uploads/`; the database stores their file paths. The `data/` directory is ignored by Git.
- Completed signing workflows appear under the separate **Tài liệu đã đóng dấu** tab (documents you uploaded or signed). Open one to see its signers, the page each seal was placed on, the PDF with saved seals, and download links. The PDF contains visual stamps, not cryptographic digital signatures.
- PDF stamping is a hand-over workflow. Every document has one current holder (`document_workflows`). An uploaded document stays in **Tài liệu của tôi → Đang ở chỗ tôi** until it is forwarded; while holding it, a user can add any number of their own seals on any pages, drag them (also across pages), resize or delete them. The holder chooses the next recipient in the bar at the top of the document, or completes and locks it. After forwarding, the sender's seals are locked; if the document is forwarded back, the sender can edit their own seals again. Nobody can edit another user's seals. History is kept in `document_routes`; seals in `document_seals`. Apply `backend/database/migrations/2026_11_06_000001_create_document_routing.php` (it converts documents from the old fixed signer chain).
- Users design their seal under **Hồ sơ & bảo mật → Con dấu & chữ ký** (round, square, name/title, signature, or an uploaded scan with optional background removal). The browser renders a transparent PNG; a copy is frozen into each document at signing time (`document_seal_images`), so later design changes do not alter signed documents. Seal images are stored in `data/seals/`. Apply `backend/database/migrations/2026_11_05_000001_create_seal_profiles.php` with `php artisan migrate --path=... --force`.
- Meeting schedules are saved in the MySQL `meetings` table and can be managed from the **Lịch họp** feature. Choose a single-day meeting, a multi-day meeting, or a weekly recurrence with selected weekdays and an end date. Users with the meeting room administration permission manage the shared room catalogue. Availability is checked as the schedule is entered, and the API rechecks room and company-wide conflicts before saving, including across overnight meetings and recurring occurrences.
- Gate permits are saved in the MySQL `gate_permits` table and managed from **Giấy phép cổng**. Each permit is assigned to an active user with the approver permission and a different active user with the guard permission; only those assigned users with the corresponding permission can approve or confirm the request. The completed permit can be printed for signing at the gate.
- ZKTeco F18 attendance collection and viewing:
  - **Data path:** the Node.js collector connects directly to each terminal over TCP on port `4370` using the `zklib-js` package. It reads attendance history immediately at startup and then every five minutes. It stores each newly-seen terminal record in `cham_cong`, retaining the terminal IP, record serial, decoded employee ID/time, verification/punch bytes, and complete raw record. New records are identified by their per-device record serial so repeated history scans do not duplicate old records. If a device record serial moves backwards (for example after its logs are cleared or its counter resets), the collector logs an error and pauses imports for that device rather than silently skipping or duplicating records. Run the attendance schema migration before starting the updated collector.
  - **Configured devices:** set `ZK_ATTENDANCE_DEVICE_IPS` in `backend/.env` to a comma-separated list of terminal IP addresses. The default list is `192.168.110.235,192.168.110.236,192.168.110.237,192.168.110.238,192.168.1.240,192.168.1.239,192.168.1.238`. Make sure the application host can reach each terminal on TCP port `4370`; an unreachable terminal is retried independently and does not prevent other reachable terminals from collecting new events.
  - **Start and monitor:** `npm run dev`, `npm run start`, and `npm run start:lan` launch the collector together with the application. A MySQL advisory lock prevents a second collector process from scanning the terminals concurrently. In the console, look for `[attendance <IP>] History check complete: N new, M scanned.` every five minutes; read and database errors are logged with the terminal IP. The application host must remain on and connected to the terminal network for collection to continue.
  - **Employee names in Workdesk:** `cham_cong.user_code` is the ID supplied by the attendance terminal. The attendance API matches it to `users.timekeeping_code` and displays `users.name`; it is not matched against the Workdesk login `users.user_code`. Rows with an ID that does not match `timekeeping_code` have no linked employee name. Raw history records are 40-byte terminal records; the collector stores the record's verification byte (offset 26) and punch-state byte (offset 31) in the existing mode columns and retains the complete record in `raw_record`. This byte layout is reverse-engineered from a related ZKTeco model, not vendor-confirmed for every F18 firmware, so the raw bytes remain the authoritative source; no work-code offset is assumed.
  - **Record retention:** attendance rows are not deduplicated by employee/time. The migration removes the unique key on `(user_code, check_time)` so repeated events and events from different terminals are retained separately. History rows include `device_ip`, terminal record serial, `source_type`, and the complete `raw_record`; realtime events keep the raw event packet when supplied by the terminal.
  - **History import is optional and destructive:** starting the application does not clear or backfill attendance history. Back up `lms_vina`, run `php artisan migrate --path=database/migrations/2026_11_02_000002_expand_attendance_device_records.php --force` from `backend`, verify the desired IP list, then run `npm run attendance:replace` from the project root. To verify connectivity and record counts without changing the database, run `node scripts/zkteco-attendance-collector.cjs --check-history` with `ZK_ATTENDANCE_DEVICE_IPS` set to the intended list. The replacement command reads every configured terminal sequentially, and changes `cham_cong` only after all histories have been read and validated; if any terminal is unreachable or a read is incomplete, the existing table remains unchanged. This replaces all existing attendance rows with the history from configured devices. Exclude an offline terminal from `ZK_ATTENDANCE_DEVICE_IPS` only if you explicitly accept that its history will not be imported. Back up the database before running it.
  - The **Chấm công** screen lists records within the signed-in user's organization scope and supports server-side search, date filters, and pagination.
- Meal reporting:
  - The **Báo cơm** feature records Vietnamese meal quantities by report date, shift (morning/noon/afternoon/night), department, and workshop 1 or 2. Korean meal quantities are separate company totals for each date, shift, and workshop; they are not assigned to a department.
  - The full company department list is visible to all authenticated users at all times. Before cutoff, admins, CEOs, division heads, and department heads can edit the departments they manage; other designated shift reporters can edit Vietnamese meal counts only for their assigned department and shift. HR can enter the separate Korean totals for both workshops; admins can enter both meal types.
  - Admins configure one daily cutoff time for each shift. After a shift's cutoff on the selected report date, the report becomes read-only. The report uses Vietnam time (`Asia/Ho_Chi_Minh`).
  - To assign HR access, an admin opens **Tài khoản & phân quyền**, edits a user, and enables **HR nhập suất ăn Hàn**. Admins, CEOs, division heads, and department heads can assign one shift reporter per department and shift in **Báo cơm**, within their management scope.
  - The original meal reporting migration is `backend/database/migrations/2026_11_03_000001_create_meal_reports.php`. Apply the follow-up migration `backend/database/migrations/2026_11_03_000002_create_meal_korean_reports.php` to create the standalone Korean totals table. It combines any existing department-level Korean counts by date, shift, and workshop, preserving their company totals. Run from `backend`: `php artisan migrate --path=database/migrations/2026_11_03_000002_create_meal_korean_reports.php --force`.
- Run `npm test` to test PDF stamp geometry, gate permit workflow, and meeting recurrence/conflict validation.
- Set `PDF_SEAL_DATA_DIR` to store uploaded PDFs in another directory.
- Authentication uses an HttpOnly, SameSite=Strict session cookie backed by MySQL and expires after 12 hours. Existing Node scrypt password hashes are verified during the first successful Laravel login and upgraded to Laravel bcrypt hashes without resetting users' passwords.
- Existing LMS employee and attendance records are retained. Do not place passwords in source control or logs.
- After signing in, use **Tài khoản & phân quyền** to view users in your management scope, assign organization levels, and delegate business permissions. Admins and CEOs can create internal accounts; managers can update subordinate accounts and reset their passwords. Use **Phòng ban** to manage divisions, departments, and teams within your scope. Each user can change their own password under **Hồ sơ & bảo mật**. Passwords must be at least 6 characters; longer, unique passwords are recommended. Password changes revoke other active sessions.
- Meeting schedules remain visible to all signed-in users. Uploaded PDFs, workflow state, signing history, exports, and download links are private to the uploader except for accounts explicitly assigned as signers; those assigned users can access that document to complete their signing step. Other accounts cannot list or download it.
- For LAN use, do not expose the server to an untrusted network. The default `http://<host>:3001` deployment does not encrypt traffic; use a trusted LAN or place the app behind HTTPS before entering credentials from other devices.

This app is intended for a trusted internal trial. It is not a substitute for a hardened public-facing identity provider or HTTPS deployment.

This template provides a minimal setup to get React working in Vite with HMR and some Oxlint rules.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the Oxlint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and Oxlint's TypeScript related rules in your project.
Cách hoạt động hiện tại: collector không còn dựa vào sự kiện realtime push. Nó kết nối đến máy và đọc lịch sử chấm công ngay lúc khởi động, sau đó đọc lại mỗi 5 phút. Vì vậy, thời gian xuất hiện trong Workdesk có thể trễ tối đa khoảng 5 phút, cộng với thời gian máy gửi dữ liệu.

Bạn có thể xem theo thứ tự này trong VS Code:

Danh sách IP và chu kỳ quét

IP máy chấm công được đọc từ ZK_ATTENDANCE_DEVICE_IPS trong backend/.env; nếu chưa cấu hình thì dùng danh sách mặc định ở đầu collector.
Chu kỳ 5 phút được đặt ở historyPollIntervalMs.
Kết nối tới máy và MySQL

connectTcp mở kết nối TCP tới máy chấm công, cổng 4370, rồi bắt tay kết nối với thiết bị.
createPool đọc thông tin kết nối MySQL từ backend/.env.
Đọc và giải mã lịch sử

readAttendanceBuffer gửi yêu cầu đọc và nhận các gói dữ liệu máy gửi về.
fetchDeviceHistory gom dữ liệu thành các bản ghi 40 byte và giải mã những trường đã biết.
Chỉ thêm lượt mới

syncDeviceHistory đọc serial lớn nhất đang lưu cho từng máy, rồi chỉ chèn các serial mới vào cham_cong. Cách này tránh thêm lại toàn bộ lịch sử ở mỗi lần quét.
runDevicePoller thực hiện một lượt đọc, ghi log kết quả, chờ 5 phút rồi quét lại.
Khởi chạy collector

runCollector khởi tạo và chạy các poller; ứng dụng khởi chạy collector qua run-laravel.ps1.
Để đọc nhanh: trong VS Code nhấn Ctrl+P, nhập zkteco-attendance-collector.cjs; sau đó dùng Ctrl+G và nhập số dòng ở các liên kết trên. Khi collector chạy, tìm log dạng [attendance <IP>] History check complete: N new, M scanned.; lỗi kết nối hoặc đọc lịch sử cũng được ghi kèm IP. Hướng dẫn vận hành nằm trong phần chấm công của README.md.

1. Cài đặt lần đầu (bỏ qua nếu đã cài)

cd backend
composer install
copy .env.example .env      # sau đó sửa thông tin MySQL và Reverb trong backend\.env
php artisan key:generate
cd ..
npm install
Bạn cần PHP 8.3 bật sẵn các extension openssl, mbstring, pdo_mysql, fileinfo, và cần có Node.js.

2. Build frontend

npm run build
Lệnh này tạo thư mục dist/, Laravel sẽ phục vụ frontend từ đây.

3. Chạy server
Có hai lựa chọn:

Lệnh	Truy cập từ	Địa chỉ
npm run start	Chỉ máy này	http://127.0.0.1:3001
npm run start:lan	Các máy khác trong mạng LAN	http://<IP-LAN-của-máy>:3001
Mỗi lệnh chạy cùng lúc ba thứ:

Laravel API cùng frontend ở cổng 3001
Reverb WebSocket ở cổng 8091
Collector chấm công ZKTeco
Nếu chạy LAN, bạn cần mở cổng TCP 3001 và 8091 trên Windows Firewall, profile Private.

Lưu ấy
Kết nối dùng HTTP nên không được mã hoá. Chỉ dùng trong mạng LAN tin cậy, hoặc đặt một reverse proxy HTTPS ở phía trước.
Khi dev thì dùng npm run dev. Frontend chạy ở http://localhost:5173, API ở cổng 8000.
Nếu backend\.env vẫn để APP_DEBUG=true, bạn nên đổi thành APP_ENV=production và APP_DEBUG=false khi chạy production.


---------cách chạy chấm công ------------

Collector chấm công là file scripts/zkteco-attendance-collector.cjs. Nó có 3 cách chạy, mọi lệnh đều chạy ở thư mục gốc D:\dungcc-dev.

1. Tự chạy kèm ứng dụng (cách thường dùng)
Khi bạn chạy npm run start, npm run start:lan hoặc npm run dev, collector khởi động luôn cùng ứng dụng. Nó đọc lịch sử chấm công ngay khi khởi động, sau đó cứ 5 phút đọc lại một lần. Bản ghi mới được lưu vào bảng cham_cong.

Muốn chạy riêng collector, không cần bật cả ứng dụng:


node scripts/zkteco-attendance-collector.cjs
Trên console sẽ hiện log kiểu: [attendance <IP>] History check complete: N new, M scanned.

2. Chỉ kiểm tra kết nối, không ghi DB

node scripts/zkteco-attendance-collector.cjs --check-history
Lệnh này đọc lịch sử từ từng máy và in ra số bản ghi đọc được. Database giữ nguyên.

3. Thay toàn bộ lịch sử chấm công (thao tác này xoá dữ liệu)

# 1. Backup database lms_vina trước
cd backend
php artisan migrate --path=database/migrations/2026_11_02_000002_expand_attendance_device_records.php --force
cd ..
npm run attendance:replace
Lệnh này xoá toàn bộ dữ liệu trong cham_cong, rồi nạp lại từ lịch sử của tất cả các máy. Nếu có máy nào không kết nối được hoặc đọc dữ liệu không đủ, bảng được giữ nguyên.

Cấu hình
Collector đọc thông tin từ backend/.env:

ZK_ATTENDANCE_DEVICE_IPS: danh sách IP các máy chấm công, cách nhau bằng dấu phẩy. Nếu không khai báo, collector dùng danh sách mặc định 192.168.110.235–238 và 192.168.1.238–240.
DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD: thông tin kết nối MySQL.
Máy chạy collector phải kết nối được tới các máy chấm công qua cổng TCP 4370. Collector có khoá MySQL, nên chỉ một tiến trình được quét các máy tại một thời điểm. Nếu ứng dụng đang chạy thì collector đi kèm đã giữ khoá rồi. Vì vậy, khi đó bạn không nên chạy thêm một bản riêng.