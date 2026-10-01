# Apache 設定手順書

WSL(Ubuntu) の Apache に、勤怠管理システム用の設定を入れる手順。次の2つを行う。

1. **内部ファイルを Web から見えなくする設定**（[apache/attendance.conf](./apache/attendance.conf)）を有効にする。
2. **DB の接続情報**を、Web ルートの外 `/var/www/attendance_private/config.php` に置く。

## 背景

- リポジトリ直下が Web ルート（`/var/www/html/attendance`）のため、何もしないと、`.git`（ソースの履歴）、`docs/`、`src/`（内部コード）などが Web から見えてしまう。
- WSL の Apache は `AllowOverride None` のため、`.htaccess` は効かない。そのため、Apache の設定ファイルで制御する。
- Claude の検証環境の Apache で、設定が無いと `.git/config` が見えること（HTTP 200）、設定を入れると拒否されること（HTTP 403）を確認済み。

## 表記のルール

コードブロックの中身は、そのままコピーして貼り付けられる（プロンプトの `$` は書いていない）。**【ターミナルで実行】** は WSL のターミナルで実行する。**【出力例（入力しない）】** は結果の見本。

## 前提

- [WSL環境へのソース配置手順書.md](./WSL環境へのソース配置手順書.md) の0〜1章が完了している（`/var/www/html/attendance` に git clone 済み）。
- `sudo` が使える。

## 1. 現状の確認（設定を入れる前）

設定を入れる前は、`.git` が Web から見えてしまうことを確認する（見えるのが正しい。このあと直す）。

**【ターミナルで実行】**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/attendance/.git/config
```

**【出力例（入力しない）】**

```
200
```

## 2. 設定ファイルを置いて有効にする

リポジトリから設定ファイルを取り出して、Apache の設定の置き場所に保存する（`docs/` は WSL に配置されないため、`git show` で取り出す）。

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance
git fetch origin
git show origin/main:docs/apache/attendance.conf | sudo tee /etc/apache2/conf-available/attendance.conf > /dev/null
```

有効にして、設定の構文を確認し、反映する。

**【ターミナルで実行】**

```bash
sudo a2enconf attendance
sudo apache2ctl configtest
```

`Syntax OK` と表示されれば、反映する（`AH00558` の警告は、無視してよい）。

**【ターミナルで実行】**

```bash
sudo apache2ctl graceful
```

## 3. 設定の確認

**【ターミナルで実行】**

```bash
for p in index.html css/ .git/config docs/ src/; do printf "%-16s " "$p"; curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/attendance/$p"; done
```

**【出力例（入力しない）】**

```
index.html       200
css/             403
.git/config      403
docs/            403
src/             403
```

- `index.html`（現在の試作の画面）は表示できる（200）。
- `.git/config`、`docs/`、`src/` は拒否される（403）。ディレクトリの一覧（`css/`）も、表示されない（403）。
- `src/` や `docs/` が、まだ WSL に存在しなくても、403 になる（設定でアクセス自体を拒否している）。

## 4. DB の接続情報の置き場所を作る

Web ルート（`/var/www/html`）の外に、設定用のフォルダを作る。

**【ターミナルで実行】**

```bash
sudo mkdir -p /var/www/attendance_private
sudo chown root:www-data /var/www/attendance_private
sudo chmod 750 /var/www/attendance_private
```

設定ファイルを作る（エディタが開くので、次の内容を貼り付けて、`db_pass` を `attendance_app` のパスワードに書き換えて保存する。`nano` の保存は Ctrl+O → Enter、終了は Ctrl+X）。

**【ターミナルで実行】**

```bash
sudo nano /var/www/attendance_private/config.php
```

**【貼り付ける内容】**

```php
<?php
return [
    'db_name' => 'attendance_training',
    'db_host' => 'localhost',
    'db_user' => 'attendance_app',
    'db_pass' => 'ここにパスワード',
];
```

PHP を実行する Apache のユーザー（`www-data`）だけが読めるようにする。

**【ターミナルで実行】**

```bash
sudo chown root:www-data /var/www/attendance_private/config.php
sudo chmod 640 /var/www/attendance_private/config.php
```

確認する（`www-data` で読めること、他のユーザーでは読めないこと）。

**【ターミナルで実行】**

```bash
sudo -u www-data test -r /var/www/attendance_private/config.php && echo "www-data: 読める"
ls -l /var/www/attendance_private/
```

**【出力例（入力しない）】**

```
www-data: 読める
-rw-r----- 1 root www-data ... config.php
```

> パスワードを含むファイルは、**リポジトリにコミットしない**。このファイルは Git の管理外（`/var/www/html` の外）にある。

## 5. 元に戻したい場合

**【ターミナルで実行】**

```bash
sudo a2disconf attendance
sudo apache2ctl graceful
```

## 6. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `fatal: ambiguous argument 'origin/main'` | `git fetch origin` を実行していない |
| `Conf attendance does not exist!` | 2 の `tee` が失敗している。`ls /etc/apache2/conf-available/attendance.conf` で確認する |
| `Syntax error ...` | 設定ファイルの取り出しに失敗している（空など）。2 の手順をやり直す |
| `AH00558: Could not reliably determine the server's fully qualified domain name` | 警告。動作に影響しないので無視してよい |
| 確認で `index.html` が 404 | 配置が済んでいない。配置手順書の1章を確認する |
| 確認ですべて 200 のまま | 設定が反映されていない。`sudo a2enconf attendance` と `sudo apache2ctl graceful` を再実行する |
| `curl: (7) Failed to connect` | Apache が起動していない。`sudo apache2ctl start`（または `sudo service apache2 start`）を実行する |
