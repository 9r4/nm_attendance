# 実装 PR 2：認証（完了：2026-10-13、PR #3）

状態: **完了（2026-10-13、PR #3 をマージ）**。以下は経緯。**承認済み（2026-10-13。D-058）**。作業ブランチ **`claude/pr2-auth`** に、Claude の担当部分と、依頼者が書く3つの関数の枠をプッシュ済み。**依頼者が中身を書いてプッシュする番**。

## 1. 目的

- ログイン・ログアウトができる（FR-001、FR-002）。
- ログインしていない人は、どの画面も使えず、ログイン画面に移る（BR-003）。
- 画面上部のヘッダーに、ログイン中の人の氏名・部署と、ログアウトボタンを出す（画面モックのとおり）。
- 以降の PR で使う、権限のチェック（社員・管理者・システム管理者）と、CSRF 対策の仕組みを用意する。

## 2. 画面と動き

| 画面・操作 | 動き |
| --- | --- |
| ログイン画面（`login.php`） | ID とパスワードを入れて「ログイン」。画面モックの Login と同じ見た目。下に「パスワードをお忘れの方」を置き、押すと「システム管理者にお問い合わせください」と表示する（D-050） |
| ログイン成功 | メイン画面（`index.php`）へ移る |
| ログイン失敗 | 「ID またはパスワードが正しくありません。」と表示する。**ID が無い・パスワードが違う・利用不可（`is_active = 0`）のどれでも、同じ文**にする（どれが原因かを、他人に知られないため） |
| ログインしていない状態で、ほかの画面を開く | ログイン画面へ移る（BR-003） |
| ログアウト | ヘッダーの「ログアウト」ボタン。ログイン情報を消して、ログイン画面へ移る |
| ヘッダーの表示 | 社員・管理者：氏名と部署名。システム管理者（社員ではない）：「システム管理者」 |
| 一定時間、操作しなかった場合 | 自動でログアウトし、次に操作したときにログイン画面へ移る（時間は 6章で確認） |

## 3. 変更するファイル

| ファイル | 内容 | 担当 |
| --- | --- | --- |
| **`src/queries/login_accounts.php`** | **`fetchLoginAccount()`、`updateLastLoginAt()`** | **依頼者**（枠は Claude） |
| **`src/queries/employees.php`** | **`fetchEmployeeProfile()`（社員と部署を結合して取得）** | **依頼者**（枠は Claude） |
| `src/session.php` | セッションの開始（Cookie の設定、無操作時の期限）、CSRF トークンの発行と確認 | Claude |
| `src/auth.php` | ログイン処理（`password_verify()`、利用可否の確認、セッション ID の再発行）、ログアウト、ログイン中の人の取得、ログインの必須化（BR-003）、権限のチェック | Claude |
| `login.php` | ログイン画面（表示と、ログインの POST の処理） | Claude |
| `logout.php` | ログアウト（POST＋CSRF トークンのみ受け付ける） | Claude |
| `templates/header.php` | ヘッダーに氏名・部署名・ログアウトボタンを追加（ログイン画面では出さない） | Claude |
| `css/style.css` | ログイン画面、ヘッダーの利用者の表示、ボタン、エラー表示のスタイル | Claude |
| `index.php` | 仮のメイン画面に、ログインの必須化を追加 | Claude |

## 4. 依頼者が書くもの（仕様）

社員 ID・ログイン ID は文字列なので、`bindParam` の型は `PDO::PARAM_STR`（[PHPコーディング方針.md](../PHPコーディング方針.md)）。関数の `{` は関数名と同じ行（D-056）。枠には、処理の流れのコメントを書いておく（D-054）。

### 4-1. `fetchLoginAccount()`（`src/queries/login_accounts.php`）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchLoginAccount(PDO $pdo, string $loginId): ?array {` |
| 引数 | `$loginId`：ログイン画面で入力された ID |
| 戻り値 | 見つかった場合：`['login_id' => …, 'employee_id' => …, 'password_hash' => …, 'role' => …, 'approval_scope' => …, 'is_active' => …]`。見つからない場合：`null` |
| SQL | `login_accounts` から、上の6列を取得（条件は `login_id = :login_id`） |
| ポイント | `fetch()` は、行が無いと `false` を返す。`false` のときは `null` を返す（`return $row === false ? null : $row;`） |

### 4-2. `updateLastLoginAt()`（`src/queries/login_accounts.php`）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function updateLastLoginAt(PDO $pdo, string $loginId): void {` |
| 処理 | `login_accounts` の `last_login_at` を、今の日時（`NOW()`）に更新する（条件は `login_id = :login_id`） |
| 戻り値 | なし（`void`） |
| ポイント | 初めての UPDATE 文。`execute()` で実行するだけで、取得（`fetch()`）はしない |

### 4-3. `fetchEmployeeProfile()`（`src/queries/employees.php`）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchEmployeeProfile(PDO $pdo, string $employeeId): ?array {` |
| 戻り値 | `['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部']`。見つからない場合：`null` |
| SQL | `employees` と `departments` を**結合（JOIN）**して取得する。`employees.department_id = departments.id` で結び、部署名は `departments.name AS department_name` のように別名を付ける（`employees` にも `name` があるため） |
| ポイント | 初めての JOIN。テーブルに別名（`employees e`、`departments d`）を付けると書きやすい |

### 4-4. 確認方法（依頼者の WSL）

確認用のテストデータ（5章）を入れてから、作業ブランチで次を確かめる。

1. `http://localhost/attendance/` を開くと、ログイン画面に移る。
2. `2025001` ／ `Test1234` でログインすると、メイン画面に移り、ヘッダーに「山田 太郎／営業部」と出る。
3. パスワードを間違えると、「ID またはパスワードが正しくありません。」と出る。
4. `sysadmin`（DB構築手順書で登録したパスワード）でログインすると、ヘッダーに「システム管理者」と出る。
5. ログアウトすると、ログイン画面に戻る。もう一度 `http://localhost/attendance/` を開いても、ログイン画面に移る。

## 5. 確認用のテストデータ（WSL の DB に入れる）

ログインを試すため、部署・社員・ログイン情報を、手で入れる（アカウント作成の画面は PR 7）。社員 ID は、**2025 年の番号（`2025001` など）**にする。2026 年の番号にすると、PR 7 で自動採番する `2026001` と重なるため。パスワードはどれも **`Test1234`**（テスト用。ハッシュは Claude の環境で作成し、`password_verify()` で照合できることを確認済み）。

SQL は `docs/db/test_data.sql`。入れ方は [DB構築手順書.md](../DB構築手順書.md) の 5章。内容は次のとおり。

| 社員 ID | 氏名 | 部署 | 権限 | 承認範囲 |
| --- | --- | --- | --- | --- |
| 2025001 | 山田 太郎 | 営業部 | 社員（`employee`） | － |
| 2025002 | 佐藤 花子 | 営業部 | 管理者（`manager`） | 部署（`department`） |
| 2025003 | 鈴木 一郎 | 開発部 | 管理者（`manager`） | 全社（`all`） |
| 2025004 | 高橋 次郎 | 開発部 | 社員（利用不可：`is_active = 0`） | － |

## 6. Claude の確認（テスト）

Claude の環境（PHP の組み込みサーバー＋MySQL、ブラウザは Playwright）で、依頼者のコードを含めて確認する。

- ログイン成功・失敗（ID が無い／パスワードが違う／利用不可）、エラーの文が同じであること。
- ログイン前は、ほかの画面からログイン画面へ移ること。
- ログイン成功時に、セッション ID が変わること（セッション固定攻撃の対策）。
- ログアウトは、CSRF トークンが無い・違う POST を受け付けないこと。GET では、ログアウトしないこと。
- ヘッダーの氏名・部署、システム管理者の表示。
- 画面に出す値のエスケープ、PHP の警告 0 件、画面写真（PC・スマホ）。

## 6-2. Claude の作業結果（2026-10-13、枠のプッシュ時点）

- 追加：`src/session.php`（セッションの開始、無操作2時間で自動ログアウト、CSRF トークン）、`src/auth.php`（ログイン・ログアウト・ログインの必須化・権限のチェック）、`login.php`、`logout.php`、`src/functions.php` に `redirect()`・`abort()`。ヘッダーに氏名・部署名・ログアウトボタン、CSS を追加。`index.php` をログイン必須に。枠：`src/queries/login_accounts.php`（`fetchLoginAccount()`・`updateLastLoginAt()`）、`src/queries/employees.php`（`fetchEmployeeProfile()`）。
- セッションの保存場所は、アプリ専用（`/tmp/attendance_sessions`）にした。Ubuntu の標準の保存場所は、約24分で古いセッションを消す仕組みがあり、2時間を守れないため。
- 確認（Claude の環境。`/attendance/` の下に配置、PHP の組み込みサーバー＋MySQL 8.0.46、ブラウザは Playwright。依頼者の3つの関数は、検証用の仮の中身で実行。コミットしない）：26 項目すべて通過（6章の項目、ログイン中に利用不可にされた場合、Cookie の属性、自動ログアウト（期限を3秒にして確認）、スマホの幅）。PHP の警告 0 件。枠のままでも、ログイン画面は表示される（ログインを試すと、未実装のエラー）。

## 7. 確認したいこと

~~1〜3~~ → すべて承認（範囲と担当、無操作 2 時間、テストデータ）。D-058。
