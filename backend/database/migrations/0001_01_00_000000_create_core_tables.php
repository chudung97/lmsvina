<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Các bảng nền của Workdesk trước khi chuyển sang Laravel (người dùng, tổ chức, lịch họp,
 * giấy phép cổng, chấm công, đóng dấu PDF kiểu cũ). Trước đây chúng được tạo từ
 * database.mysql.sql và lệnh artisan cài đặt; migration này giúp một database trống
 * chỉ cần chạy `php artisan migrate`.
 *
 * Cấu trúc lấy nguyên từ hệ thống đang chạy. Bảng nào đã tồn tại thì bỏ qua, nên chạy trên
 * server hiện tại không thay đổi gì.
 */
return new class extends Migration
{
    private const TABLES = [
        'users' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timekeeping_code` int DEFAULT NULL,
  `timekeeping_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_no` int DEFAULT NULL,
  `UserPassWord` decimal(12,2) DEFAULT NULL,
  `PhanQuyen` int DEFAULT NULL,
  `UserEnable` tinyint(1) DEFAULT NULL,
  `GioiTinh` tinyint(1) DEFAULT NULL,
  `NgayVaoLamViec` datetime DEFAULT NULL,
  `ChucVu` decimal(12,2) DEFAULT NULL,
  `NgaySinh` datetime DEFAULT NULL,
  `NoiSinh` decimal(12,2) DEFAULT NULL,
  `LoaiNhanVien` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `CMND` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `DienThoaiLienHe` decimal(12,2) DEFAULT NULL,
  `Email` varchar(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `NgayPhep` int DEFAULT NULL,
  `HinhAnh` decimal(12,2) DEFAULT NULL,
  `TienLuong` int DEFAULT NULL,
  `LuongHopDong` int DEFAULT NULL,
  `DanToc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `QuocTich` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Skype` decimal(12,2) DEFAULT NULL,
  `Yahoo` decimal(12,2) DEFAULT NULL,
  `Facebook` decimal(12,2) DEFAULT NULL,
  `PassWord` decimal(12,2) DEFAULT NULL,
  `DangThamGiaBaoHiem` tinyint(1) DEFAULT NULL,
  `NghiViecTamThoi` tinyint(1) DEFAULT NULL,
  `TinhLuongTheo` tinyint(1) DEFAULT NULL,
  `SanPhamOrCongDoan` tinyint(1) DEFAULT NULL,
  `NgayCap` datetime DEFAULT NULL,
  `NoiCap` decimal(12,2) DEFAULT NULL,
  `NgayKyHopDong` datetime DEFAULT NULL,
  `ThoiHanHopDong` int DEFAULT NULL,
  `TrinhDo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `DuongDanAnh` decimal(12,2) DEFAULT NULL,
  `GhiChu` decimal(12,2) DEFAULT NULL,
  `NhanVienMoi` tinyint(1) DEFAULT NULL,
  `MaCongTy` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MaKhuVuc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MaPhongBan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MaChucVu` decimal(12,2) DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seal_text` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','member') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `department_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `org_level` enum('ceo','division_head','department_head','team_lead','employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_code` (`user_code`),
  UNIQUE KEY `users_email_unique` (`Email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'divisions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `divisions` (
  `id` char(36) NOT NULL,
  `name` varchar(120) NOT NULL,
  `manager_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `divisions_name_unique` (`name`),
  UNIQUE KEY `divisions_manager_unique` (`manager_user_id`),
  CONSTRAINT `divisions_manager_fk` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'departments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `departments` (
  `id` char(36) NOT NULL,
  `name` varchar(120) NOT NULL,
  `manager_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `division_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_name_unique` (`name`),
  UNIQUE KEY `departments_manager_unique` (`manager_user_id`),
  CONSTRAINT `departments_manager_fk` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'teams' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `teams` (
  `id` char(36) NOT NULL,
  `department_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `name` varchar(120) NOT NULL,
  `manager_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teams_department_name_unique` (`department_id`,`name`),
  UNIQUE KEY `teams_manager_unique` (`manager_user_id`),
  CONSTRAINT `teams_department_fk` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teams_manager_fk` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'team_members' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `team_members` (
  `user_id` int NOT NULL,
  `team_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `team_members_team_fk` (`team_id`),
  CONSTRAINT `team_members_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'user_permissions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id` int NOT NULL,
  `permission` varchar(40) NOT NULL,
  PRIMARY KEY (`user_id`,`permission`),
  CONSTRAINT `user_permissions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'auth_sessions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `auth_sessions` (
  `token_hash` char(64) NOT NULL,
  `user_id` int NOT NULL,
  `expires_at` bigint NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_hash`),
  KEY `auth_sessions_expiry` (`expires_at`),
  KEY `auth_sessions_user_fk` (`user_id`),
  CONSTRAINT `auth_sessions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'meeting_rooms' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `meeting_rooms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meeting_rooms_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'meetings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `meetings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `meeting_date` char(10) NOT NULL,
  `end_date` char(10) NOT NULL,
  `start_time` char(5) NOT NULL,
  `end_time` char(5) NOT NULL,
  `room_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recurrence_type` enum('single','overnight','weekly') NOT NULL DEFAULT 'single',
  `recurrence_group_id` char(36) DEFAULT NULL,
  `recurrence_days` text,
  `recurrence_end_date` char(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meetings_creator_fk` (`created_by`),
  KEY `meetings_room_time` (`room_id`,`meeting_date`,`start_time`,`end_date`,`end_time`),
  CONSTRAINT `meetings_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `meetings_room_fk` FOREIGN KEY (`room_id`) REFERENCES `meeting_rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'meeting_participants' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `meeting_participants` (
  `meeting_id` int unsigned NOT NULL,
  `user_id` int NOT NULL,
  PRIMARY KEY (`meeting_id`,`user_id`),
  KEY `meeting_participants_user` (`user_id`,`meeting_id`),
  CONSTRAINT `meeting_participants_meeting_fk` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meeting_participants_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'meeting_booking_lock' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `meeting_booking_lock` (
  `id` tinyint unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'documents' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `file_path` text NOT NULL,
  `owner_id` int DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documents_owner_completed` (`owner_id`,`completed_at`),
  CONSTRAINT `documents_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'workflow_state' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `workflow_state` (
  `id` tinyint unsigned NOT NULL,
  `current_user_id` int NOT NULL,
  `current_document_id` int unsigned DEFAULT NULL,
  `finalized` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `workflow_state_user_fk` (`current_user_id`),
  KEY `workflow_state_document_fk` (`current_document_id`),
  CONSTRAINT `workflow_state_document_fk` FOREIGN KEY (`current_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workflow_state_user_fk` FOREIGN KEY (`current_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'owner_workflows' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `owner_workflows` (
  `owner_id` int NOT NULL,
  `current_document_id` int unsigned DEFAULT NULL,
  `finalized` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`owner_id`),
  KEY `owner_workflows_document_fk` (`current_document_id`),
  CONSTRAINT `owner_workflows_document_fk` FOREIGN KEY (`current_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `owner_workflows_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'document_signers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `document_signers` (
  `document_id` int unsigned NOT NULL,
  `user_id` int NOT NULL,
  `sequence` int unsigned NOT NULL,
  PRIMARY KEY (`document_id`,`user_id`),
  UNIQUE KEY `document_signers_order` (`document_id`,`sequence`),
  KEY `document_signers_user_fk` (`user_id`),
  CONSTRAINT `document_signers_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_signers_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'seal_positions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `seal_positions` (
  `user_id` int NOT NULL,
  `document_id` int unsigned NOT NULL,
  `page` int unsigned NOT NULL DEFAULT '1',
  `x` double NOT NULL,
  `y` double NOT NULL,
  `width` double NOT NULL,
  `height` double NOT NULL,
  `rotation` double NOT NULL DEFAULT '0',
  `signed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`document_id`),
  KEY `seal_positions_document_fk` (`document_id`),
  CONSTRAINT `seal_positions_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seal_positions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'completed_seals' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `completed_seals` (
  `document_id` int unsigned NOT NULL,
  `user_id` int NOT NULL,
  `user_name` varchar(120) NOT NULL,
  `seal_text` varchar(3) NOT NULL,
  `signature_name` varchar(120) NOT NULL,
  `color` char(7) NOT NULL,
  `page` int unsigned NOT NULL,
  `x` double NOT NULL,
  `y` double NOT NULL,
  `width` double NOT NULL,
  `height` double NOT NULL,
  `rotation` double NOT NULL DEFAULT '0',
  PRIMARY KEY (`document_id`,`user_id`),
  KEY `completed_seals_user_fk` (`user_id`),
  CONSTRAINT `completed_seals_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `completed_seals_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'workflow_events' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `workflow_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `owner_id` int DEFAULT NULL,
  `document_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `workflow_events_owner_fk` (`owner_id`),
  KEY `workflow_events_document_fk` (`document_id`),
  CONSTRAINT `workflow_events_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workflow_events_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'gate_permits' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `gate_permits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `requester_id` int NOT NULL,
  `approver_id` int NOT NULL,
  `guard_id` int NOT NULL,
  `direction` enum('out','in') NOT NULL,
  `permit_date` char(10) NOT NULL,
  `permit_time` char(5) NOT NULL,
  `return_time` char(5) DEFAULT NULL,
  `purpose` varchar(500) NOT NULL,
  `destination` varchar(160) NOT NULL,
  `vehicle_info` varchar(160) NOT NULL DEFAULT '',
  `status` enum('pending_approval','pending_guard','approved','rejected') NOT NULL DEFAULT 'pending_approval',
  `approver_note` varchar(500) NOT NULL DEFAULT '',
  `guard_note` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` datetime DEFAULT NULL,
  `guard_confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gate_permits_requester` (`requester_id`,`created_at`),
  KEY `gate_permits_approver` (`approver_id`,`status`,`permit_date`),
  KEY `gate_permits_guard` (`guard_id`,`status`,`permit_date`),
  CONSTRAINT `gate_permits_approver_fk` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `gate_permits_guard_fk` FOREIGN KEY (`guard_id`) REFERENCES `users` (`id`),
  CONSTRAINT `gate_permits_requester_fk` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'cham_cong' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `cham_cong` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_time` datetime NOT NULL,
  `verify_mode` int DEFAULT '0',
  `in_out_mode` int DEFAULT '0',
  `work_code` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `device_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_record_sn` smallint unsigned DEFAULT NULL,
  `source_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'legacy',
  `raw_record` blob,
  PRIMARY KEY (`id`),
  KEY `idx_check_time` (`check_time`),
  KEY `idx_cham_cong_device_record` (`device_ip`,`device_record_sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'bang_cham_cong_ngay' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `bang_cham_cong_ngay` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ngay` date NOT NULL,
  `gio_vao` datetime DEFAULT NULL,
  `gio_ra` datetime DEFAULT NULL,
  `so_lan_quet` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_ngay` (`user_id`,`ngay`),
  KEY `idx_ngay` (`ngay`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Các bảng tham chiếu lẫn nhau, nên tắt kiểm tra khóa ngoại trong lúc tạo.
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table => $definition) {
                if (! Schema::hasTable($table)) {
                    DB::unprepared($definition);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Không xóa: đây là dữ liệu gốc (tài khoản, chấm công, lịch họp...). Muốn làm lại từ
        // đầu trên máy phát triển thì dùng `php artisan migrate:fresh`.
    }
};
