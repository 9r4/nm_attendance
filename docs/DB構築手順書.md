# DB構築手順書（WSL の MySQL にテーブルを作る）

確定したDB設計（[DB設計書.md](./DB設計書.md)、[db/schema.sql](./db/schema.sql)）を、WSL(Ubuntu) の MySQL に作成する手順。

- DB名：`attendance_training`（作成済み）
- ユーザー：`attendance_app@localhost`（作成済み）
- MySQL：8.4.11
- 対象：7テーブル（departments, employee_code_sequences, employees, login_accounts, attendances, attendance_requests, work_settings）

## 表記のルール

| プロンプト | 意味 |
| --- | --- |
| `$` | **ターミナル（シェル）** で実行する |
| `mysql>` | **mysql にログインした状態**（`mysql -u attendance_app -p` で入った後）で実行する |

- 入力するのは `$` や `mysql>` の**右側だけ**。
- `mysql>` の SQL は、末尾の `;` までが1つの命令。
- 以降の手順で「mysql に入る」と書いてあるところは、次のコマンドで入る。出るときは `exit` を入力する。

```bash
$ mysql -u attendance_app -p
```

> **WSL のソース配置先 `/var/www/html/attendance` には `docs/` が無い**（sparse-checkout で除外）。そのため、SQL は `git show` でリポジトリから一時ファイルに取り出し、mysql の `SOURCE` で流す（SQL ファイルを Web から見える場所に置かない）。

## 0. 事前確認

### 0-1. DB と権限の確認（mysql に入って）

```sql
mysql> SHOW DATABASES LIKE 'attendance_training';
mysql> SHOW GRANTS;
```

- `attendance_training` が表示されること。
- 権限に、`attendance_training` への `CREATE`、`ALTER`、`INDEX`、`REFERENCES`（外部キー用）、`INSERT`、`SELECT`、`UPDATE`、`DELETE`、`DROP` が含まれること（`ALL PRIVILEGES` なら問題ない）。

### 0-2. 最新の SQL を一時ファイルに取り出す（ターミナルで）

mysql から出ている状態（`exit` 済み）で実行する。

```bash
$ cd /var/www/html/attendance
$ git fetch origin
$ git show origin/main:docs/db/schema.sql > /tmp/schema.sql
$ head -3 /tmp/schema.sql
```

先頭に `-- 勤怠管理システム スキーマ（…確定…）` と表示されれば OK。

## 1. テーブルの作成

mysql に入って実行する。

```bash
$ mysql -u attendance_app -p
```

```sql
mysql> USE attendance_training;
mysql> SOURCE /tmp/schema.sql
```

`Query OK` が何行か表示され、`ERROR` が出なければ成功。7テーブルできていることを確認する。

```sql
mysql> SHOW TABLES;
```

```
attendance_requests
attendances
departments
employee_code_sequences
employees
login_accounts
work_settings
```

勤務設定の初期値（始業9:00・終業18:00・昼休憩12:00〜13:00）も、`schema.sql` の最後で登録される。

```sql
mysql> SELECT * FROM work_settings;
```

確認できたら mysql から出て、一時ファイルを削除する。

```sql
mysql> exit
```

```bash
$ rm /tmp/schema.sql
```

## 2. 最初のシステム管理者の登録

システム管理者は画面から作れないため、SQL で直接登録する。パスワードは**ハッシュ化**して保存する（平文は保存しない）。

### 2-1. パスワードのハッシュを作る（ターミナルで）

PHP の CLI が必要（`php -v` で確認。無ければ `sudo apt install -y php-cli`）。パスワードは画面に表示されず、シェルの履歴にも残らないように入力する。

```bash
$ read -s -p "システム管理者のパスワード: " PW; echo
$ php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT), "\n";' "$PW"; unset PW
```

`$2y$10$…` で始まる60文字前後の文字列が1行表示される。**この1行をコピーしておく**（パスワード自体ではなくハッシュなので、画面に出してよい）。

### 2-2. 登録する（mysql に入って）

```bash
$ mysql -u attendance_app -p
```

ログインIDは `sysadmin` とする例（変更してよい。20文字以内の英数字）。`＜コピーしたハッシュ＞` を、2-1 でコピーした1行に置き換える（シングルクォート `'` は残す）。

```sql
mysql> USE attendance_training;
mysql> INSERT INTO login_accounts (login_id, employee_code, password_hash, role) VALUES ('sysadmin', NULL, '＜コピーしたハッシュ＞', 'system_admin');
```

確認（ハッシュは表示しない）：

```sql
mysql> SELECT login_id, employee_code, role, is_active FROM login_accounts;
```

```
+----------+---------------+--------------+-----------+
| login_id | employee_code | role         | is_active |
+----------+---------------+--------------+-----------+
| sysadmin | NULL          | system_admin |         1 |
+----------+---------------+--------------+-----------+
```

> 社員・管理者（上司・部長等）のアカウントは、アプリ（アカウント作成画面）から、システム管理者が作成する。部署マスタも同様に、アプリから登録する（アプリ実装後）。

## 3. やり直したい場合（開発中のみ・全データが消える）

**本番データがある場合は絶対に実行しない。** mysql に入って、子テーブルから順に削除する（この順序なら外部キーのエラーが出ない）。

```sql
mysql> USE attendance_training;
mysql> DROP TABLE attendance_requests, attendances, login_accounts, employee_code_sequences, work_settings, employees, departments;
```

その後、「0-2」の一時ファイルの取り出しからやり直す。

## 4. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `Access denied for user 'attendance_app'` | パスワード違い。`-p` の入力を確認する |
| `ERROR 1044 ... Access denied ... to database` | 権限不足。0-1 の権限を確認する（MySQL の管理者が `GRANT` を追加する） |
| `ERROR 1046 (3D000): No database selected` | `USE attendance_training;` を実行していない |
| `Table '...' already exists` | すでに作成済み。中身を作り直すなら「3. やり直し」を実行する |
| `Check constraint ... is violated` | `login_accounts` の権限と社員コードの組み合わせが誤り。システム管理者は `employee_code` を NULL、社員・管理者は社員コードとログインIDを同じにする |
| `SOURCE` で `Failed to open file` | `/tmp/schema.sql` が無い。0-2 を実行する（mysql の外で） |
| `git show` で `fatal: ambiguous argument 'origin/main'` | `git fetch origin` を実行していない |
