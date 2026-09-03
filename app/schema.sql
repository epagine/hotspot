-- Referência legada (SQLite). Instalação atual usa MySQL via /instalar + ensure_core_schema() + migrations.
CREATE TABLE IF NOT EXISTS settings (
  k TEXT NOT NULL PRIMARY KEY,
  v TEXT
);

CREATE TABLE IF NOT EXISTS stores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  city TEXT NOT NULL DEFAULT '',
  token TEXT NOT NULL UNIQUE,
  pending_command TEXT,
  pending_command_id TEXT,
  last_seen_at TEXT,
  last_status TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  billing_status TEXT NOT NULL DEFAULT 'em_dia',
  plan TEXT NOT NULL DEFAULT 'mensal',
  monthly_fee TEXT NOT NULL DEFAULT '',
  paid_until TEXT NOT NULL DEFAULT '',
  contact TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  auto_billing INTEGER NOT NULL DEFAULT 1,
  portal_email TEXT NOT NULL DEFAULT '',
  portal_pass_hash TEXT NOT NULL DEFAULT '',
  portal_enabled INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS store_settings (
  store_id INTEGER NOT NULL,
  k TEXT NOT NULL,
  v TEXT,
  PRIMARY KEY (store_id, k)
);

CREATE TABLE IF NOT EXISTS clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  store_id INTEGER NOT NULL DEFAULT 1,
  ip TEXT NOT NULL,
  mac TEXT,
  phone TEXT,
  status_code TEXT NOT NULL,
  status_text TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'pending',
  user_agent TEXT,
  created_at TEXT NOT NULL,
  authorized_at TEXT,
  expires_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_ip_state ON clients (ip, state);
CREATE INDEX IF NOT EXISTS idx_code ON clients (status_code);
CREATE INDEX IF NOT EXISTS idx_clients_store ON clients (store_id, id);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  store_id INTEGER NOT NULL,
  reference_id TEXT NOT NULL UNIQUE,
  checkout_id TEXT NOT NULL DEFAULT '',
  pay_url TEXT NOT NULL DEFAULT '',
  amount_cents INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending',
  raw TEXT,
  created_at TEXT NOT NULL,
  paid_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_payments_store ON payments (store_id, id);
