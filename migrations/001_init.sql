-- TYPE ME V2 P0 schema. MySQL 8 / MariaDB 10.5+.
-- Apply on an empty database before enabling DB_DSN.

CREATE TABLE IF NOT EXISTS test_attempts (
  attempt_id VARCHAR(64) PRIMARY KEY,
  uid VARCHAR(64) NOT NULL,
  session_id VARCHAR(64) NOT NULL DEFAULT '',
  source VARCHAR(64) NOT NULL DEFAULT 'direct',
  campaign VARCHAR(128) NOT NULL DEFAULT '',
  school_id VARCHAR(128) NOT NULL DEFAULT '',
  creator_id VARCHAR(128) NOT NULL DEFAULT '',
  referrer_id VARCHAR(64) NOT NULL DEFAULT '',
  share_id VARCHAR(64) NOT NULL DEFAULT '',
  started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  INDEX idx_attempt_uid (uid),
  INDEX idx_attempt_source (source, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS test_answers (
  attempt_id VARCHAR(64) NOT NULL,
  question_id VARCHAR(16) NOT NULL,
  answer_index TINYINT UNSIGNED NOT NULL,
  answered_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (attempt_id, question_id),
  CONSTRAINT fk_answer_attempt FOREIGN KEY (attempt_id) REFERENCES test_attempts(attempt_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS test_results (
  result_id VARCHAR(64) PRIMARY KEY,
  attempt_id VARCHAR(64) NOT NULL UNIQUE,
  uid VARCHAR(64) NOT NULL,
  primary_personality VARCHAR(32) NOT NULL,
  secondary_personality VARCHAR(32) NOT NULL,
  answers_json LONGTEXT NOT NULL,
  scores_json LONGTEXT NOT NULL,
  completed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_result_primary (primary_personality, completed_at),
  CONSTRAINT fk_result_attempt FOREIGN KEY (attempt_id) REFERENCES test_attempts(attempt_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  event_id VARCHAR(64) PRIMARY KEY,
  anonymous_user_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL DEFAULT '',
  session_id VARCHAR(64) NOT NULL DEFAULT '',
  event_name VARCHAR(64) NOT NULL,
  occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  source VARCHAR(64) NOT NULL DEFAULT 'direct',
  campaign VARCHAR(128) NOT NULL DEFAULT '',
  school_id VARCHAR(128) NOT NULL DEFAULT '',
  creator_id VARCHAR(128) NOT NULL DEFAULT '',
  referrer_id VARCHAR(64) NOT NULL DEFAULT '',
  share_id VARCHAR(64) NOT NULL DEFAULT '',
  primary_personality VARCHAR(32) NOT NULL DEFAULT '',
  secondary_personality VARCHAR(32) NOT NULL DEFAULT '',
  product_id VARCHAR(64) NOT NULL DEFAULT '',
  sku_id VARCHAR(128) NOT NULL DEFAULT '',
  order_id VARCHAR(64) NOT NULL DEFAULT '',
  metadata_json LONGTEXT NULL,
  INDEX idx_event_name_time (event_name, occurred_at),
  INDEX idx_event_source_time (source, occurred_at),
  INDEX idx_event_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shares (
  share_id VARCHAR(64) PRIMARY KEY,
  referrer_id VARCHAR(64) NOT NULL,
  session_id VARCHAR(64) NOT NULL DEFAULT '',
  primary_personality VARCHAR(32) NOT NULL DEFAULT '',
  secondary_personality VARCHAR(32) NOT NULL DEFAULT '',
  share_url VARCHAR(1024) NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'result',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_share_referrer (referrer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  product_id VARCHAR(64) PRIMARY KEY,
  personality_key VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  price_fen INT UNSIGNED NOT NULL,
  regular_price_fen INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS skus (
  sku_id VARCHAR(128) PRIMARY KEY,
  product_id VARCHAR(64) NOT NULL,
  color VARCHAR(32) NOT NULL,
  size VARCHAR(16) NOT NULL,
  stock_on_hand INT UNSIGNED NOT NULL DEFAULT 0,
  stock_reserved INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_product_color_size (product_id, color, size),
  CONSTRAINT fk_sku_product FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  out_trade_no VARCHAR(64) PRIMARY KEY,
  uid VARCHAR(64) NOT NULL,
  status VARCHAR(40) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT '',
  amount_fen INT UNSIGNED NOT NULL,
  receiver_name VARCHAR(128) NOT NULL DEFAULT '',
  receiver_phone VARCHAR(64) NOT NULL DEFAULT '',
  receiver_address VARCHAR(512) NOT NULL DEFAULT '',
  quiz_result VARCHAR(255) NOT NULL DEFAULT '',
  primary_personality VARCHAR(32) NOT NULL DEFAULT '',
  transaction_id VARCHAR(128) NULL UNIQUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  paid_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_order_uid (uid, created_at),
  INDEX idx_order_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  out_trade_no VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  sku_id VARCHAR(128) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  unit_price_fen INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL,
  CONSTRAINT fk_item_order FOREIGN KEY (out_trade_no) REFERENCES orders(out_trade_no) ON DELETE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(product_id),
  CONSTRAINT fk_item_sku FOREIGN KEY (sku_id) REFERENCES skus(sku_id),
  INDEX idx_item_order (out_trade_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(191) NOT NULL UNIQUE,
  out_trade_no VARCHAR(64) NOT NULL,
  transaction_id VARCHAR(128) NOT NULL DEFAULT '',
  trade_state VARCHAR(40) NOT NULL,
  payload_json LONGTEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_payment_order (out_trade_no),
  CONSTRAINT fk_payment_order FOREIGN KEY (out_trade_no) REFERENCES orders(out_trade_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refunds (
  out_refund_no VARCHAR(64) PRIMARY KEY,
  out_trade_no VARCHAR(64) NOT NULL,
  refund_fen INT UNSIGNED NOT NULL,
  total_fen INT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_refund_order (out_trade_no),
  CONSTRAINT fk_refund_order FOREIGN KEY (out_trade_no) REFERENCES orders(out_trade_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
