-- 申請の理由を、必須（NOT NULL）から任意（NULL 可）に変更（2026-10-01、決定事項 D-041）
--   attendance_requests.reason
--
-- 修正・新規登録の申請の理由は任意。簡単残業申請は、理由の入力欄が無い（NULL になる）。
-- すでにテーブルを作成済みのDBを、データを残したまま更新するための SQL。既存のデータは変わらない。

ALTER TABLE attendance_requests
  MODIFY reason VARCHAR(500) NULL COMMENT '申請理由(任意。簡単残業申請はNULL)';
