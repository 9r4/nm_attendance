# 検証スクリプト（Claude の検証環境用）

Claude の検証環境で、ブラウザ（Playwright）を使って画面の動きを確かめるスクリプト。PR のテストと、あとの PR での再確認（前の PR の動きが壊れていないか）に使う。**WSL では実行しない**（依頼者は不要）。

| ファイル | 対象 | 項目数（2026-10-15 時点） |
| --- | --- | --- |
| `pr3.js` | メイン画面（出退勤・休憩・上書き確認） | 36 |
| `pr4.js` | 記録表示・ハンバーガーメニュー | 41 |
| `pr5.js` | 申請（修正・新規登録・簡単残業申請） | 57 |
| `pr5補足.js` | 申請中の打刻の制限と取り消し（PR #7） | 20 |
| `pr6.js` | 承認 | 50 |
| `pr7.js` | システム管理者（アカウント管理・部署・勤務設定）。最初と最後に、作ったアカウント・部署を消し、テストデータと勤務設定を元に戻す | 90 |
| `conflict.php` | 同時の出勤で一意制約の違反になる場合（PR 3）。別の接続で行を追加中に、このスクリプトを実行する | － |

PHPUnit（`composer test`）は `tests/` にある（PR 7 の時点で 100 件）。DB を使うテスト（`tests/ApprovalsDbTest.php`、`tests/AdminDbTest.php`）は、環境変数を指定したときだけ動く（下の 3）。

## 1. 検証環境の準備（コンテナが作り直されたら、毎回）

Claude の環境は使い捨てで、MySQL や PHP のサーバーが止まっていることがある。

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

- 検証環境の `mysql` コマンドは文字コードが latin1 のため、日本語を含む SQL を流すときは `--default-character-set=utf8mb4` を付ける（`test_data.sql` には `SET NAMES utf8mb4;` を入れてある）。
- 依頼者が書く関数が枠のまま（未実装）のときは、検証用の仮の中身を入れて動かし、コミットする前に枠に戻す。

## 2. 実行

```bash
node docs/検証スクリプト/pr6.js
```

- 各スクリプトは、最初に勤怠・申請の表を消してから、確認用のデータを入れる（テスト用の DB で実行すること）。
- 最後に「結果: ○ 件 OK / ○ 件 NG」と出る。PHP の警告は、サーバーのログで `Warning|Notice|Deprecated|Fatal|[500]` を探す。
- `pr5.js` は、月の 6 日以降に実行する（月の 1〜4 日を使うため）。
- ブラウザは `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`（検証環境に入っているもの）。

## 3. DB を使うテスト（PHPUnit。承認・システム管理者）

テスト用の DB（`attendance_test`）の表を、テストのたびに作り直す。本番・開発の DB を指定しないこと。

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS attendance_test CHARACTER SET utf8mb4"
ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' ATTENDANCE_TEST_USER=root vendor/bin/phpunit tests
```
