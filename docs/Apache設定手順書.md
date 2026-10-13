# Apache 設定手順書

WSL(Ubuntu) の Apache に、勤怠管理システム用の設定（**内部ファイルを Web から見えなくする設定**、[apache/attendance.conf](./apache/attendance.conf)）を入れる手順。

> 当初あった「DB の接続情報を Web ルートの外に置く」手順は、2026-10-13 に取りやめた（DB の接続情報は `src/dbconnect.php` に直接書く。決定事項 D-045）。

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

## 4. 元に戻したい場合

**【ターミナルで実行】**

```bash
sudo a2disconf attendance
sudo apache2ctl graceful
```

## 5. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `fatal: ambiguous argument 'origin/main'` | `git fetch origin` を実行していない |
| `Conf attendance does not exist!` | 2 の `tee` が失敗している。`ls /etc/apache2/conf-available/attendance.conf` で確認する |
| `Syntax error ...` | 設定ファイルの取り出しに失敗している（空など）。2 の手順をやり直す |
| `AH00558: Could not reliably determine the server's fully qualified domain name` | 警告。動作に影響しないので無視してよい |
| 確認で `index.html` が 404 | 配置が済んでいない。配置手順書の1章を確認する |
| 確認ですべて 200 のまま | 設定が反映されていない。`sudo a2enconf attendance` と `sudo apache2ctl graceful` を再実行する |
| `curl: (7) Failed to connect` | Apache が起動していない。`sudo apache2ctl start`（または `sudo service apache2 start`）を実行する |
