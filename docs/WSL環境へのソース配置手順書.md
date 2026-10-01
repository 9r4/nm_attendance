# WSL環境へのソース配置手順書

WSL(Ubuntu) の `/var/www/html/attendance` に、GitHub のソースを配置・更新する手順。

- 配置先（プロジェクトルート）: `/var/www/html/attendance`
- リポジトリ: `https://github.com/9r4/nm_attendance`（非公開）
- ブランチ: `main`（※ `main` の作成は未完了。[タスク管理.md](./タスク管理.md) T-004 を参照。完了するまでは `claude/attendance-system-docs-8rsef4` を使う）
- **WSL側に `docs/` と `CLAUDE.md` は不要** → `sparse-checkout` で除外する

> 以降のコマンドは、WSL の Ubuntu ターミナルで実行する。`$` はプロンプトなので入力しない。

## 0. 事前準備（初回のみ）

### 0-1. git の確認

```bash
$ git --version
```

表示されなければ `sudo apt update && sudo apt install -y git` で入れる。

### 0-2. GitHub への認証を用意する（非公開リポジトリのため必須）

どちらか一方でよい。

**A. HTTPS + Personal Access Token（簡単）**

1. GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens で、`9r4/nm_attendance` のみ・`Contents: Read-only` のトークンを作る（読み取り専用でよい。ソースの反映は PR 経由のため、WSL からプッシュしない）。
2. clone / pull のときに、ユーザー名に GitHub のユーザー名、パスワードに**トークン**を入力する。
3. 毎回の入力を省くなら `git config --global credential.helper store`（トークンが平文で `~/.git-credentials` に保存される点に注意）。

**B. SSH 鍵**

1. `ssh-keygen -t ed25519 -C "wsl"` で鍵を作り、`cat ~/.ssh/id_ed25519.pub` の内容を GitHub → Settings → SSH keys に登録する。
2. 以降のURLは `git@github.com:9r4/nm_attendance.git` を使う。

## 1. 初回配置

### 1-1. 配置先ディレクトリを作る

`/var/www/html` は通常 root 所有のため、自分のユーザーが書き込めるようにする。

```bash
$ sudo mkdir -p /var/www/html/attendance
$ sudo chown -R $USER:$USER /var/www/html/attendance
```

> すでに `/var/www/html/attendance` に何かファイルがある場合、clone に失敗する。中身が不要なら `rm -rf /var/www/html/attendance/*` で空にするか、別名に退避してから実行する。

### 1-2. clone（docs と CLAUDE.md を除外）

```bash
$ git clone --no-checkout https://github.com/9r4/nm_attendance.git /var/www/html/attendance
$ cd /var/www/html/attendance
$ git sparse-checkout set --no-cone '/*' '!/docs/' '!/CLAUDE.md'
$ git checkout main
```

SSH を使う場合は、1行目の URL を `git@github.com:9r4/nm_attendance.git` に置き換える。

### 1-3. 配置結果の確認

```bash
$ ls -A /var/www/html/attendance
```

次のようになっていれば成功。`docs/` と `CLAUDE.md` が**無い**こと。

```
.git  admin_submit.html  calendar.html  css  index.html  js  login.html
```

## 2. 更新（最新ソースの取り込み）

依頼者が PR をスカッシュマージした後、次を実行する。

```bash
$ cd /var/www/html/attendance
$ git pull
```

- `docs/` の更新は取り込まれない（除外設定が維持される）。
- 配置先のファイルは**直接編集しない**。編集した場合、`git pull` が競合・失敗する。誤って編集した場合は `git checkout -- .` で元に戻す（編集内容は失われる）。

## 3. 動作確認

Apache などのWebサーバが起動している場合は、ブラウザで次を開く。

- `http://localhost/attendance/index.html`
- `http://localhost/attendance/login.html`

Webサーバが未設定の場合は、静的ファイルだけ確認するなら次でも試せる（Python が入っている場合）。

```bash
$ cd /var/www/html/attendance && python3 -m http.server 8000
# ブラウザ: http://localhost:8000/index.html
```

## 4. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `destination path ... already exists and is not an empty directory` | 配置先が空でない。1-1 の注意を参照 |
| `Authentication failed` | トークンの期限切れ・権限不足。再作成して入力し直す |
| `pathspec 'main' did not match` | `main` が未作成。冒頭の注記のとおり作業ブランチ名を指定する（`git checkout claude/attendance-system-docs-8rsef4`） |
| `Permission denied` | 1-1 の `chown` が未実施。再実行する |
| `docs/` が配置されてしまった | `git sparse-checkout set --no-cone '/*' '!/docs/' '!/CLAUDE.md'` を再実行する |
| `git pull` が競合する | 配置先のファイルを直接編集している。`git status` で確認し、`git checkout -- .` で戻してから pull する |

## 5. ブランチ切り替え時（`main` 作成後に作業ブランチから移る場合）

```bash
$ cd /var/www/html/attendance
$ git fetch origin
$ git checkout main
$ git pull
```
