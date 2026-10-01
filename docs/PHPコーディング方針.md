# PHP コーディング方針（DB アクセスの書き方）

状態: **案（依頼者の承認待ち）**。DB まわりの書き方は、依頼者が以前に書いた PHP コード（`phpKakeibo`）に合わせる。その他の部分は、すべて合わせる必要はない（依頼者の指示）。

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
- 名前付きプレースホルダ＋`bindParam`（型を明示）＋`execute()` の流れ。
- `fetchAll()` と `fetch()` の使い分け。
- 画面ごとに PHP ファイルを分ける構成と、更新後に `header('Location: …'); exit;` で戻る形。
- 画面の `<?= h(…) ?>` による出力。

### 3-2. 既存コードから変える点（本プロジェクトの要件のため）

| 変える点 | 理由 |
| --- | --- |
| DB のユーザー・パスワードを、コードに直接書かない。Web ルートの外の設定ファイル（`/var/www/attendance_private/config.php`）から読み込む | リポジトリ直下が Web ルートで、Git にも入るため。漏えいを防ぐ |
| 接続エラーの内容（`$e->getMessage()`）を画面に出さない。`error_log()` に記録し、画面には定型の文を出す | 接続先などの内部情報の漏えいを防ぐ |
| `PDO::ATTR_EMULATE_PREPARES => false` を追加する | SQL とデータを DB 側で分けて処理させ、SQL インジェクションへの耐性を高める |
| 接続時に `SET time_zone = '+09:00'` を実行する | 時刻はすべて日本時間（BR-004） |
| 削除や承認などの更新は **POST＋CSRF トークン**で行う（既存コードの `delete.php?id=…` のような GET は使わない） | GET のリンクだけで更新できると、外部のページから勝手に実行される危険があるため |
| `$_POST` を受け取ったら、**サーバー側で検証してから** SQL に渡す。権限と承認範囲も毎回確認する | 引継ぎ資料の注意点と、BR-003（ログイン済みのみ） |
| 承認など、複数の更新を1つにまとめる処理は、`beginTransaction()`／`commit()`／`rollBack()` で囲む | 途中で失敗したとき、半端な状態にしないため |
| `SELECT *` ではなく、必要な列を書く（任意） | 列が増えても、画面や処理が影響を受けにくい |
| `created_at`・`updated_at` は、DB 側の既定値（`CURRENT_TIMESTAMP`／`ON UPDATE`）に任せる | DB設計書（確定）のとおり。SQL に `now()` を書かなくてよい |

> `bindParam` は変数を**参照**で結びつける。`execute()` の前に変数の値が変わると、変わった値が使われる。`bindParam` には必ず変数を渡し、定数や式を直接渡す場合は `bindValue` を使う。

## 4. 書き方の見本

DB の確定した構成（[DB設計書.md](./DB設計書.md)）に合わせた例。本番の実装でも、この形で書く（`departments` は部署マスタ）。

**ファイル：`/var/www/attendance_private/config.php`**（Web ルートの外に手動で作る。リポジトリには、値をダミーにした `config.sample.php` だけを置く）

```php
<?php
return [
    'db_name' => 'attendance_training',
    'db_host' => 'localhost',
    'db_user' => 'attendance_app',
    'db_pass' => 'ここにパスワード',
];
```

**ファイル：`src/dbconnect.php`**

```php
<?php
$config = require('/var/www/attendance_private/config.php');

try {
    $pdo = new PDO(
        'mysql:dbname=' . $config['db_name'] . ';host=' . $config['db_host'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
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
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
```

**ファイル：`departments.php`**（一覧。取得して画面に出す例）

```php
<?php
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/functions.php');

$sql = "SELECT id, name FROM departments WHERE is_active = :is_active ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
$is_active = 1;
$stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
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
$stmt->bindParam(':name', $name, PDO::PARAM_STR);
$stmt->execute();

header('Location: ./departments.php');
exit;
```

**複数の更新を1つにまとめる形**（承認などで使う）

```php
$pdo->beginTransaction();
try {
    // ここに複数の SQL（prepare → bindParam → execute）を書く
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
- PHP を Apache がどう実行しているか（`mod_php`／`php-fpm`）、`.htaccess` が有効かは未確認。確認のコマンドは [技術方針.md](./技術方針.md) の3章を参照。
