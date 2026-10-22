# PHP コーディング方針（DB アクセスの書き方）

状態: **確定（2026-10-01 依頼者承認。D-037）**。DB まわりの書き方は、依頼者が以前に書いた PHP コード（`phpKakeibo`）に合わせる。その他の部分は、すべて合わせる必要はない（依頼者の指示）。

## 1. 共有されたコード

2026-10-01 に依頼者から共有された PHP の学習用コード（`phpKakeibo`、`phpBBS`、`PHP_form`）を読み、書き方の要点をこのドキュメントにまとめた。

- コード自体は、リポジトリに保存しない。DB のユーザー・パスワード（ローカル開発用の値）が、コードの中に直接書かれているため。
- 以降、「既存コード」はこれらを指す。主な参照元は `phpKakeibo`（`dbconnect.php`、`create.php`、`update.php`、`delete.php`、`index.php`、`functions.php`）。

## 2. 既存コードの書き方（要点）

| 項目 | 既存コードの書き方 |
| --- | --- |
| DB 接続 | `dbconnect.php` に `$pdo = new PDO(...)` をまとめ、各ページで `include_once('./dbconnect.php');` する。接続時に `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`、`PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`、文字コードは `utf8mb4` |
| SQL の組み立て | `$sql = "…";` に SQL を文字列で書き、`$stmt = $pdo->prepare($sql);` で準備する。**名前付きプレースホルダ**（`:title`）を使う |
| 値の渡し方 | `$stmt->bindParam(':title', $title, PDO::PARAM_STR);` のように、**型（`PDO::PARAM_STR`／`PDO::PARAM_INT`）を明示**して渡す |
| 実行・取得 | `$stmt->execute();`。複数行は `$stmt->fetchAll()`、1 行は `$stmt->fetch()` |
| 登録・更新の日時 | SQL 内で `now()` を使って `created_at`・`updated_at` を入れる |
| 画面遷移 | 登録・更新・削除のあとは、`header('Location: ./index.php'); exit;` で一覧へ戻す（二重送信を防ぐ形） |
| ファイル構成 | 画面や処理ごとに PHP ファイルを分ける（`index.php`、`createForm.php`、`create.php`、`editForm.php`、`update.php`、`delete.php`）。共通関数は `functions.php` |
| 画面への出力 | HTML の中に `<?php foreach (…): ?> … <?php endforeach; ?>` と `<?= h($value) ?>` を書く。`h()` は `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` |

## 3. 本プロジェクトでの扱い

### 3-1. 既存コードに合わせる点

- `dbconnect.php`（PDO の接続）、`functions.php`（`h()`）という名前と役割。
- DB の接続情報（DB 名・ユーザー・パスワード）を、`dbconnect.php` に直接書く（ハードコーディング。2026-10-13 依頼者の判断、D-045。学習用で外部に公開しないため。注意点は [技術方針.md](./技術方針.md) 2-2）。
- 名前付きプレースホルダ＋型の明示＋`execute()` の流れ。値は **`bindValue`** で渡す（2026-10-15 変更。D-060。既存コードは `bindParam`）。PR 1 の `fetchWorkSettings()` は `bindParam` のまま（動作は同じ）。
- `fetchAll()` と `fetch()` の使い分け。
- 画面の `<?= h(…) ?>` による出力。

### 3-1-2. 命名と、依頼者が書く部分の枠（2026-10-13 追加）

- **関数名**は、先頭が小文字で、単語の区切りを大文字にする（例：`fetchWorkSettings`、`insertAttendance`）（D-053）。既存の `h()` はそのまま。
- **関数の `{` は、関数名と同じ行に書く**（例：`function fetchWorkSettings(PDO $pdo): array {`）。`if`・`foreach`・`try` なども同じ（D-056）。
- 依頼者が中身を書く関数の「枠」には、Claude が**処理の流れを、簡単なコメント（番号付きの手順）で書いておく**（D-054）。依頼者は、コメントを見ながら中身を書く。

### 3-2. 既存コードから変える点（本プロジェクトの要件のため）

| 変える点 | 理由 |
| --- | --- |
| 接続エラーの内容（`$e->getMessage()`）を画面に出さない。`error_log()` に記録し、画面には定型の文を出す | 接続先などの内部情報の漏えいを防ぐ |
| `PDO::ATTR_EMULATE_PREPARES => false` を追加する | SQL とデータを DB 側で分けて処理させ、SQL インジェクションへの耐性を高める |
| 接続時に `SET time_zone = '+09:00'` を実行する | 時刻はすべて日本時間（BR-004） |
| 削除や承認などの更新は **POST＋CSRF トークン**で行う（既存コードの `delete.php?id=…` のような GET は使わない） | GET のリンクだけで更新できると、外部のページから勝手に実行される危険があるため |
| `$_POST` を受け取ったら、**サーバー側で検証してから** SQL に渡す。権限と承認範囲も毎回確認する | 引継ぎ資料の注意点と、BR-003（ログイン済みのみ） |
| **エラー処理を共通化する**。想定外の例外は、共通のエラー処理（`src/errors.php`）で、`error_log()` に記録し、画面には定型の文（HTTP 500）を出す。`display_errors` は無効にする。個別の `try catch` は、**エラーの種類ごとに画面の対応が必要な箇所**（例：一意制約違反＝重複登録）と、トランザクションに限る | 既存コードの一覧・登録には `try catch` が無く、SQL が失敗すると PHP 標準のエラー（内部情報を含みうる）が出るため。すべての SQL を個別に `try catch` で囲むと、コードが冗長になり、握りつぶしの原因になる |
| 承認など、複数の更新を1つにまとめる処理は、`beginTransaction()`／`commit()`／`rollBack()` で囲む | 途中で失敗したとき、半端な状態にしないため |
| `SELECT *` ではなく、必要な列を書く（任意） | 列が増えても、画面や処理が影響を受けにくい |
| `created_at`・`updated_at` は、DB 側の既定値（`CURRENT_TIMESTAMP`／`ON UPDATE`）に任せる | DB設計書（確定）のとおり。SQL に `now()` を書かなくてよい |
| 画面で読み込む CSS・JS の URL は、`appUrl()` ではなく **`assetUrl()`** で作る（2026-10-22 追加。D-068） | URL の後ろにファイルの更新日時が付き、ファイルを変えるとブラウザが新しいファイルを読み込む（キャッシュ対策） |

### 3-3. このプロジェクトでの要否を判断した点（依頼者の指示：既存コードのままではなく、要否を判断する）

| 既存コードの書き方 | このプロジェクトでの判断（案） |
| --- | --- |
| 更新後に `header('Location: …'); exit;` で別の画面へ戻る（画面遷移） | **形は必要、遷移先は画面ごとに決める**。POST のあとにリダイレクトする形（二重送信の防止）は、申請・承認・アカウント作成などの画面で必要。ただし、遷移先は「一覧」とは限らず（例：申請後は記録表示に完了メッセージ付きで戻る、アカウント作成後は同じ画面に留まる）、**画面設計（画面遷移）で決める**。出退勤・休憩のように JavaScript から API（JSON）を呼ぶ操作は、リダイレクトしない（JSON を返し、画面を書き換える） |
| 画面・処理ごとに PHP ファイルを分ける（`createForm.php`、`create.php`、`editForm.php`、`update.php`、`delete.php`） | **そのままは採用しない**。本プロジェクトの画面は少なく（ログイン・メイン・記録表示・承認・アカウント管理）、操作ごとにファイルを分けると数が増える。**画面ごとに1つの PHP が、表示（GET）と更新（POST）を処理する**形にする。ファイルが増える場合は、機能ごとのフォルダに整理する（[技術方針.md](./技術方針.md) 2-1）。`index.php` はリポジトリ直下に置く |

> **社員 ID（`employee_id`、`reviewer_id`、`login_id`）は文字列**（例：`2026001`、`2026A01`）。`bindValue` には必ず `PDO::PARAM_STR` を指定する（既存コードの `id` のように `PDO::PARAM_INT` を使うと、英字を含む値が壊れる）。`departments.id` や `attendances.id` など、数値の `id` は `PDO::PARAM_INT`。

> `bindValue` は、呼んだ時点の値を渡す（定数や式もそのまま渡せる）。`bindParam` は変数を**参照**で結びつけ、`execute()` の前に変数の値が変わると変わった値が使われるため、本プロジェクトでは `bindValue` に揃える（D-060）。

## 4. 書き方の見本

DB の確定した構成（[DB設計書.md](./DB設計書.md)）に合わせた例。本番の実装でも、この形で書く（`departments` は部署マスタ）。

**ファイル：`src/errors.php`**（共通のエラー処理。想定外の例外を、記録して定型の文を出す）

```php
<?php
ini_set('display_errors', '0');

set_exception_handler(function (\Throwable $e) {
    error_log($e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('エラーが発生しました。時間をおいて、もう一度お試しください。');
});
```

**ファイル：`src/dbconnect.php`**

```php
<?php
include_once(__DIR__ . '/errors.php');

try {
    $pdo = new PDO(
        'mysql:dbname=attendance_training;host=localhost;charset=utf8mb4',
        'attendance_app',
        'ここにパスワード',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+09:00'");
} catch (\PDOException $e) {
    error_log($e->getMessage());
    header('Content-Type: text/plain; charset=UTF-8', true, 500);
    exit('データベースに接続できません。');
}
```

**ファイル：`src/functions.php`**

```php
<?php
function h($value) {
    // NULL は空文字にする（PHP 8.1 以降、htmlspecialchars に NULL を渡すと非推奨の警告が出るため）
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
```

**ファイル：`departments.php`**（一覧。取得して画面に出す例）

```php
<?php
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/functions.php');

$sql = "SELECT id, name FROM departments WHERE is_active = :is_active ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':is_active', 1, PDO::PARAM_INT);
$stmt->execute();
$departments = $stmt->fetchAll();
?>
<ul>
<?php foreach ($departments as $department): ?>
    <li><?= h($department['name']) ?></li>
<?php endforeach; ?>
</ul>
```

**ファイル：`departmentCreate.php`**（登録して一覧へ戻る例。実際には、権限・CSRF・入力の検証を先に行う）

```php
<?php
include_once(__DIR__ . '/src/dbconnect.php');

$name = $_POST['name'];

$sql = "INSERT INTO departments(name) VALUES(:name)";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':name', $name, PDO::PARAM_STR);
$stmt->execute();

header('Location: ./departments.php');
exit;
```

**ファイル：`departmentCreateSafe.php`**（上の登録に、重複の対応を加えた例。エラーの種類ごとに画面の対応が必要なときだけ、`try catch` を書く）

```php
<?php
include_once(__DIR__ . '/src/dbconnect.php');

$name = $_POST['name'];

$sql = "INSERT INTO departments(name) VALUES(:name)";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':name', $name, PDO::PARAM_STR);

try {
    $stmt->execute();
} catch (\PDOException $e) {
    if ($e->errorInfo[1] === 1062) {
        // 一意制約違反（同じ名前の部署がすでにある）。画面にエラーを出して戻る
        header('Location: ./departments.php?error=duplicate');
        exit;
    }
    throw $e;
}

header('Location: ./departments.php');
exit;
```

**複数の更新を1つにまとめる形**（承認などで使う）

```php
$pdo->beginTransaction();
try {
    // ここに複数の SQL（prepare → bindValue → execute）を書く
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

## 5. 環境（依頼者の WSL）

| 項目 | バージョン |
| --- | --- |
| PHP | 8.5.4（CLI） |
| Apache | 2.4.66 |
| MySQL | 8.4.11 |

- Claude の検証環境の PHP は 8.3 系のため、**8.3 でも 8.5 でも動く書き方**にする。8.5 で警告（非推奨）が出ないかは、依頼者の WSL で確認する。
- PHP の拡張機能は `pdo_mysql` と `mbstring` が必要（2026-10-15 に `mbstring` が無く、申請でエラーになったため追記。[WSL環境へのソース配置手順書.md](./WSL環境へのソース配置手順書.md) 0-3）。新しい拡張機能を使う場合は、方針の段階で WSL に入っているかを確認する。
- PHP は Apache の `mod_php`（確認済み）。`.htaccess` は使えない（`AllowOverride None`）ため、Apache の設定ファイルで制御する（[技術方針.md](./技術方針.md) 2-1、[Apache設定手順書.md](./Apache設定手順書.md)）。
