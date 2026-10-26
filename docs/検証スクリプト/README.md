# 検証スクリプト（Claude の検証環境用）

Claude の検証環境で、ブラウザ（Playwright）を使って画面の動きを確かめるスクリプト。PR のテストと、あとの PR での再確認（前の PR の動きが壊れていないか）に使う。**WSL では実行しない**（依頼者は不要）。

| ファイル | 対象 | 項目数（2026-10-26 時点。pr3〜pr10 で計 402） |
| --- | --- | --- |
| `pr3.js` | メイン画面（出退勤・休憩・上書き確認） | 36 |
| `pr4.js` | 記録表示・ハンバーガーメニュー | 41 |
| `pr5.js` | 申請（修正・新規登録・簡単残業申請） | 58 |
| `pr5補足.js` | 申請中の打刻の制限と取り消し（PR #7） | 20 |
| `pr6.js` | 承認 | 50 |
| `pr7.js` | システム管理者（アカウント管理・部署・勤務設定）。最初と最後に、作ったアカウント・部署を消し、テストデータと勤務設定を元に戻す | 90 |
| `pr8.js` | CSS・JS のキャッシュ対策（全画面の URL に `?v=` が付く、ファイルを変えると URL が変わる） | 19 |
| `pr9.js` | 小さな修正（自動ログアウト後のログアウト、ログイン画面の有効期限、残業申請が申請中のボタン、アカウント作成の確認の順番、承認のコメント）。docker の環境（1-1）が必要（セッションのファイルを書き換えるため） | 20 |
| `pr10.js` | 社員の勤怠（一覧・詳細・見られない社員・メニューと権限・画面の幅）。最初と最後に、勤怠・申請と、確認用に足した社員（2025901・2025902）を消す | 68 |
| `conflict.php` | 同時の出勤で一意制約の違反になる場合（PR 3）。別の接続で行を追加中に、このスクリプトを実行する | － |

PHPUnit（`composer test`）は `tests/` にある（PR 10 の時点で 116 件）。DB を使うテスト（`tests/ApprovalsDbTest.php`、`tests/AdminDbTest.php`、`tests/MembersDbTest.php`）は、環境変数を指定したときだけ動く（下の 3）。

## 1. 検証環境の準備（コンテナが作り直されたら、毎回）

Claude の環境は使い捨てで、サーバーが止まっていることがある。

### 1-1. 依頼者の WSL と同じ版で確認する（2026-10-22 から。基本はこちら）

依頼者の WSL（Ubuntu 26.04）と**同じ版**の Apache 2.4.66・PHP 8.5.4（mod_php）・MySQL 8.4.11 を、Docker の中に用意して確認する。Apache の設定も、WSL と同じ [../apache/attendance.conf](../apache/attendance.conf) を使う。ファイルは [wsl環境/](./wsl環境/)（`Dockerfile`・`start.sh`・`build.sh`）。

```bash
composer install                              # PHPUnit（vendor/ が無ければ）
bash docs/検証スクリプト/wsl環境/build.sh       # 作って起動し、版を表示する（初回は数分）
```

- リポジトリを `/var/www/html/nm_attendance` に置き（`http://127.0.0.1:8080/nm_attendance/` で開く）、DB・`attendance_app` ユーザー（パスワードは `src/dbconnect.php` と同じ）・テストデータ・システム管理者 `sysadmin`（パスワード `Admin1234`、検証環境だけ）を初回に作る。
- MySQL は `127.0.0.1:3307` で外に出し、`~/.my.cnf` を書くので、検証スクリプトの `mysql -uroot …` はこの MySQL につながる。
- PHP の非推奨・警告は、すべて Apache のログに出す（画面には出さない）。確認のあと、次で 0 件であることを確かめる：
  `docker exec attendance-wsl grep -iE 'PHP (Warning|Notice|Deprecated|Fatal)' /var/log/apache2/error.log`
- Docker の外の通信は、Docker Hub と Ubuntu の公式のサーバーが使える（PHP の PPA・MySQL の公式のサーバーは、Claude の環境の通信の制限で使えない）。
- **Docker Hub が「429 Too Many Requests」で断るとき**（2026-10-26 に発生）：Google の Docker Hub のミラーから同じイメージを取り、名前を付け直してから、BuildKit を使わずに作る（BuildKit は手元にイメージがあっても Docker Hub に問い合わせるため）。
  ```bash
  docker pull mirror.gcr.io/library/ubuntu:26.04 && docker tag mirror.gcr.io/library/ubuntu:26.04 ubuntu:26.04
  DOCKER_BUILDKIT=0 bash docs/検証スクリプト/wsl環境/build.sh
  ```
- **Claude の環境に `mysql` コマンドが無いとき**（2026-10-26 に発生）：検証スクリプトの `mysql -uroot …` を、コンテナの中の mysql で動かす。
  ```bash
  printf '#!/bin/bash\nexec docker exec -i attendance-wsl mysql "$@"\n' > /usr/local/bin/mysql && chmod +x /usr/local/bin/mysql
  ```

### 1-2. Docker が使えないとき（以前のやり方：PHP 8.3 の組み込みサーバー＋MySQL 8.0）

```bash
# MySQL（入っていなければ apt で入れる。DB の中身はコンテナが残っていれば残る）
service mysql start
# DB が無ければ作る（root はパスワードなし）。アプリのユーザー attendance_app のパスワードは src/dbconnect.php の値に合わせる
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS attendance_training CHARACTER SET utf8mb4"
mysql -uroot attendance_training < docs/db/schema.sql
mysql -uroot --default-character-set=utf8mb4 attendance_training < docs/db/test_data.sql
# システム管理者（検証環境だけ。パスワード Admin1234）
H=$(php -r 'echo password_hash("Admin1234", PASSWORD_DEFAULT);'); mysql -uroot attendance_training -e "INSERT INTO login_accounts (login_id, employee_id, password_hash, role) VALUES ('sysadmin', NULL, '$H', 'system_admin')"
# PHPUnit
composer install
# PHP のサーバー（/home/user を Web ルートにして、/nm_attendance/ で開く。4 並列で、同時に送る確認ができる）
cd /home/user && PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d log_errors=1 -d error_reporting=-1 -S 127.0.0.1:8080 -t /home/user > サーバーのログ 2>&1
```

- 1-1 で作った `~/.my.cnf` があると、`mysql` コマンドは 1-1 の MySQL につながる。1-2 を使うときは `~/.my.cnf` を消す。

### 1-3. 共通の注意

- 検証環境の `mysql` コマンドは文字コードが latin1 のため、日本語を含む SQL を流すときは `--default-character-set=utf8mb4` を付ける（`test_data.sql` には `SET NAMES utf8mb4;` を入れてある）。
- 依頼者が書く関数が枠のまま（未実装）のときは、検証用の仮の中身を入れて動かし、コミットする前に枠に戻す。

## 2. 実行

```bash
node docs/検証スクリプト/pr6.js
```

- 各スクリプトは、最初に勤怠・申請の表を消してから、確認用のデータを入れる（テスト用の DB で実行すること）。
- 最後に「結果: ○ 件 OK / ○ 件 NG」と出る。PHP の警告は、1-1 では Apache のログ（1-1 のコマンド）、1-2 ではサーバーのログで `Warning|Notice|Deprecated|Fatal|[500]` を探す。
- `pr5.js` は、月の 6 日以降に実行する（月の 1〜4 日を使うため）。
- ブラウザは `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`（検証環境に入っているもの）。版が違う（例：2026-10-26 は `chromium-1243/chrome-linux64`）ときは、スクリプトは変えずに、同じ場所を指すリンクを作る：
  `ln -sfn /opt/pw-browsers/chromium-1243 /opt/pw-browsers/chromium-1194 && ln -sfn /opt/pw-browsers/chromium-1243/chrome-linux64 /opt/pw-browsers/chromium-1243/chrome-linux`
- スクリプトが撮る画面の写真（`*.png`）は、確認のためのもので、コミットしない。

## 3. DB を使うテスト（PHPUnit。承認・システム管理者）

テスト用の DB（`attendance_test`）の表を、テストのたびに作り直す。本番・開発の DB を指定しないこと。

1-1 の環境（PHP 8.5）で実行する（`attendance_test` は 1-1 で作ってある。非推奨もすべて出すため `error_reporting=-1`）：

```bash
docker exec -w /var/www/html/nm_attendance -e ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' -e ATTENDANCE_TEST_USER=root attendance-wsl php -d error_reporting=-1 vendor/bin/phpunit tests
```

1-2 の環境のとき：

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS attendance_test CHARACTER SET utf8mb4"
ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' ATTENDANCE_TEST_USER=root vendor/bin/phpunit tests
```
