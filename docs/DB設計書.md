# DB設計書（案・承認待ち）

状態: **案。依頼者の承認後に確定する**（承認後、決定事項.md に記録し、実装は PR 運用）。
DDL: [db/schema.sql](./db/schema.sql)（MariaDB 10.11 で作成と制約の動作を検証済み。本番は MySQL のため、DB バージョン確認後に再検証する）

## 1. 前提（決定済み）

| 項目 | 内容 | 参照 |
| --- | --- | --- |
| DBMS | MySQL（InnoDB / utf8mb4）。DB名 `attendance_traning`、ユーザー `attendance_app@localhost` | D-005 |
| 勤怠の粒度 | 1社員×1日＝1行 | D-006 |
| 休憩 | 1日1回。昼休憩（既定12:00〜13:00）は別扱い | D-007, D-009 |
| 標準労働時間 | 全社共通の1パターンを設定テーブルで管理 | D-008 |
| 申請 | 1テーブルで種別を持つ。承認済み・却下も履歴として残す | D-010 |
| ログインID | ＝社員コード。権限はログイン情報に持つ | D-011 |
| 社員コード | 自動採番（作成年＋通番） | D-013 |
| 部署 | 部署マスタを別テーブルにする | D-014 |
| 退職 | 削除せず、利用可否を無効にする | D-015 |
| 承認者 | 管理者が全社員の申請を承認する | D-016 |
| 時刻 | すべて日本時間（BR-004） | - |

## 2. ER図

```mermaid
erDiagram
    departments ||--o{ employees : "所属"
    employees ||--|| login_accounts : "ログインID=社員コード"
    employees ||--o{ attendance_records : "1日1行"
    employees ||--o{ attendance_requests : "申請者"
    employees ||--o{ attendance_requests : "承認者(reviewer)"
    departments { int id PK }
    employees { varchar employee_code PK }
    login_accounts { varchar login_id PK_FK }
    attendance_records { bigint id PK }
    attendance_requests { bigint id PK }
    work_settings { tinyint id PK }
    employee_code_sequences { smallint year PK }
```

## 3. テーブル一覧

| テーブル | 役割 |
| --- | --- |
| departments | 部署マスタ |
| employees | 社員（氏名・メール・部署・入社日・退社日） |
| login_accounts | ログイン情報（ID・パスワードハッシュ・権限・利用可否） |
| attendance_records | 勤怠情報（1社員×1日＝1行） |
| attendance_requests | 申請＝一時領域（修正・新規登録／残業） |
| work_settings | 勤務設定（標準始業・終業、昼休憩）。1行のみ |
| employee_code_sequences | 社員コード採番用の年別カウンタ |

### 3-1. employees

| 列 | 型 | NULL | 説明 |
| --- | --- | --- | --- |
| employee_code | VARCHAR(7) PK | NOT NULL | 社員コード（例：2026001, 2026A01）。重複不可 |
| name | VARCHAR(100) | NOT NULL | 氏名 |
| email | VARCHAR(254) UNIQUE | NOT NULL | メールアドレス |
| department_id | INT FK→departments | NOT NULL | 部署 |
| hire_date | DATE | NOT NULL | 入社日 |
| leave_date | DATE | NULL | 退社日（在籍中は NULL） |
| created_at / updated_at | DATETIME | NOT NULL | 作成・更新日時 |

### 3-2. login_accounts

| 列 | 型 | NULL | 説明 |
| --- | --- | --- | --- |
| login_id | VARCHAR(7) PK, FK→employees | NOT NULL | ログインID（＝社員コード） |
| password_hash | VARCHAR(255) | NOT NULL | パスワードハッシュ（PHP の `password_hash()` の結果を想定。平文は保存しない） |
| role | ENUM('employee','admin') | NOT NULL | 権限（社員／管理者） |
| is_active | TINYINT(1) | NOT NULL | 利用可否（0＝ログイン不可） |
| last_login_at | DATETIME | NULL | 最終ログイン日時 |
| created_at / updated_at | DATETIME | NOT NULL | |

### 3-3. attendance_records

| 列 | 型 | NULL | 説明 |
| --- | --- | --- | --- |
| id | BIGINT PK | NOT NULL | |
| employee_code | VARCHAR(7) FK | NOT NULL | 社員コード |
| work_date | DATE | NOT NULL | 勤務日。**(employee_code, work_date) で一意**（BR-005） |
| clock_in_at | DATETIME | NULL | 出勤時刻 |
| clock_out_at | DATETIME | NULL | 退勤時刻 |
| break_start_at | DATETIME | NULL | 休憩開始（昼休憩とは別） |
| break_end_at | DATETIME | NULL | 休憩終了 |
| overtime_end_at | DATETIME | NULL | 承認済みの残業終了時刻 |
| created_at / updated_at | DATETIME | NOT NULL | |

### 3-4. attendance_requests（一時領域）

| 列 | 型 | NULL | 説明 |
| --- | --- | --- | --- |
| id | BIGINT PK | NOT NULL | |
| employee_code | VARCHAR(7) FK | NOT NULL | 申請者 |
| target_date | DATE | NOT NULL | 対象日 |
| request_type | ENUM('correction','overtime') | NOT NULL | 種別（修正・新規登録／残業） |
| requested_clock_in_at 他4列 | DATETIME | NULL | 申請する時刻（出勤・退勤・休憩開始・休憩終了・残業終了） |
| reason | VARCHAR(500) | NOT NULL | 申請理由 |
| status | ENUM('pending','approved','rejected') | NOT NULL | 申請中／承認済み／却下 |
| reviewer_code | VARCHAR(7) FK | NULL | 承認・却下した管理者 |
| review_comment | VARCHAR(500) | NULL | 却下コメント（任意） |
| reviewed_at | DATETIME | NULL | 承認・却下日時 |
| pending_key | 生成列 | NULL | status='pending' のとき 1、それ以外は NULL。BR-002 の制御用 |
| created_at / updated_at | DATETIME | NOT NULL | 申請日時など |

**BR-002（同じ申請の重複不可）の実現方法**：`UNIQUE (employee_code, target_date, request_type, pending_key)`。申請中のものだけが `pending_key=1` になって一意制約の対象になり、承認済み・却下の履歴は何件あっても残せる。別日の申請は問題ない。

### 3-5. work_settings（1行のみ・id=1）

| 列 | 既定値 | 説明 |
| --- | --- | --- |
| standard_start | 09:00 | 標準始業（※要確認） |
| standard_end | 18:00 | 標準終業。簡単残業申請の基準 |
| lunch_start / lunch_end | 12:00 / 13:00 | 昼休憩。労働時間の計算で自動控除（D-009） |

### 3-6. departments / employee_code_sequences

- departments：`id`, `name`（一意）, `is_active`, `created_at`, `updated_at`
- employee_code_sequences：`year`（PK）, `last_number`

## 4. 主要な処理とテーブルの対応

| 機能 | 処理 |
| --- | --- |
| FR-001 ログイン | `login_accounts` を login_id で取得 → `is_active=1` と `password_verify()` を確認 → `last_login_at` 更新。権限（role）をセッションに保持 |
| FR-003 出退勤 | 当日の行が無ければ INSERT、有れば UPDATE。2回目以降は上書き確認（BR-001）後に UPDATE |
| FR-004 記録表示 | `attendance_records` を社員コードと月で絞り込み、`attendance_requests` の pending を重ねて表示 |
| FR-005/008 申請 | `attendance_requests` に pending で INSERT。一意制約違反を BR-002 のエラーとして扱う |
| FR-006 承認 | トランザクション内で、勤怠を INSERT … ON DUPLICATE KEY UPDATE で反映 → 申請を approved に更新（reviewer_code・reviewed_at）。却下は rejected とコメントのみ更新 |
| FR-007 アカウント作成 | トランザクション内で社員コードを採番 → `employees` と `login_accounts` を INSERT |

### 社員コードの採番ルール（D-013）

- 形式：作成年4桁＋3桁の通番。年が変わると通番は 001 から。
- `employee_code_sequences` の当年の行を `SELECT … FOR UPDATE` で確保し、`last_number` を +1 して n とする（同時作成でも重複しない）。
- n ≤ 999：`yyyy` ＋ n を3桁ゼロ埋め（例：2026001, 2026999）。
- n ≥ 1000：m = n − 1000 とし、英字 = `A`＋(m ÷ 99 の商)、数字 = (m ÷ 99 の余り)＋1 を2桁ゼロ埋め（例：1000番目＝2026A01、1099番目＝2026A99、1100番目＝2026B01）。上限は `Z99`（年間3,573人）。
- 形式の検証：`^[0-9]{4}([0-9]{3}|[A-Z][0-9]{2})$`

### 労働時間の計算（参考）

実労働時間 ＝ 退勤 − 出勤 − 昼休憩（設定の12:00〜13:00のうち勤務時間と重なる分）− 休憩（開始〜終了）。
遅刻 ＝ 出勤 ＞ 標準始業、早退 ＝ 退勤 ＜ 標準終業（ともにDBには持たず、表示時に判定する）。

## 5. 設計判断（Claude の提案・要承認）

| # | 提案 | 理由 |
| --- | --- | --- |
| P-1 | **遅刻・早退はDBに列を持たない**（時刻と標準労働時間の比較で判定） | 時刻から導出でき、列を持つと不整合の元になる。Step4 の遅刻・早退ボタンは作らない |
| P-2 | **休暇は今回のDBに含めない** | 機能要件に無く、仕様も未確定。必要になったら勤怠情報に「区分」列を追加して拡張する |
| P-3 | **管理者も社員として `employees` に登録する**（権限は `login_accounts.role`） | 管理者も出退勤するため |
| P-4 | **修正・新規登録の申請は「その日の4つの時刻の全体」を持つ**（NULL＝空欄にする）。新規かどうかは対象日の勤怠行の有無で決まる | 「一部だけ変更」と「空欄にする」を区別する必要が無く、承認時の反映が単純になる |
| P-5 | **BR-002 の重複判定は「同一社員×対象日×種別の申請中」** | 引継ぎ資料の記述（同一対象日の申請中）を種別単位にした。同じ日の「修正」と「残業」は同時に出せる |
| P-6 | **残業の承認結果は `attendance_records.overtime_end_at` に持つ** | 承認で本番に反映する方針（Step4）に沿う |
| P-7 | **時刻は DATETIME（JST）で保存**し、接続ごとに `SET time_zone='+09:00'` を実行 | BR-004。日跨ぎの勤務にも対応できる |
| P-8 | **パスワードは `password_hash()` でハッシュ化**。アカウントのロック等の不正対策は今回は含めない | 不正対策の要否が未確定のため |

## 6. 要確認

1. **標準始業時刻**：9:00 でよいか（標準終業は 18:00 で確定済み）。
2. **DB名の綴り**：`attendance_traning` は `attendance_training` の誤記ではないか。
3. **MySQL のバージョン**：WSL で `mysql --version` の結果を教えてほしい（生成列・外部キー等の動作確認のため）。
4. 上記 P-1〜P-8 の承認、または修正。
