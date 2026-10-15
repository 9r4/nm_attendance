# WSL環境へのソース配置手順書

WSL(Ubuntu) の `/var/www/html/attendance` に、GitHub のソースを配置・更新する手順。

- 配置先（プロジェクトルート）: `/var/www/html/attendance`
- リポジトリ: `https://github.com/9r4/nm_attendance`（非公開）
- ブランチ: 初回の配置は `main`（常に最新版）。

> **2026-10-13 以降**：依頼者もこのフォルダでコードを書くため（D-048）、接続先を HTTPS に変更し、作業ブランチに切り替えて開発している。開発と、その後の更新は [開発環境手順書.md](./開発環境手順書.md) を使う。この手順書の 0-2（デプロイキー）と 1章は、初回の配置の記録。
- **WSL側に `docs/` と `CLAUDE.md` は不要** → `sparse-checkout` で除外する

> 以降のコマンドは、WSL の Ubuntu ターミナルで実行する。コードブロックの中身は、そのままコピーして貼り付けられる（プロンプトの `$` は書いていない）。実行結果の見本は **【出力例（入力しない）】** と表示している。

## 0. 事前準備（初回のみ）

### 0-1. git の確認

**【ターミナルで実行】**

```bash
git --version
```

表示されなければ `sudo apt update && sudo apt install -y git` で入れる。

### 0-2. GitHub への認証を用意する（SSH 鍵・非公開リポジトリのため必須）

SSH の**デプロイキー**（このリポジトリ専用・読み取り専用の鍵）を使う。一度設定すれば、`git clone` / `git fetch` / `git pull` で、パスワードの入力は不要になる。鍵に有効期限もない。

#### 0-2-1. このリポジトリ専用の鍵を作る（WSL）

パスフレーズは空にする（入力が要らなくなる）。鍵ファイルは、自分だけが読める権限で作られる。

**【ターミナルで実行】**

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/nm_attendance_deploy -N "" -C "wsl-nm_attendance-deploy"
```

#### 0-2-2. SSH の設定を追加する（WSL）

この鍵を、`github-nm` という名前で使えるようにする。すでに `~/.ssh/config` がある場合も、末尾に追記される。

**【ターミナルで実行】**

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-nm
  HostName github.com
  User git
  IdentityFile ~/.ssh/nm_attendance_deploy
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

#### 0-2-3. 公開鍵を表示してコピーする（WSL）

**【ターミナルで実行】**

```bash
cat ~/.ssh/nm_attendance_deploy.pub
```

`ssh-ed25519 AAAA…` で始まる1行を、全部コピーする。`.pub` が付いた**公開鍵**だけをコピーする（`.pub` が付いていない秘密鍵は、誰にも見せない）。

#### 0-2-4. GitHub にデプロイキーとして登録する（ブラウザ）

1. GitHub のリポジトリ `9r4/nm_attendance` → **Settings** → **Deploy keys** → **Add deploy key**。
2. Title：`WSL`（任意）。Key：0-2-3 でコピーした1行を貼り付ける。
3. **「Allow write access」にはチェックを入れない**（読み取り専用）。
4. **Add key** を押す。

#### 0-2-5. 接続を確認する（WSL）

**【ターミナルで実行】**

```bash
ssh -T github-nm
```

初回は `Are you sure you want to continue connecting (yes/no/[fingerprint])?` と聞かれるので、`yes` と入力する。
`Hi 9r4/nm_attendance! You've successfully authenticated, but GitHub does not provide shell access.` と表示されれば成功。

### 0-3. PHP の拡張機能の確認（2026-10-15 追加）

このシステムが使う PHP の拡張機能は、次の2つ。Ubuntu では、PHP 本体とは別に入れる必要がある。

| 拡張機能 | 使う場所 | 無い場合 |
| --- | --- | --- |
| `pdo_mysql` | DB への接続（全画面） | どの画面も「データベースに接続できません。」などのエラーになる |
| `mbstring` | 申請の理由の文字数の確認（PR 5 以降） | 記録表示から申請すると「エラーが発生しました。画面を読み込み直して、もう一度お試しください。」になる（2026-10-15 に WSL で発生し、入れて解決） |

**【ターミナルで実行】**

```bash
php -m | grep -i -E 'pdo_mysql|mbstring'
```

**【出力例（入力しない）】**

```
mbstring
pdo_mysql
```

どちらかが表示されなければ、入れて Apache を再起動する（入れる必要が無いものは、入れても変わらない）。

**【ターミナルで実行】**

```bash
sudo apt update && sudo apt install -y php-mysql php-mbstring
```

**【ターミナルで実行】**

```bash
sudo service apache2 restart
```

## 1. 初回配置

### 1-1. 配置先ディレクトリを作る

`/var/www/html` は通常 root 所有のため、自分のユーザーが書き込めるようにする。

**【ターミナルで実行】**

```bash
sudo mkdir -p /var/www/html/attendance
sudo chown -R $USER:$USER /var/www/html/attendance
```

> すでに `/var/www/html/attendance` に何かファイルがある場合、clone に失敗する。中身が不要なら `rm -rf /var/www/html/attendance/*` で空にするか、別名に退避してから実行する。

### 1-2. clone（docs と CLAUDE.md を除外）

**【ターミナルで実行】**

```bash
git clone --no-checkout git@github-nm:9r4/nm_attendance.git /var/www/html/attendance
cd /var/www/html/attendance
git sparse-checkout set --no-cone '/*' '!/docs/' '!/CLAUDE.md'
git checkout main
```

`git@github-nm:…` は、0-2-2 で作った設定名（`github-nm`）を使った SSH の URL。

### 1-3. 配置結果の確認

**【ターミナルで実行】**

```bash
ls -A /var/www/html/attendance
```

次のようになっていれば成功。`docs/` と `CLAUDE.md` が**無い**こと。

**【出力例（入力しない）】**

```
.git  admin_submit.html  calendar.html  css  index.html  js  login.html
```

## 2. 更新（最新ソースの取り込み）

依頼者が PR をスカッシュマージした後、次を実行する。

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance
git pull
```

- `docs/` の更新は取り込まれない（除外設定が維持される）。
- `main` にいるときは、ファイルを**直接編集しない**（編集は作業ブランチで行う。[開発環境手順書.md](./開発環境手順書.md) 5章）。`main` で編集した場合、`git pull` が競合・失敗する。誤って編集した場合は `git checkout -- .` で元に戻す（編集内容は失われる）。

## 3. 動作確認

Apache などのWebサーバが起動している場合は、ブラウザで次を開く。

- `http://localhost/attendance/index.html`
- `http://localhost/attendance/login.html`

Webサーバが未設定の場合は、静的ファイルだけ確認するなら次でも試せる（Python が入っている場合）。

**【ターミナルで実行】**

```bash
cd /var/www/html/attendance && python3 -m http.server 8000
# ブラウザ: http://localhost:8000/index.html
```

## 4. よくある失敗

| 症状 | 原因・対処 |
| --- | --- |
| `destination path ... already exists and is not an empty directory` | 配置先が空でない。1-1 の注意を参照 |
| `Permission denied (publickey)` | デプロイキーが未登録、または別の鍵が使われている。0-2-4 の登録と、0-2-2 の `~/.ssh/config`（`IdentityFile` のパス）を確認し、`ssh -T github-nm` を再実行する |
| `Host key verification failed` | `ssh -T github-nm` を一度も実行していない。0-2-5 を実行して `yes` と入力する |
| `ssh-keygen: command not found` | SSH クライアントが未導入。`sudo apt install -y openssh-client` を実行する |
| `Could not resolve hostname github-nm` | 0-2-2 の `~/.ssh/config` が無い、または書き間違い。`cat ~/.ssh/config` で確認する |
| `Permission denied` | 1-1 の `chown` が未実施。再実行する |
| `docs/` が配置されてしまった | `git sparse-checkout set --no-cone '/*' '!/docs/' '!/CLAUDE.md'` を再実行する |
| `git pull` が競合する | 配置先のファイルを直接編集している。`git status` で確認し、`git checkout -- .` で戻してから pull する |
