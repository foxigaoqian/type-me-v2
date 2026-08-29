-- TYPE ME 人格内容管理：稳定类型 + 草稿/发布/历史版本。
-- active=0 仅停用数据库文案覆盖，计分仍可返回该 TYPE，并回退到代码默认文案。

CREATE TABLE IF NOT EXISTS personality_types (
  type_id VARCHAR(16) PRIMARY KEY,
  personality_key VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  published_revision_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_personality_type_active (active, type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS personality_content_revisions (
  revision_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type_id VARCHAR(16) NOT NULL,
  version INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
  name VARCHAR(64) NOT NULL,
  main_meme VARCHAR(255) NOT NULL,
  identity_card_meme VARCHAR(255) NOT NULL,
  friend_meme VARCHAR(255) NOT NULL,
  tshirt_copy VARCHAR(255) NOT NULL,
  share_copy VARCHAR(512) NOT NULL,
  content_json LONGTEXT NOT NULL,
  created_by VARCHAR(64) NOT NULL DEFAULT 'admin',
  updated_by VARCHAR(64) NOT NULL DEFAULT 'admin',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  published_at DATETIME(3) NULL,
  UNIQUE KEY uq_personality_content_version (type_id, version),
  INDEX idx_personality_content_status (type_id, status, updated_at),
  CONSTRAINT fk_personality_content_type FOREIGN KEY (type_id)
    REFERENCES personality_types(type_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
