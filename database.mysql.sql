CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_code VARCHAR(50) NULL,
  name VARCHAR(120) NOT NULL,
  seal_text VARCHAR(3) NOT NULL,
  signature_name VARCHAR(120) NOT NULL,
  color CHAR(7) NOT NULL,
  email VARCHAR(254) NULL,
  password_hash VARCHAR(256) NULL,
  role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  org_level ENUM('ceo', 'division_head', 'department_head', 'team_lead', 'employee') NOT NULL DEFAULT 'employee',
  department_id CHAR(36) NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY users_email_unique (email),
  UNIQUE KEY users_user_code_unique (user_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS divisions (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  manager_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY divisions_name_unique (name),
  UNIQUE KEY divisions_manager_unique (manager_user_id),
  CONSTRAINT divisions_manager_fk
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS departments (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  division_id CHAR(36) COLLATE utf8mb4_0900_ai_ci NULL,
  manager_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY departments_name_unique (name),
  UNIQUE KEY departments_manager_unique (manager_user_id),
  KEY departments_division (division_id),
  CONSTRAINT departments_division_fk
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT departments_manager_fk
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS teams (
  id CHAR(36) PRIMARY KEY,
  department_id CHAR(36) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  name VARCHAR(120) NOT NULL,
  manager_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY teams_department_name_unique (department_id, name),
  UNIQUE KEY teams_manager_unique (manager_user_id),
  CONSTRAINT teams_department_fk
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT teams_manager_fk
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS team_members (
  user_id INT NOT NULL PRIMARY KEY,
  team_id CHAR(36) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  CONSTRAINT team_members_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT team_members_team_fk
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id INT NOT NULL,
  permission VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, permission),
  CONSTRAINT user_permissions_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS meeting_rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY meeting_rooms_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS meetings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  meeting_date CHAR(10) NOT NULL,
  end_date CHAR(10) NOT NULL,
  start_time CHAR(5) NOT NULL,
  end_time CHAR(5) NOT NULL,
  room_id INT UNSIGNED NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recurrence_type ENUM('single', 'overnight', 'weekly') NOT NULL DEFAULT 'single',
  recurrence_group_id CHAR(36) NULL,
  recurrence_days TEXT NULL,
  recurrence_end_date CHAR(10) NULL,
  CONSTRAINT meetings_room_fk FOREIGN KEY (room_id) REFERENCES meeting_rooms(id),
  CONSTRAINT meetings_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
  KEY meetings_room_time (room_id, meeting_date, start_time, end_date, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS meeting_participants (
  meeting_id INT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (meeting_id, user_id),
  KEY meeting_participants_user (user_id, meeting_id),
  CONSTRAINT meeting_participants_meeting_fk
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT meeting_participants_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS meeting_booking_lock (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT IGNORE INTO meeting_booking_lock (id) VALUES (1);

CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  file_path TEXT NOT NULL,
  owner_id INT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT documents_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id),
  KEY documents_owner_completed (owner_id, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS workflow_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  current_user_id INT NOT NULL,
  current_document_id INT UNSIGNED NULL,
  finalized TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT workflow_state_user_fk FOREIGN KEY (current_user_id) REFERENCES users(id),
  CONSTRAINT workflow_state_document_fk
    FOREIGN KEY (current_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS owner_workflows (
  owner_id INT NOT NULL PRIMARY KEY,
  current_document_id INT UNSIGNED NULL,
  finalized TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT owner_workflows_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT owner_workflows_document_fk
    FOREIGN KEY (current_document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS document_signers (
  document_id INT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  sequence INT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, user_id),
  UNIQUE KEY document_signers_order (document_id, sequence),
  CONSTRAINT document_signers_document_fk
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT document_signers_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS seal_positions (
  user_id INT NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  page INT UNSIGNED NOT NULL DEFAULT 1,
  x DOUBLE NOT NULL,
  y DOUBLE NOT NULL,
  width DOUBLE NOT NULL,
  height DOUBLE NOT NULL,
  rotation DOUBLE NOT NULL DEFAULT 0,
  signed_at DATETIME NULL,
  PRIMARY KEY (user_id, document_id),
  CONSTRAINT seal_positions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT seal_positions_document_fk
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS completed_seals (
  document_id INT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  user_name VARCHAR(120) NOT NULL,
  seal_text VARCHAR(3) NOT NULL,
  signature_name VARCHAR(120) NOT NULL,
  color CHAR(7) NOT NULL,
  page INT UNSIGNED NOT NULL,
  x DOUBLE NOT NULL,
  y DOUBLE NOT NULL,
  width DOUBLE NOT NULL,
  height DOUBLE NOT NULL,
  rotation DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (document_id, user_id),
  CONSTRAINT completed_seals_document_fk
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT completed_seals_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS workflow_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message TEXT NOT NULL,
  owner_id INT NULL,
  document_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT workflow_events_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT workflow_events_document_fk
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS gate_permits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requester_id INT NOT NULL,
  approver_id INT NOT NULL,
  guard_id INT NOT NULL,
  direction ENUM('out', 'in') NOT NULL,
  permit_date CHAR(10) NOT NULL,
  permit_time CHAR(5) NOT NULL,
  return_time CHAR(5) NULL,
  purpose VARCHAR(500) NOT NULL,
  destination VARCHAR(160) NOT NULL,
  vehicle_info VARCHAR(160) NOT NULL DEFAULT '',
  status ENUM('pending_approval', 'pending_guard', 'approved', 'rejected')
    NOT NULL DEFAULT 'pending_approval',
  approver_note VARCHAR(500) NOT NULL DEFAULT '',
  guard_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  guard_confirmed_at DATETIME NULL,
  KEY gate_permits_requester (requester_id, created_at),
  KEY gate_permits_approver (approver_id, status, permit_date),
  KEY gate_permits_guard (guard_id, status, permit_date),
  CONSTRAINT gate_permits_requester_fk FOREIGN KEY (requester_id) REFERENCES users(id),
  CONSTRAINT gate_permits_approver_fk FOREIGN KEY (approver_id) REFERENCES users(id),
  CONSTRAINT gate_permits_guard_fk FOREIGN KEY (guard_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS gate_permit_approval_steps (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  permit_id INT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  sequence INT UNSIGNED NOT NULL,
  is_hr TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  note VARCHAR(500) NOT NULL DEFAULT '',
  decided_at DATETIME NULL,
  UNIQUE KEY gate_permit_approval_steps_order (permit_id, sequence),
  KEY gate_permit_approval_steps_user (user_id, status),
  CONSTRAINT gate_permit_approval_steps_permit_fk
    FOREIGN KEY (permit_id) REFERENCES gate_permits(id) ON DELETE CASCADE,
  CONSTRAINT gate_permit_approval_steps_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY auth_sessions_expiry (expires_at),
  CONSTRAINT auth_sessions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
