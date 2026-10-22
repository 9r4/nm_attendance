# コードを読む（本編）

このシステムのコードを、**ファイルごとに上から順に読み**、「このコードはこういう動きをする」「ここが重要」をエンジニアの目で解説する。

- 対象：2026-10-22 時点の `main`。コードの抜き出しは実際のファイルからの写し（長い部分は `…` で省略）。
- 各章の形：このファイルの役割 → 全体の構成 → コードを上から読む（使っている言語の書き方の説明つき）→ **ここが重要** → まとめ → 関連するファイル。
- 仕組みを機能ごとに横断して説明した資料は、[../README.md](../README.md) の「仕組み編」。

## おすすめの読む順番

最初に「1. 入口と共通の部品」を読むと、ほかのファイルが読みやすくなる。そのあとは、興味のある画面から読んでよい。1つの画面は「画面の PHP → API → ルール（`src/`）→ SQL（`src/queries/`）→ JavaScript」の順に読むと、処理の流れのとおりになる。

### 1. 入口と共通の部品

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `src/functions.php` | [src_functions.md](./src_functions.md) | `h()`・`appUrl()`・`assetUrl()` など、どこでも使う関数 |
| `src/errors.php` | [src_errors.md](./src_errors.md) | 想定外のエラーを、ログに残して定型の文を出す |
| `src/dbconnect.php` | [src_dbconnect.md](./src_dbconnect.md) | DB に接続して `$pdo` を作る |
| `src/session.php` | [src_session.md](./src_session.md) | セッション、CSRF 対策、1回だけの通知 |
| `src/auth.php` | [src_auth.md](./src_auth.md) | ログイン、ログイン中の利用者、権限 |
| `login.php` | [login.md](./login.md) | ログイン画面 |
| `logout.php` | [logout.md](./logout.md) | ログアウト |
| `templates/`・`js/menu.js` | [templates.md](./templates.md) | 画面の上下の共通部分とメニュー |

### 2. メイン画面（出退勤）

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `index.php` | [index.md](./index.md) | メイン画面の PHP |
| `api/attendance.php` | [api_attendance.md](./api_attendance.md) | ボタンを押したときの受け口 |
| `src/attendance.php` | [src_attendance.md](./src_attendance.md) | 状態・押せるか・押したときの処理 |
| `js/main.js` | [js_main.md](./js_main.md) | 時計、ボタン、簡単残業申請 |

### 3. 記録表示と申請

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `records/index.php` | [records_index.md](./records_index.md) | 記録表示の PHP |
| `src/records.php` | [src_records.md](./src_records.md) | 月の決め方、労働時間、カレンダー |
| `src/holidays.php` | [src_holidays.md](./src_holidays.md) | 祝日の計算 |
| `js/records.js` | [js_records.md](./js_records.md) | 日付のウィンドウ、申請ウィンドウ、取り消し |
| `api/requests.php` | [api_requests.md](./api_requests.md) | 申請の受け口 |
| `src/requests.php` | [src_requests.md](./src_requests.md) | 申請の入力の確認・保存・表示 |

### 4. 承認

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `approvals/index.php` | [approvals_index.md](./approvals_index.md) | 承認画面の PHP |
| `api/approvals.php` | [api_approvals.md](./api_approvals.md) | 承認・却下の受け口 |
| `src/approvals.php` | [src_approvals.md](./src_approvals.md) | 承認範囲、承認・却下のトランザクション |
| `js/approvals.js` | [js_approvals.md](./js_approvals.md) | 承認・却下のボタンとウィンドウ |

### 5. システム管理者

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `admin/accounts.php` | [admin_accounts.md](./admin_accounts.md) | アカウント管理の画面 |
| `admin/departments.php`・`admin/settings.php` | [admin_departments_settings.md](./admin_departments_settings.md) | 部署・勤務設定の画面 |
| `src/admin.php` | [src_admin.md](./src_admin.md) | 入力の確認、社員コードの採番、トランザクション |
| `js/admin.js` | [js_admin.md](./js_admin.md) | 確認のウィンドウ、承認範囲の出し分け |

### 6. SQL（`src/queries/`）

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `attendances.php` | [src_queries_attendances.md](./src_queries_attendances.md) | 勤怠の読み書き |
| `attendance_requests.php` | [src_queries_attendance_requests.md](./src_queries_attendance_requests.md) | 申請の読み書き、承認の一覧とロック |
| `employees.php`・`login_accounts.php` | [src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md) | 社員とログイン情報 |
| `departments.php`・`employee_id_sequences.php`・`work_settings.php` | [src_queries_departments_sequences_work_settings.md](./src_queries_departments_sequences_work_settings.md) | 部署、採番、勤務設定 |

### 7. 見た目とテスト

| ファイル | 章 | 一言で |
| --- | --- | --- |
| `css/style.css` | [css_style.md](./css_style.md) | 見た目のすべて（読むための地図と、押さえる書き方） |
| `tests/` | [tests.md](./tests.md) | 自動テストの書き方 |
