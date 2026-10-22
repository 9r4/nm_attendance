# `src/dbconnect.php` を読む（DB への接続）

## このファイルの役割

MySQL に接続して、接続結果（PDO のオブジェクト）を変数 **`$pdo`** に入れるファイル。DB を使う画面・API は、すべて最初にこのファイルを読み込み、あとは `$pdo` を関数に渡して使う。

- このファイルは**依頼者が書いた**（DB まわりは依頼者の担当。D-046）。前半のコメントは、Claude が用意した「枠」（処理の流れの説明）の名残り。
- 共通のエラー処理（`src/errors.php`）も、ここで読み込む。DB を使う画面は、`dbconnect.php` を読むだけで、エラー処理も有効になる。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 1〜5行 | 説明と、共通のエラー処理の読み込み |
| 7〜23行 | 枠のコメント（何をどの順に書くか） |
| 25〜27行 | 接続先・ユーザー・パスワード |
| 29〜39行 | 接続する。失敗したら、決まった文を出して終わる |
| 40行 | DB のタイムゾーンを日本時間にする |

---

## 1. 共通のエラー処理を読み込む

```php
<?php
// DB に接続して、接続結果を $pdo に入れる（ほかのファイルは $pdo を使う）
// 書き方の見本：docs/PHPコーディング方針.md の 4章「src/dbconnect.php」

include_once(__DIR__ . '/errors.php');
```

- `src/errors.php` を読み込むと、エラーの文が画面に出なくなり、想定外の例外は「エラーが発生しました。…」の定型の文（HTTP 500）になる（[src_errors.md](./src_errors.md)）。
- 接続より先に読み込んでおくので、接続の処理で想定外のことが起きても、共通のエラー処理が受け止める。

## 2. 枠のコメント

```php
// TODO（依頼者）：DB に接続する
// 1. try { … } catch (\PDOException $e) { … } で囲む
// 2. try の中：new PDO(…) で接続して、$pdo に入れる
…
// 4. catch の中：
//    - error_log($e->getMessage()); で、エラーの内容をログに記録する（画面には出さない）
//    - header('Content-Type: text/plain; charset=UTF-8', true, 500); で、エラーの状態にする
//    - exit('データベースに接続できません。'); で、定型の文を出して終わる

// throw new RuntimeException('src/dbconnect.php は未実装です');
```

- このプロジェクトでは、依頼者が中身を書く部分に、Claude が**番号付きの手順をコメントで書いておく**（D-054）。依頼者は、このコメントを見ながら下のコードを書いた。
- 最後の `throw new RuntimeException(…)` は、中身が書かれる前に「未実装」と分かるようにしていた仮の1行。中身を書いたので、コメントにして止めてある。

## 3. 接続先・ユーザー・パスワード

```php
$dsn = 'mysql:host=localhost;dbname=attendance_training;charset=utf8mb4';
$user = 'attendance_app';
$pass = '…';
```

- **DSN**（データソース名）：どの種類の DB の、どこの、どのデータベースに、どの文字コードでつなぐかを、1つの文字列で表したもの。
  - `mysql:`：MySQL を使う。
  - `host=localhost`：同じコンピューターの MySQL。
  - `dbname=attendance_training`：使うデータベース。
  - `charset=utf8mb4`：文字コード。`utf8mb4` は、絵文字などの4バイトの文字も扱える UTF-8。
- ユーザーは、このアプリ専用の `attendance_app`。パスワードも、この DB 専用のものにしている（ここでは伏せている）。

> **ここが重要**：接続の情報を、ファイルに直接書いている（ハードコーディング）。これは**学習用で外に公開しない**ことを前提にした、依頼者の判断（D-045）。パスワードは GitHub（非公開）のリポジトリと、その履歴に残るので、**リポジトリを公開しない・ほかで使っているパスワードを使わない**ことが条件になっている。`src/` は Apache の設定で Web から見えないようにしてある（D-039）。将来、外に公開するなら、接続の情報をリポジトリの外（Web ルートの外の設定ファイルや、環境変数）に移す（[技術方針.md](../../技術方針.md) 2-2）。

## 4. 接続する

```php
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
```

- `new PDO(…)`：DB に接続して、接続を表すオブジェクトを作る。4つ目の引数は、動きを決めるオプション（配列）。
- 3つのオプション：

| オプション | 意味 | なぜ必要か |
| --- | --- | --- |
| `ATTR_ERRMODE => ERRMODE_EXCEPTION` | SQL が失敗したら例外を投げる | 失敗に気付ける。例外は、共通のエラー処理か、個別の `catch`（一意制約の違反など）で受け止める |
| `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC` | 取得した行を、列名をキーにした配列（連想配列）にする | `$row['check_in_at']` のように列名で読める |
| `ATTR_EMULATE_PREPARES => false` | プレースホルダ（`:login_id` など）の処理を、PHP ではなく MySQL にさせる | SQL の文とデータを、DB 側で分けて扱う。SQL インジェクションへの守りが固くなる |

- `ERRMODE_EXCEPTION` は、PHP 8 では最初からこの設定だが、明示しておくと、読む人に「失敗は例外になる」と分かる。

```php
} catch (PDOException $e) {
    error_log($e->getMessage());
    header('Content-Type: text/plain; charset=UTF-8', true, 500);
    exit('データベースに接続できません。');
}
```

- 接続できなかったとき（MySQL が止まっている、パスワードが違う など）は、`PDOException` が投げられる。
- `error_log($e->getMessage())`：エラーの文を、ログにだけ書く。エラーの文には、ユーザー名や接続先が入ることがあるので、画面には出さない。
- `header(…, true, 500)`：`header()` の2つ目の引数は「同じ名前のヘッダーを置き換えるか」、3つ目は HTTP の状態。これ1行で、`Content-Type` と 500 の両方を設定している。
- 画面には「データベースに接続できません。」とだけ出して、`exit` で終わる。接続できなければ、どの画面も先に進めないので、ここで止める。

> **共通のエラー処理があるのに、なぜここで `catch` するのか**：共通のエラー処理に任せると、画面の文は「エラーが発生しました。…」になる。接続の失敗は「DB に届かない」という分かりやすい原因なので、専用の文を出すために、ここだけ個別に受け止めている。`catch` するのは `PDOException`（DB のエラー）だけで、それ以外の例外は共通のエラー処理に任せる。

## 5. タイムゾーンを日本時間にする

```php
$pdo->exec("SET time_zone = '+09:00'");
```

- `exec()`：結果の行を返さない SQL を実行する。
- MySQL の `NOW()` や、`updated_at` の自動の日時（`CURRENT_TIMESTAMP`）は、MySQL のタイムゾーンで決まる。接続ごとに日本時間（UTC より9時間進んだ時刻）にしておくことで、PHP 側（`src/functions.php` の `date_default_timezone_set('Asia/Tokyo')`）とそろう（BR-004）。
- この1行は `try` の外にある。接続ができた後なので、ここで失敗することはまず無いが、失敗すれば例外になり、共通のエラー処理が受け止める。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 接続は1か所にまとめる | どの画面も `dbconnect.php` を読んで `$pdo` を使う |
| SQL の失敗は例外にする | `ERRMODE_EXCEPTION` |
| プレースホルダは DB に処理させる | `ATTR_EMULATE_PREPARES => false` |
| エラーの詳細は画面に出さない | `error_log()` に書き、画面には定型の文 |
| 文字コードとタイムゾーンをそろえる | `charset=utf8mb4`、`SET time_zone = '+09:00'` |
| 接続の情報の扱いを決めておく | 直接書くのは、非公開・専用パスワードが条件（D-045） |

## 関連するファイル

- 共通のエラー処理：[src_errors.md](./src_errors.md)
- タイムゾーン（PHP 側）：[src_functions.md](./src_functions.md)
- `$pdo` を使う SQL：`src/queries/` の各ファイル（依頼者が書く）
- 書き方の見本：[PHPコーディング方針.md](../../PHPコーディング方針.md)（4章）
- 接続の情報の扱い：[技術方針.md](../../技術方針.md)（2-2）、[決定事項.md](../../決定事項.md)（D-045）
