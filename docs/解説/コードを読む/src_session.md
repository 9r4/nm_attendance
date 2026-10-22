# `src/session.php` を読む（ログイン状態の保持と CSRF 対策）

## このファイルの役割

**セッション**（ブラウザごとに、サーバー側で値を覚えておく仕組み）を扱うファイル。次の3つを受け持つ。

1. セッションを始める。2時間操作しなかったら、ログイン状態を消す（自動ログアウト）。
2. **CSRF 対策**のトークンを作り、送られてきたトークンを確かめる。
3. 次の画面で1回だけ出す通知（フラッシュ）を、出し入れする。

- 「誰がログインしているか」の判断はしない。それは `src/auth.php` の役目（[src_auth.md](./src_auth.md)）。`src/auth.php` が、このファイルを読み込んで使う。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 4〜5行 | 定数：自動ログアウトまでの時間 |
| 7〜42行 | `startAppSession()`：セッションを始める・自動ログアウト |
| 44〜63行 | `csrfToken()`・`isValidCsrfToken()`・`requireValidCsrfToken()`：CSRF 対策 |
| 65〜75行 | `setFlash()`・`takeFlash()`：1回だけの通知 |

### セッションの仕組み（前提）

- `session_start()` を呼ぶと、PHP はブラウザに **Cookie でセッション ID**（長いランダムな文字列）を渡す。次のリクエストからは、ブラウザがその ID を送ってくる。
- サーバーは、ID ごとにファイルを作って `$_SESSION` の中身を保存する。ID が同じなら、前のリクエストで `$_SESSION` に入れた値を読める。
- このシステムは、ログインに成功すると `$_SESSION['user']` に `login_id` を入れる。これがあれば「ログイン中」。

---

## 1. 定数：自動ログアウトまでの時間

```php
// 無操作で自動ログアウトするまでの時間（秒）。2時間（2026-10-13 依頼者承認）
const SESSION_IDLE_SECONDS = 7200;
```

- 7200 秒＝2時間（D-058）。この値を、下の2か所（自動ログアウトの判断と、古いセッションの掃除）で使う。数字を2か所に書かず、定数1つにしているので、時間を変えるときはここだけ直せばよい。

## 2. `startAppSession()`：セッションを始める

### 二重に始めない

```php
function startAppSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
```

- `: void`：戻り値が無い関数。
- `session_status()` が `PHP_SESSION_ACTIVE` なら、このリクエストでは、もうセッションを始めている。何もせずに戻る（早期リターン）。
- この関数は、`login.php`・`logout.php`・`currentUser()`（`src/auth.php`）など、いろいろな場所から呼ばれる。何度呼んでも安全なように、最初に確かめている。

### 保存場所と、古いセッションの掃除

```php
    // セッションの保存場所をアプリ専用にする。
    // Ubuntu の標準の場所は、約24分で古いセッションを消す仕組みがあり、2時間を守れないため
    $savePath = sys_get_temp_dir() . '/attendance_sessions';
    if (!is_dir($savePath)) {
        mkdir($savePath, 0700, true);
    }
    session_save_path($savePath);
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
```

- `sys_get_temp_dir()`：一時ファイルの置き場所（ふつうは `/tmp`）。その中に、このアプリ専用のフォルダを作る。
- `mkdir($savePath, 0700, true)`：`0700` は「持ち主（Web サーバーの利用者）だけが読み書きできる」権限。3つ目の `true` は、途中のフォルダが無ければ一緒に作る指定。
- **GC（ガベージコレクション）**：古いセッションのファイルを消す掃除。
  - `gc_maxlifetime`：最後に使ってから、この秒数を過ぎたセッションを「古い」とみなす。2時間にそろえる。
  - `gc_probability`／`gc_divisor`：掃除をする確率。1／100＝リクエスト100回に1回くらいの割合で、掃除が走る。
- `ini_set()` の値は文字列で渡すので、`(string)` で数値を文字列にしている。

> **ここが重要**：Ubuntu の PHP は、標準の保存場所のセッションを、PHP とは別の仕組み（定期的に動く処理）で約24分（`php.ini` の `gc_maxlifetime` の 1440 秒）で消してしまう。保存場所が標準のままだと、ここで `gc_maxlifetime` を2時間にしても、24分で勝手にログアウトされる。**保存場所をアプリ専用にして、掃除を PHP の GC（2時間）だけにした**のは、このため。また、Ubuntu の `php.ini` は PHP 自身の GC を止めている（`gc_probability` が 0）ので、ここで 1 にして動かしている。

### セッション ID の扱い

```php
    ini_set('session.use_strict_mode', '1');
```

- **strict モード**：サーバーが作っていないセッション ID が送られてきたら、それを使わず、新しい ID を作る。攻撃者が自分で決めた ID を、ほかの人に使わせる攻撃（セッション固定攻撃）を防ぐ。

### Cookie の設定

```php
    session_name('ATTENDANCE_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => appUrl(),
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
```

- `session_name()`：Cookie の名前。標準の `PHPSESSID` から、アプリ専用の名前に変える。同じサーバーのほかの PHP アプリと、Cookie がぶつからない。
- `session_set_cookie_params()`：セッションの Cookie の性質を決める（`session_start()` より前に呼ぶ必要がある）。

| 設定 | 意味 |
| --- | --- |
| `'lifetime' => 0` | ブラウザを閉じたら Cookie を消す |
| `'path' => appUrl()` | このアプリの URL（`/attendance/`）の下でだけ Cookie を送る |
| `'secure' => …` | HTTPS で開いているときだけ、「HTTPS でしか送らない」印を付ける。`$_SERVER['HTTPS']` は HTTPS のときに空でない値が入る（サーバーによっては、HTTPS でないときに `'off'` が入るので、それも除く） |
| `'httponly' => true` | JavaScript から Cookie を読めなくする。XSS があっても、セッション ID を盗まれにくい |
| `'samesite' => 'Lax'` | ほかのサイトから送られる POST には、Cookie を付けない（CSRF への守りの1つ） |

### 自動ログアウト

```php
    $now = time();
    if (isset($_SESSION['user'], $_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['timed_out'] = true;
    }
    $_SESSION['last_activity'] = $now;
}
```

- `$_SESSION['last_activity']`：最後にリクエストが来た時刻。**この関数が呼ばれるたびに、今の時刻で上書きする**（最後の行）。
- `isset($a, $b)`：引数を複数書くと「全部ある」ときだけ `true`。ログイン中で、前の時刻もあるときだけ調べる。
- 前のリクエストから2時間より長くたっていたら：
  - `$_SESSION = [];`：中身を全部消す（ログイン状態も、CSRF トークンも消える）。
  - `session_regenerate_id(true)`：セッション ID を新しくする。`true` は、古い ID のファイルも消す指定。
  - `$_SESSION['timed_out'] = true;`：「自動でログアウトした」印を立てる。ログイン画面が、この印を見て案内を出す（[login.md](./login.md)）。

> **ここが重要**：GC（掃除）はおおよその仕組みで、2時間ちょうどにファイルが消えるとは限らない（掃除が走るのは確率まかせ）。だから、**「2時間たったか」は、毎回 `last_activity` で確かめている**。GC はファイルが溜まらないための掃除、判断はこの `if`、と役割を分けている。

## 3. CSRF 対策

**CSRF**（クロスサイト・リクエスト・フォージェリ）：ログイン中の人が、悪意のあるサイトを開いたとき、そのサイトに置かれたフォームから、このシステムへ勝手に POST を送らせる攻撃。ブラウザは Cookie（セッション ID）を自動で付けるので、何もしないと「本人の操作」として受け付けてしまう。

対策：フォームに**推測できない文字列（トークン）**を埋め込み、POST のたびにそれが送られてきたかを確かめる。悪意のあるサイトは、このトークンを知ることができない。

### `csrfToken()`：トークンを返す

```php
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
```

- まだトークンが無ければ作り、セッションに保存する。あれば、それを返す（セッションの間は同じトークンを使う）。
- `random_bytes(32)`：暗号に使える強さの、ランダムな 32 バイト。`rand()` などとは違い、次の値を推測できない。
- `bin2hex()`：バイトを 16 進数の文字（`0-9a-f`）にする。32 バイト → 64 文字。HTML に埋め込める形になる。
- 画面では、`<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">` のように埋め込む。JavaScript から API に送る画面では、`data-csrf-token` 属性に入れて、送るときに付ける（[index.md](./index.md)）。

### `isValidCsrfToken()`：送られてきたトークンが正しいか

```php
// 送られてきた CSRF トークンが正しいか
function isValidCsrfToken(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
```

- 次の3つが全部そろえば `true`、1つでも欠ければ `false` を返す。止めはしない（どうするかは呼ぶ側が決める）：
  - 送られてきた値が文字列である（`csrf_token[]=…` のように配列で送られていない）。
  - セッションにトークンがある（自動ログアウトなどで消えていない）。
  - 2つが一致する。
- `hash_equals()`：2つの文字列を比べる。`===` との違いは、**どこで食い違っても、比べる時間が同じ**こと。`===` は、先頭から比べて違う文字が見つかった時点で止まるので、かかった時間の差から「何文字目まで合っているか」を探られるおそれがある。秘密の値を比べるときは、`hash_equals()` を使う。
- `hash_equals()` は引数が文字列でないとエラーになる。先に `is_string()` と `empty()` で確かめているのは、そのため。`&&` は左が `false` になった時点で止まる（短絡評価）ので、右の `hash_equals()` まで進むのは、両方が文字列のときだけ。

### `requireValidCsrfToken()`：正しくなければ止める

```php
// 送られてきた CSRF トークンが正しくなければ、処理を止める
function requireValidCsrfToken(): void {
    if (!isValidCsrfToken()) {
        abort(400, '不正なリクエストです。画面を開き直して、もう一度お試しください。');
    }
}
```

- `isValidCsrfToken()` が `false` なら、400 で止める（`abort()` は、そこで処理を終える）。ほとんどの画面と API は、こちらを使う。
- PR 9 で、確かめる中身を `isValidCsrfToken()` に分けた（D-069）。ログイン画面だけは、400 で止めずに「画面の有効期限が切れました。…」と同じ画面に出したいので、`isValidCsrfToken()` を直接使う（[login.md](./login.md)）。**確かめ方は1か所**のまま、「止める」か「案内する」かを呼ぶ側で選べる。

> **ここが重要**：トークンを確かめるのは、**状態を変える POST すべて**（ログイン・ログアウト・打刻・申請・承認・管理の画面）。ただしログアウトは、もうログイン状態が無いときは確かめずにログイン画面へ移す（[logout.md](./logout.md)）。`samesite=Lax` の Cookie も CSRF を防ぐ働きをするが、ブラウザの実装に頼る守りなので、トークンを主な守りにしている。

## 4. 1回だけの通知（フラッシュ）

```php
// 次に表示する画面で、1回だけ出す通知（例：申請を受け付けたあと、画面を読み込み直して出す）
function setFlash(string $message): void {
    $_SESSION['flash'] = $message;
}

// 通知を取り出す（取り出したら消える）。無ければ null
function takeFlash(): ?string {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($message) ? $message : null;
}
```

- 使い方の例：申請の API が `setFlash('申請を受け付けました。…')` で通知を保存し、JavaScript が画面を読み込み直す。読み込み直した記録表示の画面が `takeFlash()` で通知を取り出して表示する。
- `takeFlash()` は、取り出したら `unset()` で消す。もう一度画面を開いても、同じ通知は出ない。
- `?string`：文字列か `null` を返す型。通知が無いときは `null`。
- `is_string($message) ? … : null`：セッションの中身が、万一文字列でなかったときにも、型どおり（`string` か `null`）に返すため。

> **なぜセッションに入れるのか**：画面を読み込み直す（またはリダイレクトする）と、PHP の変数は全部消える。リクエストをまたいで値を渡すには、セッションに入れるしかない。「入れる → 次の画面で取り出して消す」の形にしておくと、1回だけ出る通知になる。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 何度呼んでも安全にする | `session_status()` で、始めていたら何もしない |
| 設定値は定数1つに | `SESSION_IDLE_SECONDS` を判断と GC の両方で使う |
| 環境のくせに合わせる | Ubuntu の約24分の掃除を避けるため、保存場所をアプリ専用に |
| Cookie は守りを固める | `httponly`・`samesite`・HTTPS なら `secure`・`path` |
| 時間の判断は自分で行う | GC にまかせず、`last_activity` で2時間を確かめる |
| CSRF はトークンで防ぐ | `random_bytes()` で作り、`hash_equals()` で比べる |
| 型を確かめてから使う | `is_string($token)` |
| 「調べる」と「止める」を分ける | `isValidCsrfToken()` と `requireValidCsrfToken()` |
| リクエストをまたぐ通知 | `setFlash()`／`takeFlash()` |

## 関連するファイル

- このファイルを使う側：[src_auth.md](./src_auth.md)（`currentUser()` が `startAppSession()` を呼ぶ）、[login.md](./login.md)、[logout.md](./logout.md)
- トークンを埋め込む画面：[index.md](./index.md)（`data-csrf-token`）、[templates.md](./templates.md)（ログアウトボタンのフォーム）
- フラッシュを使う側：[api_requests.md](./api_requests.md)・[api_approvals.md](./api_approvals.md)（`setFlash()`）、[records_index.md](./records_index.md)・[approvals_index.md](./approvals_index.md)・`admin/` の各画面（`takeFlash()`。管理の画面は `setFlash()` も使う）
- 400 を返す関数：[src_functions.md](./src_functions.md)（`abort()`）
