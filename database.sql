PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  seal_text TEXT NOT NULL,
  signature_name TEXT NOT NULL,
  color TEXT NOT NULL,
  email TEXT,
  password_hash TEXT,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'member')),
  department_id TEXT,
  active INTEGER NOT NULL DEFAULT 0 CHECK (active IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS departments (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL COLLATE NOCASE UNIQUE,
  manager_user_id TEXT UNIQUE REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  permission TEXT NOT NULL,
  PRIMARY KEY (user_id, permission)
);

CREATE TABLE IF NOT EXISTS documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  file_path TEXT NOT NULL,
  owner_id TEXT REFERENCES users(id),
  uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TEXT
);

CREATE TABLE IF NOT EXISTS seal_positions (
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  page INTEGER NOT NULL DEFAULT 1 CHECK (page > 0),
  x REAL NOT NULL,
  y REAL NOT NULL,
  width REAL NOT NULL,
  height REAL NOT NULL,
  rotation REAL NOT NULL DEFAULT 0,
  signed_at TEXT,
  PRIMARY KEY (user_id, document_id)
);

CREATE TABLE IF NOT EXISTS completed_seals (
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  user_id TEXT NOT NULL REFERENCES users(id),
  user_name TEXT NOT NULL,
  seal_text TEXT NOT NULL,
  signature_name TEXT NOT NULL,
  color TEXT NOT NULL,
  page INTEGER NOT NULL CHECK (page > 0),
  x REAL NOT NULL,
  y REAL NOT NULL,
  width REAL NOT NULL,
  height REAL NOT NULL,
  rotation REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (document_id, user_id)
);

CREATE TABLE IF NOT EXISTS workflow_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  current_user_id TEXT NOT NULL REFERENCES users(id),
  current_document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
  finalized INTEGER NOT NULL DEFAULT 0 CHECK (finalized IN (0, 1))
);

CREATE TABLE IF NOT EXISTS workflow_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message TEXT NOT NULL,
  owner_id TEXT REFERENCES users(id),
  document_id INTEGER REFERENCES documents(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS meeting_rooms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL COLLATE NOCASE UNIQUE,
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS meetings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  meeting_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  room_id INTEGER REFERENCES meeting_rooms(id),
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recurrence_type TEXT NOT NULL DEFAULT 'single',
  recurrence_group_id TEXT,
  recurrence_days TEXT,
  recurrence_end_date TEXT
);

CREATE TABLE IF NOT EXISTS meeting_participants (
  meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  user_id TEXT NOT NULL REFERENCES users(id),
  PRIMARY KEY (meeting_id, user_id)
);

CREATE TABLE IF NOT EXISTS gate_permits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requester_id TEXT NOT NULL REFERENCES users(id),
  approver_id TEXT NOT NULL REFERENCES users(id),
  guard_id TEXT NOT NULL REFERENCES users(id),
  direction TEXT NOT NULL CHECK (direction IN ('out', 'in')),
  permit_date TEXT NOT NULL,
  permit_time TEXT NOT NULL,
  return_time TEXT,
  purpose TEXT NOT NULL,
  destination TEXT NOT NULL,
  vehicle_info TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending_approval'
    CHECK (status IN ('pending_approval', 'pending_guard', 'approved', 'rejected')),
  approver_note TEXT NOT NULL DEFAULT '',
  guard_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at TEXT,
  guard_confirmed_at TEXT
);

CREATE TABLE IF NOT EXISTS gate_permit_approval_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  permit_id INTEGER NOT NULL REFERENCES gate_permits(id) ON DELETE CASCADE,
  user_id TEXT NOT NULL REFERENCES users(id),
  sequence INTEGER NOT NULL,
  is_hr INTEGER NOT NULL DEFAULT 0 CHECK (is_hr IN (0, 1)),
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
  note TEXT NOT NULL DEFAULT '',
  decided_at TEXT,
  UNIQUE (permit_id, sequence)
);

CREATE TABLE IF NOT EXISTS auth_sessions (
  token_hash TEXT PRIMARY KEY,
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS owner_workflows (
  owner_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  current_document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
  finalized INTEGER NOT NULL DEFAULT 0 CHECK (finalized IN (0, 1))
);

CREATE TABLE IF NOT EXISTS document_signers (
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  user_id TEXT NOT NULL REFERENCES users(id),
  sequence INTEGER NOT NULL CHECK (sequence >= 0),
  PRIMARY KEY (document_id, user_id),
  UNIQUE (document_id, sequence)
);

CREATE INDEX IF NOT EXISTS auth_sessions_expiry
  ON auth_sessions(expires_at);
CREATE INDEX IF NOT EXISTS document_signers_order
  ON document_signers(document_id, sequence);
CREATE INDEX IF NOT EXISTS gate_permit_approval_steps_user
  ON gate_permit_approval_steps(user_id, status);
CREATE INDEX IF NOT EXISTS meeting_participants_user
  ON meeting_participants(user_id, meeting_id);
CREATE INDEX IF NOT EXISTS gate_permits_requester
  ON gate_permits(requester_id, created_at DESC);
CREATE INDEX IF NOT EXISTS gate_permits_approver
  ON gate_permits(approver_id, status, permit_date);
CREATE INDEX IF NOT EXISTS gate_permits_guard
  ON gate_permits(guard_id, status, permit_date);
