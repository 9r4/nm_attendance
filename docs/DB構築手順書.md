# DB構築手順書（WSL の MySQL にテーブルを作る）

確定したDB設計（[DB設計書.md](./DB設計書.md)、[db/schema.sql](./db/schema.sql)）を、WSL(Ubuntu) の MySQL に作成する手順。

- DB名：`attendance_training`（作成済み）
- ユーザー：`attendance_app@localhost`（作成済み）
- MySQL：8.4.11
- 対象：7テーブル（departments, employee_code_sequences, employees, login_accounts, attendances, attendance_requests, work_settings）

> **WSL のソース配置先 `/var/www/html/attendance` には `docs/` が無い**（sparse-checkout で除外）。そのため、SQL は `git show` でリポジトリから直接取り出して流す（ファイルを配置しないので、Webから SQL が見える心配もない）。
> コマンドは WSL の Ubuntu ターミナルで実行する。`$` はプロンプトなので入力しない。

## 0. 事前確認

### 0-1. DB とユーザーの確認

```bash
$ mysql -u attendance_app -p -e "SHOW DATABASES LIKE 'attendance_training'; SHOW GRANTS;"
```

- `attendance_training` が表示されること。
- 権限に、`attendance_training` への `CREATE`、`ALTER`、`INDEX`、`REFERENCES`（外部キー用）、`INSERT`、`SELECT`、`UPDATE`、`DELETE` が含まれること（`ALL PRIVILEGES` なら問題ない）。

### 0-2. 最新のリポジトリを取得

```bash
$ cd /var/www/html/attendance
$ git fetch origin
$ git show origin/main:docs/db/schema.sql | head -5
```

SQL の先頭（`-- 勤怠管理システム スキーマ案…`）が表示されれば OK。

## 1. テーブルの作成

```bash
$ cd /var/www/html/attendance
$ git show origin/main:docs/db/schema.sql | mysql -u attendance_app -p --default-character-set=utf8mb4 attendance_training
```

エラーが出なければ成功。次で7テーブルできていることを確認する。

```bash
$ mysql -u attendance_app -p attendance_training -e "SHOW TABLES;"
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

```bash
$ mysql -u attendance_app -p attendance_training -e "SELECT * FROM work_settings;"
```

## 2. 最初のシステム管理者の登録

システム管理者は画面から作れないため、SQL で直接登録する。パスワードはハッシュ化して保存する（平文は保存しない）。

### 2-1. パスワードのハッシュを作る

PHP の CLI が必要（`php -v` で確認。無ければ `sudo apt install -y php-cli`）。パスワードは画面に表示されず、シェルの履歴にも残らないように入力する。

```bash
$ read -s -p "システム管理者のパスワード: " PW; echo
$ HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$PW"); unset PW
$ echo "$HASH"
```

`$2y$10$…` で始まる60文字前後の文字列が表示されれば OK。

### 2-2. 登録する

ログインIDは `sysadmin` とする例（変更してよい。20文字以内の英数字）。

```bash
$ mysql -u attendance_app -p attendance_training -e "INSERT INTO login_accounts (login_id, employee_code, password_hash, role) VALUES ('sysadmin', NULL, '$HASH', 'system_admin');"
$ unset HASH
```

確認（`password_hash` は表示しない）：

```bash
$ mysql -u attendance_app -p attendance_training -e "SELECT login_id, employee_code, role, is_active FROM login_accounts;"
```

```
login_id   employee_code  role          is_active
sysadmin   NULL           system_admin  1
```

> 社員・管理者（上司・部長等）のアカウントは、アプリ（アカウント作成画面）から、システム管理者が作成する。部署マスタも同様に、アプリから登録する（アプリ実装後）。

## 3. やり直したい場合（開発中のみ・全データが消える）

**本番データがある場合は絶対に実行しない。**

```bash
$ mysql -u attendance_app -p -e "DROP DATABASE attendance_training; CREATE DATABASE attendance_training CHARACTER SET utf8mb4;"
```

その後、「1. テーブルの作成」からやり直す。

## 4. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `Access denied for user 'attendance_app'` | パスワード違い。`-p` の入力を確認する |
| `ERROR 1044 ... Access denied ... to database` | 権限不足。0-1 の権限を確認する（MySQL の管理者が `GRANT` を追加する） |
| `Table '...' already exists` | すでに作成済み。中身を作り直すなら「3. やり直し」を実行する |
| `Check constraint ... is violated` | `login_accounts` の権限と社員コードの組み合わせが誤り。システム管理者は `employee_code` を NULL、社員・管理者は社員コードとログインIDを同じにする |
| 文字化けする | `--default-character-set=utf8mb4` を付けているか確認する |
| `fatal: ambiguous argument 'origin/main'` | `git fetch origin` を実行していない |
