-- TYPE ME 宿舍人格挑战 MVP
-- 4 人固定小队：创建者 + 3 位室友。报告只在 4 人都完成测试后生成。

CREATE TABLE IF NOT EXISTS dorms (
  dorm_id VARCHAR(64) PRIMARY KEY,
  invite_code VARCHAR(16) NOT NULL UNIQUE,
  creator_uid VARCHAR(64) NOT NULL,
  name VARCHAR(64) NOT NULL DEFAULT '我的宿舍',
  status VARCHAR(24) NOT NULL DEFAULT 'OPEN',
  member_limit TINYINT UNSIGNED NOT NULL DEFAULT 4,
  report_json LONGTEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_dorm_creator (creator_uid, created_at),
  INDEX idx_dorm_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dorm_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dorm_id VARCHAR(64) NOT NULL,
  uid VARCHAR(64) NOT NULL,
  slot_no TINYINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'COMPLETE',
  attempt_id VARCHAR(64) NOT NULL,
  primary_personality VARCHAR(32) NOT NULL,
  secondary_personality VARCHAR(32) NOT NULL,
  joined_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_dorm_uid (dorm_id, uid),
  UNIQUE KEY uq_dorm_slot (dorm_id, slot_no),
  INDEX idx_dorm_member_status (dorm_id, status),
  CONSTRAINT fk_dorm_member_dorm FOREIGN KEY (dorm_id) REFERENCES dorms(dorm_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
