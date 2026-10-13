# 実装 PR 1：基盤（方針・依頼者の承認待ち）

状態: **案（依頼者の承認待ち）**。承認後に、Claude が作業ブランチに「枠」をプッシュする（運用ルール2、D-047）。

## 1. 目的

以降の機能（ログイン、メイン画面、記録表示、申請、承認、システム管理者）を作るための、共通の土台を作る。

- 試作ソース（`index.html` ほか）を削除し、PHP の構成に置き換える（T-012）。
- DB に接続し、共通のエラー処理・画面の共通レイアウト・CSS の土台を用意する。
- **依頼者が、最初の DB のコード（`dbconnect.php` と、1つの取得関数）を書く**。動作を、仮のメイン画面で確認する。

ログイン・セッション・CSRF は、PR 2（認証）で作る。自動テスト（PHPUnit）は、計算などのロジックが出てくる PR（メイン画面・記録表示）で導入する。

## 2. 変更するファイル

| ファイル | 内容 | 担当 |
| --- | --- | --- |
| `index.html`、`login.html`、`calendar.html`、`admin_submit.html`、`css/calendar.css`、`js/calendar.js` | **削除**（試作ソース） | Claude |
| `.gitattributes` | 改行コードを LF に固定（`* text=auto eol=lf`）（D-038） | Claude |
| `.gitignore` | 開発用のファイル（`vendor/` など）をコミットしない | Claude |
| `src/errors.php` | 共通のエラー処理（[PHPコーディング方針.md](../PHPコーディング方針.md) 4章の見本のとおり） | Claude |
| `src/functions.php` | `h()`（画面に出す値のエスケープ） | Claude |
| **`src/dbconnect.php`** | **DB 接続。パスワードを直接書く（D-045）** | **依頼者** |
| **`src/queries/work_settings.php`** | **勤務設定を取得する関数 `fetch_work_settings()`** | **依頼者**（枠は Claude） |
| `templates/header.php`、`templates/footer.php` | 画面の共通レイアウト（`ress`、フォント、Material Symbols、`css/style.css` の読み込み、ヘッダー） | Claude |
| `css/style.css` | CSS の土台。画面モックの配色・文字・角丸などを、CSS 変数（`--color-primary` など）で定義 | Claude |
| `index.php` | **仮のメイン画面**。共通レイアウトを使い、勤務設定（始業・終業・昼休憩）を DB から取得して表示する（PR 3 で本来のメイン画面に置き換える） | Claude |

- DB を読み書きする関数は、`src/queries/` に、テーブルごとのファイルでまとめる（例：`src/queries/work_settings.php`、PR 2 以降は `src/queries/login_accounts.php` など）。
- `src/`・`templates/` は、Apache の設定で Web から見えない（D-039）。

## 3. 依頼者が書くもの（仕様）

### 3-1. `src/dbconnect.php`

[PHPコーディング方針.md](../PHPコーディング方針.md) 4章の見本の `src/dbconnect.php` と同じ形で書く。`'ここにパスワード'` を、`attendance_app` の実際のパスワードにする。

- 必須：`include_once(__DIR__ . '/errors.php');`、`PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`、`PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`、`PDO::ATTR_EMULATE_PREPARES => false`、`SET time_zone = '+09:00'`、接続失敗時は `error_log()` と定型の文。
- 接続した結果は、変数 `$pdo` に入れる（ほかのファイルは `$pdo` を使う）。

### 3-2. `src/queries/work_settings.php` の `fetch_work_settings()`

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetch_work_settings(PDO $pdo): array` |
| 引数 | `$pdo`：`dbconnect.php` で作った接続 |
| 戻り値 | 勤務設定の1行の連想配列：`['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00']` |
| SQL | `work_settings` テーブルの `id = 1` の行から、上の4列を取得する（プレースホルダ＋`bindParam`、型は `PDO::PARAM_INT`） |
| 行が無い場合 | 例外を投げる（`throw new RuntimeException('勤務設定がありません');`）。共通のエラー処理で、定型の文が表示される |

Claude は、この関数の「枠」（関数の宣言と、`// TODO` のコメント）だけを書いてプッシュする。中身（SQL と取得）を、依頼者が書く。

### 3-3. 確認方法（依頼者の WSL）

1. 作業ブランチに切り替える（[開発環境手順書.md](../開発環境手順書.md) 5-1）。
2. `src/dbconnect.php` と `fetch_work_settings()` を書いて保存する。
3. ブラウザで `http://localhost/attendance/` を開き、仮のメイン画面に「始業 09:00／終業 18:00／昼休憩 12:00〜13:00」と表示されれば完成。
4. コミットしてプッシュし、Claude に伝える（5-3）。

## 4. Claude の確認（テスト）

- Claude の環境（PHP の組み込みサーバー＋MySQL）で、依頼者のコードを含めて動作を確認する（DB のパスワードだけ、検証用の値に差し替えて動かす）。
  - 仮のメイン画面に、勤務設定が表示されること。
  - DB に接続できないとき・勤務設定の行が無いときに、500 と定型の文だけが出て、内部情報が出ないこと。
  - `h()` で、画面に出す値がエスケープされること。
- ブラウザ（Playwright）で表示し、画面写真を PR に添付する（フォント・アイコン・配色が読み込まれていること）。
- 依頼者のコードのレビュー（SQL インジェクション対策、型の指定、エラー処理）。

## 5. 完了の条件

- 依頼者の WSL で、仮のメイン画面に勤務設定が表示される。
- 試作ソースが無くなっている。
- Claude の確認（4章）がすべて通る。
- 依頼者が PR を承諾し、スカッシュマージする。

## 6. 確認したいこと

1. この方針（範囲・担当）でよいか。
2. 関数名の付け方：既存コードの変数名（`$error_message` など）に合わせて、**小文字とアンダースコア**（`fetch_work_settings`）にする案。ほかの書き方（`fetchWorkSettings`）がよければ教えてほしい。
3. DB の関数を置く場所：`src/queries/`（テーブルごとのファイル）でよいか。
