-- 勤怠管理システム スキーマ案（承認待ち）
-- 対象: MySQL（InnoDB / utf8mb4）。時刻は日本時間(JST)で保持する（BR-004）。
-- 接続時に `SET time_zone = '+09:00'` を実行すること。

SET NAMES utf8mb4;

-- 部署マスタ
CREATE TABLE departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '部署ID',
  name        VARCHAR(50)  NOT NULL COMMENT '部署名',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '利用可否(1=有効,0=無効)',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部署マスタ';

-- 社員コード採番用（年ごとの連番カウンタ）
CREATE TABLE employee_code_sequences (
  year         SMALLINT UNSIGNED NOT NULL COMMENT '採番年(yyyy)',
  last_number  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'その年に発行した最後の通番(1〜)',
  PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社員コード採番用カウンタ';

-- 社員
CREATE TABLE employees (
  employee_code  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '社員コード(例:2026001, 2026A01)',
  name           VARCHAR(100) NOT NULL COMMENT '氏名',
  email          VARCHAR(254) NOT NULL COMMENT 'メールアドレス',
  department_id  INT UNSIGNED NOT NULL COMMENT '部署ID',
  hire_date      DATE         NOT NULL COMMENT '入社日',
  leave_date     DATE         NULL     COMMENT '退社日(在籍中はNULL)',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_code),
  UNIQUE KEY uq_employees_email (email),
  KEY idx_employees_department (department_id),
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社員';

-- ログイン情報（ログインID＝社員コード）
CREATE TABLE login_accounts (
  login_id       VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'ログインID(=社員コード)',
  password_hash  VARCHAR(255) NOT NULL COMMENT 'パスワードハッシュ(平文は保存しない)',
  role           ENUM('employee','admin') NOT NULL DEFAULT 'employee' COMMENT '権限(社員/管理者)',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '利用可否(1=可,0=不可)',
  last_login_at  DATETIME     NULL COMMENT '最終ログイン日時',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (login_id),
  CONSTRAINT fk_login_accounts_employee FOREIGN KEY (login_id) REFERENCES employees (employee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ログイン情報';

-- 勤怠情報（1社員×1日＝1行。BR-005）
CREATE TABLE attendance_records (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_code  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '社員コード',
  work_date      DATE         NOT NULL COMMENT '勤務日',
  clock_in_at    DATETIME     NULL COMMENT '出勤時刻',
  clock_out_at   DATETIME     NULL COMMENT '退勤時刻',
  break_start_at DATETIME     NULL COMMENT '休憩開始(昼休憩とは別。1日1回)',
  break_end_at   DATETIME     NULL COMMENT '休憩終了',
  overtime_end_at DATETIME    NULL COMMENT '承認済みの残業終了時刻',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_employee_date (employee_code, work_date),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_code) REFERENCES employees (employee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='勤怠情報';

-- 申請（一時領域）。承認されるまで attendance_records に反映しない。
CREATE TABLE attendance_requests (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_code          VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '申請者',
  target_date            DATE       NOT NULL COMMENT '対象日',
  request_type           ENUM('correction','overtime') NOT NULL COMMENT '種別(correction=修正・新規登録,overtime=残業)',
  requested_clock_in_at  DATETIME   NULL COMMENT '申請: 出勤時刻(correction)',
  requested_clock_out_at DATETIME   NULL COMMENT '申請: 退勤時刻(correction)',
  requested_break_start_at DATETIME NULL COMMENT '申請: 休憩開始(correction)',
  requested_break_end_at DATETIME   NULL COMMENT '申請: 休憩終了(correction)',
  requested_overtime_end_at DATETIME NULL COMMENT '申請: 残業終了時刻(overtime)',
  reason                 VARCHAR(500) NOT NULL COMMENT '申請理由',
  status                 ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '状態(申請中/承認済み/却下)',
  reviewer_code          VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '承認・却下した管理者',
  review_comment         VARCHAR(500) NULL COMMENT '却下コメント(任意)',
  reviewed_at            DATETIME   NULL COMMENT '承認・却下日時',
  -- BR-002: 申請中(pending)の同一申請を1件に制限するための補助列（pending以外はNULLで重複を許容）
  pending_key            TINYINT(1) GENERATED ALWAYS AS (IF(status = 'pending', 1, NULL)) STORED,
  created_at             DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '申請日時',
  updated_at             DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requests_pending (employee_code, target_date, request_type, pending_key),
  KEY idx_requests_status (status, created_at),
  KEY idx_requests_employee_date (employee_code, target_date),
  CONSTRAINT fk_requests_employee FOREIGN KEY (employee_code) REFERENCES employees (employee_code),
  CONSTRAINT fk_requests_reviewer FOREIGN KEY (reviewer_code) REFERENCES employees (employee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申請(一時領域)';

-- 勤務設定（全社共通の1行のみ。id=1 固定）
CREATE TABLE work_settings (
  id              TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '常に1',
  standard_start  TIME NOT NULL DEFAULT '09:00:00' COMMENT '標準始業時刻(※要確認)',
  standard_end    TIME NOT NULL DEFAULT '18:00:00' COMMENT '標準終業時刻',
  lunch_start     TIME NOT NULL DEFAULT '12:00:00' COMMENT '昼休憩開始',
  lunch_end       TIME NOT NULL DEFAULT '13:00:00' COMMENT '昼休憩終了',
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='勤務設定';

INSERT INTO work_settings (id) VALUES (1);
