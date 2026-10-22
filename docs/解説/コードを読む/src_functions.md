# `src/functions.php` を読む（どこでも使う小さな関数）

## このファイルの役割

どの画面・API からも使う、小さな共通の関数を集めたファイル。すべての画面と API が、最初に読み込む。

- 画面に値を出すときのエスケープ（`h()`）、URL の組み立て（`appUrl()`・`assetUrl()`）、応答を返して終わる関数（`redirect()`・`jsonResponse()`・`abort()`）など。
- DB も、セッションも使わない。どの関数も、受け取った値と PHP の情報だけで動く。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 4〜5行 | タイムゾーンを日本時間にする |
| 7〜10行 | `h()`：画面に出す値のエスケープ |
| 12〜30行 | `appUrl()`・`assetUrl()`：URL を作る |
| 32〜35行 | `formatTime()`：時刻の形を整える |
| 37〜56行 | `redirect()`・`jsonResponse()`・`abort()`：応答を返して処理を終える |

---

## 1. タイムゾーン

```php
// 時刻は、すべて日本時間（BR-004）
date_default_timezone_set('Asia/Tokyo');
```

- 関数の外に書いた処理は、ファイルを読み込んだときに1回だけ実行される。
- `date()` などの日付の関数が使うタイムゾーンを、日本時間にする。PHP の設定（`php.ini`）が別のタイムゾーンでも、このアプリでは日本時間で動く。
- DB 側も、接続したときに日本時間にしている（[src_dbconnect.md](./src_dbconnect.md)）。PHP と DB の両方をそろえておかないと、`NOW()` で入れた時刻と `date()` の時刻がずれる。

## 2. `h()`：画面に出す値をエスケープする

```php
// 画面に出す値をエスケープする（XSS 対策）。NULL は空文字にする
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
```

- 利用者が入力した値（氏名・理由など）をそのまま HTML に出すと、`<script>…</script>` が「文字」ではなく「プログラム」として動いてしまう（XSS：クロスサイトスクリプティング）。
- `htmlspecialchars()` は、`<` を `&lt;`、`>` を `&gt;`、`&` を `&amp;` のように置き換える。ブラウザは、それを「ただの文字」として表示する。
- `ENT_QUOTES`：`"` と `'` も置き換える指定。`value="…"` のように属性の中に出すとき、値の中の `"` で属性が閉じてしまうのを防ぐ。
- `(string)`：`null` や数値を文字列にしてから渡す。`null` は `''` になる。
- 引数に型を書いていないのは、文字列・数値・`null` のどれでも受け取るため。

> **ここが重要**：このシステムの画面は、**値を出すところを例外なく `<?= h(…) ?>` で書く**。「この値は安全そうだから」と省くと、どこが安全でどこが危ないかを1つずつ考えることになり、漏れが起きる。全部に付ける、と決めておけば迷わない。

## 3. `appUrl()`：アプリの中の URL を作る

```php
// アプリのパスから URL を作る（例：appUrl('css/style.css') → /attendance/css/style.css）
// アプリを置いた場所（/var/www/html/attendance など）に合わせて、先頭のパスを自動で付ける
function appUrl(string $path = ''): string {
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(__DIR__ . '/..');
    $base = '';
    if ($documentRoot !== false && $appRoot !== false && str_starts_with($appRoot, $documentRoot)) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($documentRoot)));
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}
```

- `$_SERVER['DOCUMENT_ROOT']`：Web サーバーのドキュメントルート（例：`/var/www/html`）。
- `$appRoot`：このファイル（`src/`）の1つ上＝アプリのフォルダ（例：`/var/www/html/attendance`）。
- `realpath()`：`..` やシンボリックリンクを解いた、本当の絶対パスにする。パスが無ければ `false`。
- アプリのフォルダがドキュメントルートの中にあれば（`str_starts_with()`：「〜で始まるか」）、その差の部分（`/attendance`）を `$base` にする。`str_replace('\\', '/', …)` は、Windows の区切り文字 `\` を `/` にそろえる。
- 最後に、`$base` の後ろの `/` と、`$path` の前の `/` を取ってから `/` 1つでつなぐ。どちらに `/` が付いていても、`//` にならない。

| 呼び方 | 結果（`/var/www/html/attendance` に置いた場合） |
| --- | --- |
| `appUrl()` | `/attendance/`（メイン画面） |
| `appUrl('css/style.css')` | `/attendance/css/style.css` |
| `appUrl('/login.php')` | `/attendance/login.php` |

- `string $path = ''` は引数の既定値。省略すると `''` になる。

> **なぜ自動で計算するのか**：URL を `/attendance/…` と書いてしまうと、別の場所に置いた環境（Claude の検証環境など）では、すべてのリンクが切れる。置いた場所から計算すれば、**同じコードがどこに置いても動く**。

## 4. `assetUrl()`：CSS・JS の URL を作る（キャッシュ対策）

```php
// CSS・JS の URL を作る。後ろに、ファイルを最後に変えた日時を付ける（例：/attendance/css/style.css?v=1761100000。D-068）
// ファイルを変えると URL が変わるので、ブラウザが古いファイル（キャッシュ）を使わなくなる。ファイルが無いときは、日時を付けない
function assetUrl(string $path): string {
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $time = is_file($file) ? filemtime($file) : false;
    return appUrl($path) . ($time === false ? '' : '?v=' . $time);
}
```

- `filemtime()`：ファイルを最後に変えた日時を、1970年からの秒数で返す。失敗すると `false`。
- `is_file()` で先にファイルがあるかを確かめ、無ければ `false` にする（無いファイルに `filemtime()` を使うと警告が出るため）。
- ブラウザは、一度読み込んだ CSS・JS を保存しておき（キャッシュ）、同じ URL なら保存したものを使う。ファイルを変えると `?v=` の数字が変わって別の URL になるので、新しいファイルを読み込む。
- 画面で CSS・JS を読み込むときは、`appUrl()` ではなく、必ずこの関数を使う（D-068）。

## 5. `formatTime()`：時刻を `HH:MM` にする

```php
// 'HH:MM:SS' 形式の時刻を 'HH:MM' にする（NULL は '--:--'）
function formatTime(?string $time): string {
    return $time === null ? '--:--' : substr($time, 0, 5);
}
```

- `?string`：文字列か `null` を受け取る型（nullable 型）。
- `substr($time, 0, 5)`：先頭から5文字。`'18:00:00'` → `'18:00'`。
- 勤務設定の時刻（`'18:00:00'` のような、日付の無い時刻）に使う。日付付きの日時（`'2026-10-15 09:01:00'`）から時刻を取り出すのは、`src/attendance.php` の `formatClock()`（11文字目から5文字）。**受け取る形が違う**ので、関数を分けている。

## 6. 応答を返して、処理を終える関数

この3つは、どれも**最後に `exit` で処理を終える**。

### `redirect()`：別の画面へ移す

```php
// アプリ内の画面へ移動する（例：redirect('login.php')）
function redirect(string $path = ''): never {
    header('Location: ' . appUrl($path));
    exit;
}
```

- `Location` ヘッダーを返すと、ブラウザがその URL を開き直す。
- 戻り値の型 `never`（PHP 8.1 以降）：「この関数からは戻ってこない（必ず `exit` か例外で終わる）」という意味。

> **ここが重要**：`header('Location: …')` だけでは、PHP の処理は止まらない。`exit` を忘れると、後ろの処理（たとえば、ログインしていない人には見せない画面）がそのまま実行されて、出力されてしまう。`redirect()` に `exit` までまとめておけば、忘れることがない。`never` 型を付けておくと、「呼んだら戻ってこない」ことがコードを読む人にも分かる。

### `jsonResponse()`：JSON を返す（API 用）

```php
// JSON を返して、処理を終える（API の応答）
function jsonResponse(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
```

- `http_response_code()`：HTTP の状態（200・400・401 など）を決める。
- `Content-Type: application/json`：返す中身が JSON であることを、ブラウザに伝える。
- `Cache-Control: no-store`：応答を保存させない。勤怠の状態は押すたびに変わるので、古い応答を使われると困る。
- `exit(文字列)`：その文字列を出力してから終わる。
- `JSON_UNESCAPED_UNICODE`：日本語をそのまま出す。`JSON_THROW_ON_ERROR`：変換に失敗したら例外にする（失敗に気付ける）。

### `abort()`：定型の文を返す

```php
// 定型の文と HTTP の状態を返して、処理を終える（例：abort(403, 'この画面を使う権限がありません。')）
function abort(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}
```

- 権限が無い（403）、CSRF トークンが違う（400）、送り方が違う（405）など、画面を出さずに止めたいときに使う。
- `text/plain`：HTML ではなく、ただの文字として表示させる。HTML として解釈されないので、文に `<` などが入っていても安全。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 出す値は必ずエスケープ | `h()` を例外なく使う |
| 置き場所に依存しない | `appUrl()` が先頭のパスを自動で計算する |
| キャッシュは URL を変えて避ける | `assetUrl()` が `?v=更新日時` を付ける |
| 止める処理は `exit` までまとめる | `redirect()`・`jsonResponse()`・`abort()` |
| 戻らない関数には `never` | 3つの関数の戻り値の型 |
| 受け取る形が違えば関数を分ける | `formatTime()`（時刻）と `formatClock()`（日時） |

## 関連するファイル

- 使う側：すべての画面と API（[index.md](./index.md)、[login.md](./login.md)、[logout.md](./logout.md)、[templates.md](./templates.md) など）
- 日時の形を整えるもう1つの関数：[src_attendance.md](./src_attendance.md)（`formatClock()`）
- テスト：[tests.md](./tests.md)。`tests/AssetUrlTest.php`（`assetUrl()` に更新日時が付くこと、ファイルを変えると URL が変わること）
