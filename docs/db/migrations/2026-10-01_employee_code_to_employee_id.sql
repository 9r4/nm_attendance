-- 列名・テーブル名の変更（2026-10-01、決定事項 D-040）
--   employee_code            → employee_id        （employees、login_accounts、attendances、attendance_requests）
--   reviewer_code            → reviewer_id        （attendance_requests）
--   employee_code_sequences  → employee_id_sequences
--
-- すでに docs/db/schema.sql（変更前）でテーブルを作成済みのDBを、データを残したまま更新するための SQL。
-- CHECK 制約が改名する列を参照しているため、いったん外してから改名し、付け直す。
-- データの値（例：2026001）は変わらない。

-- 1. CHECK 制約を外す
ALTER TABLE login_accounts DROP CHECK chk_login_accounts_role;
ALTER TABLE attendance_requests DROP CHECK chk_requests_not_self_review;

-- 2. 列名を変更する（外部キーは自動で追従する）
ALTER TABLE employees RENAME COLUMN employee_code TO employee_id;
ALTER TABLE login_accounts RENAME COLUMN employee_code TO employee_id;
ALTER TABLE attendances RENAME COLUMN employee_code TO employee_id;
ALTER TABLE attendance_requests RENAME COLUMN employee_code TO employee_id;
ALTER TABLE attendance_requests RENAME COLUMN reviewer_code TO reviewer_id;

-- 3. テーブル名を変更する
RENAME TABLE employee_code_sequences TO employee_id_sequences;

-- 4. CHECK 制約を付け直す
ALTER TABLE login_accounts ADD CONSTRAINT chk_login_accounts_role CHECK (
  (role = 'system_admin' AND employee_id IS NULL)
  OR (role <> 'system_admin' AND employee_id IS NOT NULL AND employee_id = login_id)
);
ALTER TABLE attendance_requests ADD CONSTRAINT chk_requests_not_self_review CHECK (
  reviewer_id IS NULL OR reviewer_id <> employee_id
);
