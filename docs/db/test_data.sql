-- 確認用のテストデータ（PR 2 以降の動作確認用。2026-10-13）
-- パスワードは、どれも Test1234（テスト専用。本番の運用では使わない）
-- 社員 ID は 2025 年の番号にする（2026 年の番号は、PR 7 の自動採番と重なるため）
-- 1回だけ実行する（2回目は、重複のエラーになる）

INSERT INTO departments (name) VALUES ('営業部'), ('開発部');

INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES
  ('2025001', '山田 太郎', 'yamada@example.com', (SELECT id FROM departments WHERE name = '営業部'), '2025-04-01'),
  ('2025002', '佐藤 花子', 'sato@example.com',   (SELECT id FROM departments WHERE name = '営業部'), '2025-04-01'),
  ('2025003', '鈴木 一郎', 'suzuki@example.com', (SELECT id FROM departments WHERE name = '開発部'), '2025-04-01'),
  ('2025004', '高橋 次郎', 'takahashi@example.com', (SELECT id FROM departments WHERE name = '開発部'), '2025-04-01');

INSERT INTO login_accounts (login_id, employee_id, password_hash, role, approval_scope, is_active) VALUES
  ('2025001', '2025001', '$2y$10$Wym.MkHpWDNPV3umkOBDQeO3TTq2PNZDiNOYieKVw7.jk9BcKBwue', 'employee', 'department', 1),
  ('2025002', '2025002', '$2y$10$Wym.MkHpWDNPV3umkOBDQeO3TTq2PNZDiNOYieKVw7.jk9BcKBwue', 'manager', 'department', 1),
  ('2025003', '2025003', '$2y$10$Wym.MkHpWDNPV3umkOBDQeO3TTq2PNZDiNOYieKVw7.jk9BcKBwue', 'manager', 'all', 1),
  ('2025004', '2025004', '$2y$10$Wym.MkHpWDNPV3umkOBDQeO3TTq2PNZDiNOYieKVw7.jk9BcKBwue', 'employee', 'department', 0);
