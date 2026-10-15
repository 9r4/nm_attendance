# DB構築手順書（WSL の MySQL にテーブルを作る）

確定したDB設計（[DB設計書.md](./DB設計書.md)、[db/schema.sql](./db/schema.sql)）を、WSL(Ubuntu) の MySQL に作成する手順。

- DB名：`attendance_training`（作成済み）
- ユーザー：`attendance_app@localhost`（作成済み）
- MySQL：8.4.11
- 対象：7テーブル（departments, employee_id_sequences, employees, login_accounts, attendances, attendance_requests, work_settings）

## 前提（先に済ませておくこと）

**[WSL環境へのソース配置手順書.md](./WSL環境へのソース配置手順書.md) の「0. 事前準備」と「1. 初回配置」を、先に完了させる。**

- 理由：この手順書は、`/var/www/html/attendance` にあるリポジトリ（git clone 済み）から SQL を取り出して使う（0-2）。配置が済んでいないと、`cd` や `git show` が失敗する。
- 確認：次のコマンドで `.git` と `index.html` などが表示されれば、配置済み。

**【ターミナルで実行】**

```bash
ls -A /var/www/html/attendance
```

## 表記のルール

コードブロックの直前の見出しで、**実行する場所**を区別する。コードブロックの中身は、**そのままコピーして貼り付けられる**（プロンプトの `$` や `mysql>` は書いていない）。

| 見出し | 意味 |
| --- | --- |
| **【ターミナルで実行】** | WSL のターミナル（シェル）に貼り付けて実行する |
| **【mysql 内で実行】** | `mysql -u attendance_app -p` で mysql にログインした状態で貼り付けて実行する（`mysql>` の入力待ちの状態） |
| **【出力例（入力しない）】** | 実行結果の見本。入力するものではない |

- mysql に入るコマンドは `mysql -u attendance_app -p`（パスワードを聞かれる）。出るときは `exit`。
- SQL は、末尾の `;` までが1つの命令。複数行をまとめて貼り付けてもよい。

> **WSL のソース配置先 `/var/www/html/attendance` には `docs/` が無い**（sparse-checkout で除外）。そのため、SQL は `git show` でリポジトリから一時ファイルに取り出し、mysql の `SOURCE` で流す（SQL ファイルを Web から見える場所に置かない）。

## 0. 事前確認

### 0-1. DB と権限の確認（mysql に入って）

**【mysql 内で実行】**

```sql
SHOW DATABASES LIKE 'attendance_training';
SHOW GRANTS;
```

- `attendance_training` が表示されること。
- 権限に、`attendance_training` への `CREATE`、`ALTER`、`INDEX`、`REFERENCES`（外部キー用）、`INSERT`、`SELECT`、`UPDATE`、`DELETE`、`DROP` が含まれること（`ALL PRIVILEGES` なら問題ない）。

### 0-2. 最新の SQL を一時ファイルに取り出す（ターミナルで）

mysql から出ている状態（`exit` 済み）で実行する。

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance
git fetch origin
git show origin/main:docs/db/schema.sql > /tmp/schema.sql
head -3 /tmp/schema.sql
```

先頭に `-- 勤怠管理システム スキーマ（…確定…）` と表示されれば OK。

## 1. テーブルの作成

mysql に入って実行する。

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
SOURCE /tmp/schema.sql
```

`Query OK` が何行か表示され、`ERROR` が出なければ成功。7テーブルできていることを確認する。

**【mysql 内で実行】**

```sql
SHOW TABLES;
```

**【出力例（入力しない）】**

```
attendance_requests
attendances
departments
employee_id_sequences
employees
login_accounts
work_settings
```

勤務設定の初期値（始業9:00・終業18:00・昼休憩12:00〜13:00）も、`schema.sql` の最後で登録される。

**【mysql 内で実行】**

```sql
SELECT * FROM work_settings;
```

確認できたら mysql から出て、一時ファイルを削除する。

**【mysql 内で実行】**

```sql
exit
```

**【ターミナルで実行】**

```bash
rm /tmp/schema.sql
```

## 2. 最初のシステム管理者の登録

システム管理者は画面から作れないため、SQL で直接登録する。パスワードは**ハッシュ化**して保存する（平文は保存しない）。

### 2-1. パスワードのハッシュを作る（ターミナルで）

PHP の CLI が必要（`php -v` で確認。無ければ `sudo apt install -y php-cli`）。パスワードは画面に表示されず、シェルの履歴にも残らないように入力する。

**【ターミナルで実行】**

```bash
read -s -p "システム管理者のパスワード: " PW; echo
```

パスワードを入力して Enter を押す（入力中は表示されない）。続けて、次のコマンドでハッシュを表示する。**上のコマンドと一緒に貼り付けないこと**（`read` が次の行をパスワードとして読み込んでしまうため、1行ずつ実行する）。

**【ターミナルで実行】**

```bash
php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT), "\n";' "$PW"; unset PW
```

`$2y$10$…` で始まる60文字前後の文字列が1行表示される。**この1行をコピーしておく**（パスワード自体ではなくハッシュなので、画面に出してよい）。

### 2-2. 登録する（mysql に入って）

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

ログインIDは `sysadmin` とする例（変更してよい。20文字以内の英数字）。`＜コピーしたハッシュ＞` を、2-1 でコピーした1行に置き換える（シングルクォート `'` は残す）。

**【mysql 内で実行】**

```sql
USE attendance_training;
INSERT INTO login_accounts (login_id, employee_id, password_hash, role) VALUES ('sysadmin', NULL, '＜コピーしたハッシュ＞', 'system_admin');
```

確認（ハッシュは表示しない）：

**【mysql 内で実行】**

```sql
SELECT login_id, employee_id, role, is_active FROM login_accounts;
```

**【出力例（入力しない）】**

```
+----------+---------------+--------------+-----------+
| login_id | employee_id | role         | is_active |
+----------+---------------+--------------+-----------+
| sysadmin | NULL          | system_admin |         1 |
+----------+---------------+--------------+-----------+
```

> 社員・管理者（上司・部長等）のアカウントは、アプリ（アカウント作成画面）から、システム管理者が作成する。部署マスタも同様に、アプリから登録する（アプリ実装後）。

## 3. やり直したい場合（開発中のみ・全データが消える）

**本番データがある場合は絶対に実行しない。** mysql に入って、子テーブルから順に削除する（この順序なら外部キーのエラーが出ない）。

**【mysql 内で実行】**

```sql
USE attendance_training;
DROP TABLE attendance_requests, attendances, login_accounts, employee_id_sequences, work_settings, employees, departments;
```

その後、「0-2」の一時ファイルの取り出しからやり直す。

## 4. すでに構築済みのDBを、設計変更に合わせて更新する（移行 SQL）

DB設計を変更したとき、**すでにテーブルを作成済みの DB** は、データを残したまま、移行用の SQL（`docs/db/migrations/` 配下）で更新する。**変更後の DB設計（最新の `schema.sql`）で、初めて作成する場合は、移行 SQL は不要**。

### 移行 SQL の一覧

**日付の古い順に、1つずつ、1回だけ適用する**（2回目は、すでに変更済みのためエラーになることがある）。

| # | ファイル | 内容 | 決定事項 | 適用状況（WSL） |
| --- | --- | --- | --- | --- |
| 1 | `2026-10-01_employee_code_to_employee_id.sql` | 列名・テーブル名の変更（`employee_code`→`employee_id` ほか） | D-040 | 適用済み（2026-10-01） |
| 2 | `2026-10-01_request_reason_nullable.sql` | 申請の理由を任意（NULL 可）に変更 | D-041 | 適用済み（2026-10-15 に依頼者が `SHOW COLUMNS` で確認。`reason` は NULL 可） |

> 適用したら、上の表の「適用状況」を更新する（Claude が、報告を受けて更新する）。

### 4-1. 移行用の SQL を一時ファイルに取り出す（ターミナルで）

下は、**#2** の例。#1 など、別の移行 SQL を適用するときは、ファイル名（2か所）を、表のファイル名に置き換える。

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance
git fetch origin
git show origin/main:docs/db/migrations/2026-10-01_request_reason_nullable.sql > /tmp/migration.sql
head -3 /tmp/migration.sql
```

先頭に `-- 申請の理由を、必須（NOT NULL）から任意（NULL 可）に変更…` と表示されれば OK。

### 4-2. 適用する（mysql に入って）

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
SOURCE /tmp/migration.sql
```

`ERROR` が出なければ成功。#2 の場合は、次で確認する（`reason` が `YES`（NULL 可）になる）。

**【mysql 内で実行】**

```sql
SHOW COLUMNS FROM attendance_requests LIKE 'reason';
```

**【出力例（入力しない）】**

```
Field   Type          Null  Key  Default  Extra
reason  varchar(500)  YES        NULL
```

mysql から出て、一時ファイルを削除する。

**【mysql 内で実行】**

```sql
exit
```

**【ターミナルで実行】**

```bash
rm /tmp/migration.sql
```

> 移行 SQL が失敗した場合は、エラーの内容を連絡する。開発中でデータが無ければ、「3. やり直したい場合」で、`DROP TABLE` 文の**テーブル名を、現在の DB にあるテーブル名**にして実行し、「1. テーブルの作成」「2. 最初のシステム管理者の登録」をやり直してもよい（#1 を適用していない DB では、`employee_code_sequences` が、テーブル名になっている）。

## 5. 確認用のテストデータを入れる（PR 2 以降の動作確認用）

ログインなどを試すための、部署2つと社員4人（`docs/db/test_data.sql`）を入れる。**1回だけ実行する**（2回目は、重複のエラーになる）。パスワードは、どの社員も `Test1234`（テスト専用）。

| ログイン ID | 氏名 | 部署 | 権限 | 備考 |
| --- | --- | --- | --- | --- |
| 2025001 | 山田 太郎 | 営業部 | 社員 | |
| 2025002 | 佐藤 花子 | 営業部 | 管理者（承認範囲：部署） | |
| 2025003 | 鈴木 一郎 | 開発部 | 管理者（承認範囲：全社） | |
| 2025004 | 高橋 次郎 | 開発部 | 社員 | 利用不可（ログインできないことの確認用） |

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance
git fetch origin
git show origin/main:docs/db/test_data.sql > /tmp/test_data.sql
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
SOURCE /tmp/test_data.sql
SELECT login_id, employee_id, role, is_active FROM login_accounts;
exit
```

**【出力例（入力しない）】**

```
2025001  2025001  employee      1
2025002  2025002  manager       1
2025003  2025003  manager       1
2025004  2025004  employee      0
sysadmin NULL     system_admin  1
```

**【ターミナルで実行】**

```bash
rm /tmp/test_data.sql
```

### 5-2. 出退勤を最初から試し直す（本日の記録を消す。PR 3 以降の動作確認用）

メイン画面の出勤・休憩・退勤を、同じ日にもう一度試したいときに、テスト用の社員（例：`2025001`）の**本日の行だけ**を消す。ほかの社員・ほかの日の行は消えない。

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
SET time_zone = '+09:00';
DELETE FROM attendances WHERE employee_id = '2025001' AND work_date = CURDATE();
exit
```

- `SET time_zone` は、「本日」を日本時間の日付にするため（アプリと同じ）。
- `Query OK, 1 row affected` なら消えた。`0 rows affected` なら、本日の行は無かった。
- 消したあと、メイン画面を読み込み直すと「未出勤」に戻る。

### 5-3. 記録表示の確認用に、過去の日の記録を入れる（PR 4 以降の動作確認用）

`2025001`（山田 太郎）の、2026年9月の記録を4日分入れる。記録表示で「2026年9月」を開くと、次のとおりになる。

| 日 | 出勤 | 退勤 | 休憩 | 労働時間 |
| --- | --- | --- | --- | --- |
| 9月1日（火） | 09:00 | 18:00 | なし | 8時間00分 |
| 9月2日（水） | 09:00 | 18:00 | 15:00〜15:15 | 7時間45分 |
| 9月3日（木） | 09:00 | なし | なし | --（退勤が無いので数えない） |
| 9月24日（木） | 09:30 | 19:00 | 12:10〜12:40（昼休憩と重なるので、引かない） | 8時間30分 |

合計：**出勤日数 4日、総労働時間 24時間15分**。

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
  ('2025001', '2026-09-01', '2026-09-01 09:00:00', '2026-09-01 18:00:00', NULL, NULL),
  ('2025001', '2026-09-02', '2026-09-02 09:00:00', '2026-09-02 18:00:00', '2026-09-02 15:00:00', '2026-09-02 15:15:00'),
  ('2025001', '2026-09-03', '2026-09-03 09:00:00', NULL, NULL, NULL),
  ('2025001', '2026-09-24', '2026-09-24 09:30:00', '2026-09-24 19:00:00', '2026-09-24 12:10:00', '2026-09-24 12:40:00');
exit
```

- `Duplicate entry` のエラーが出たら、すでに入っている。入れ直すときは、先に次の SQL で消す（2025001 の 2026年9月の行だけが消える）。

```sql
DELETE FROM attendances WHERE employee_id = '2025001' AND work_date BETWEEN '2026-09-01' AND '2026-09-30';
```

### 5-4. 申請の確認用（PR 5 以降の動作確認用）

**簡単残業申請を試す**：申請できるのは、本日の退勤が 18:00 より後の日だけ。昼間に試すときは、先にメイン画面で「出勤」「退勤」を押してから、本日の退勤を 19:30 に書き換える（2025001 の本日の行だけが変わる）。

**【ターミナルで実行】**

```bash
mysql -u attendance_app -p
```

**【mysql 内で実行】**

```sql
USE attendance_training;
SET time_zone = '+09:00';
UPDATE attendances SET check_out_at = CONCAT(CURDATE(), ' 19:30:00') WHERE employee_id = '2025001' AND work_date = CURDATE();
exit
```

- `Rows matched: 1` なら書き換わった。`Rows matched: 0` なら、本日の行が無い（先にメイン画面で「出勤」を押す）。
- メイン画面を読み込み直すと、退勤が 19:30 になり、「簡単残業申請」が押せる。

**申請を最初から試し直す**：2025001 の申請を、すべて消す（勤怠の記録は消えない）。

**【mysql 内で実行】**

```sql
USE attendance_training;
DELETE FROM attendance_requests WHERE employee_id = '2025001';
exit
```

## 6. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `Access denied for user 'attendance_app'` | パスワード違い。`-p` の入力を確認する |
| `ERROR 1044 ... Access denied ... to database` | 権限不足。0-1 の権限を確認する（MySQL の管理者が `GRANT` を追加する） |
| `ERROR 1046 (3D000): No database selected` | `USE attendance_training;` を実行していない |
| `Table '...' already exists` | すでに作成済み。中身を作り直すなら「3. やり直し」を実行する |
| `Check constraint ... is violated` | `login_accounts` の権限と社員コードの組み合わせが誤り。システム管理者は `employee_id` を NULL、社員・管理者は社員コードとログインIDを同じにする |
| `SOURCE` で `Failed to open file` | `/tmp/schema.sql` が無い。0-2 を実行する（mysql の外で） |
| `git show` で `fatal: ambiguous argument 'origin/main'` | `git fetch origin` を実行していない |
