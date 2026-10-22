# `login.php` を読む（ログイン画面）

## このファイルの役割

ID とパスワードを入力して、ログインする画面。**1つのファイルで、画面の表示（GET）と、ログインの処理（POST）の両方を受け持つ**。

- 開いたとき（GET）：ログインのフォームを出す。
- 「ログイン」を押したとき（POST）：入力を確かめて、正しければメイン画面へ移る。誤っていれば、同じ画面に誤りの文を出す。
- ID とパスワードの照合そのものは、`src/auth.php` の `attemptLogin()` に任せる（[src_auth.md](./src_auth.md)）。このファイルは、入力の受け取りと、画面の出し分けだけをする。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 1〜5行 | 共通の部品を読み込む |
| 7〜10行 | セッションを始め、ログイン済みならメイン画面へ移す |
| 12〜18行 | 画面に出す文の準備（自動ログアウトの案内） |
| 20〜32行 | 「ログイン」が押されたとき（POST）の処理 |
| 34〜71行 | 画面（共通の上部、案内・誤りの文、フォーム、共通の下部） |

---

## 1. 共通の部品を読み込む

```php
<?php
// ログイン画面（FR-001）
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/auth.php');
```

- `src/functions.php`：`h()`・`appUrl()`・`redirect()` など。
- `src/dbconnect.php`：DB の接続（`$pdo`）。中で `src/errors.php` も読み込むので、想定外のエラーは共通のエラー処理に回る。
- `src/auth.php`：`attemptLogin()`。中で `src/session.php` も読み込むので、`startAppSession()`・`csrfToken()`・`requireValidCsrfToken()` も使える。

## 2. ログイン済みなら、メイン画面へ

```php
startAppSession();
if (isset($_SESSION['user'])) {
    redirect();
}
```

- `startAppSession()` でセッションを始める（[src_session.md](./src_session.md)）。これで `$_SESSION` が使えるようになる。
- `$_SESSION['user']` は、ログインに成功したときに `attemptLogin()` が入れる。これがあれば、もうログインしているので、ログイン画面を出さずにメイン画面へ移す。
- `redirect()` は引数を省略すると `''`（アプリの先頭＝メイン画面）へ移り、**そこで処理を終える**（`exit`）。だから `else` は要らない。
- ほかの画面と違い、`requireLogin()` は呼ばない。ログインしていない人が使う画面だから。

## 3. 画面に出す文の準備

```php
$loginId = '';
$errorMessage = '';
$infoMessage = '';
if (!empty($_SESSION['timed_out'])) {
    $infoMessage = '一定時間操作がなかったため、ログアウトしました。もう一度ログインしてください。';
    unset($_SESSION['timed_out']);
}
```

- 使う変数を、先に空文字で用意しておく。後半の HTML で `$errorMessage !== ''` のように調べるとき、未定義の変数を読まずに済む。
- `$_SESSION['timed_out']`：2時間操作しなかったとき、`startAppSession()` がログイン状態を消して、この印を立てる。印があれば「自動でログアウトした」と案内し、`unset()` で印を消す。消しておかないと、ログイン画面を開くたびに同じ案内が出てしまう。
- `empty()`：変数が無い、または `false`・`0`・`''`・`null` などなら `true`。「無いかもしれない」値を、警告を出さずに調べられる。

自動ログアウトから、この案内が出るまでの流れ：

1. 2時間操作しなかった人が、メイン画面などを開く。
2. `startAppSession()` が、ログイン状態を消して `timed_out` の印を立てる。
3. ログイン状態が無いので、`requireLogin()` がログイン画面へ移す。
4. ログイン画面が印を見つけて、案内を出し、印を消す。

## 4. 「ログイン」が押されたとき（POST）

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
```

- `$_SERVER['REQUEST_METHOD']` は、送られてきた方法（`'GET'` か `'POST'` など）。フォームの送信は POST なので、POST のときだけ処理する。GET（画面を開いただけ）なら、この `if` を飛ばしてフォームを出す。
- `requireValidCsrfToken()`：フォームに埋め込んだ CSRF トークンが、送られてきたものと一致するかを確かめる。一致しなければ 400 で止める。ほかのサイトに置かれたフォームから、勝手にログインさせられるのを防ぐ（[src_session.md](./src_session.md)）。

```php
    $loginId = is_string($_POST['login_id'] ?? null) ? trim($_POST['login_id']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
```

- `$_POST['login_id'] ?? null`：送られてこなかったら `null`。`??` を使うと、キーが無くても警告が出ない。
- `is_string(…)`：`$_POST` の値は、ふつうは文字列だが、`login_id[]=a` のように送ると**配列**になる。配列のまま `trim()` に渡すとエラーになるので、文字列のときだけ使い、それ以外は `''` にする。
- ID は `trim()` で前後の空白を取る（コピーして貼ったときに空白が付くことがあるため）。**パスワードは `trim()` しない**。空白もパスワードの一部かもしれないので、送られたとおりに照合する。

```php
    if ($loginId === '' || $password === '') {
        $errorMessage = 'ID とパスワードを入力してください。';
    } elseif (strlen($loginId) <= 20 && attemptLogin($pdo, $loginId, $password)) {
        redirect();
    } else {
        $errorMessage = 'ID またはパスワードが正しくありません。';
    }
}
```

- どちらかが空なら、DB を調べずに「入力してください」と出す。
- `strlen($loginId) <= 20`：ログイン ID の列は 20 文字まで（DB の `login_id` は `VARCHAR(20)`）。それより長い ID は存在しないので、DB に問い合わせずに「正しくありません」にする。`&&` は左が `false` なら右を実行しない（短絡評価）ので、長すぎるときは `attemptLogin()` が呼ばれない。
- `attemptLogin()` が `true`（ログイン成功）なら、メイン画面へ移る。

> **ここが重要**：失敗したときの文は、**「ID が無い」「パスワードが違う」「利用できないアカウント」のどれでも、同じ「ID またはパスワードが正しくありません」**にしている。理由ごとに文を変えると、「この ID は存在する」と外の人に教えてしまい、パスワードを当てる手がかりになる。`attemptLogin()` も、理由を区別せずに `true`／`false` だけを返す作りになっている。

> **成功したら、別の画面へ移す（リダイレクト）**：ログインの POST のあと、画面をそのまま出すと、ブラウザの「再読み込み」で同じ POST がもう一度送られてしまう。`redirect()` で GET の画面へ移しておけば、再読み込みしても GET が送られるだけになる。

## 5. 画面

```php
$pageTitle = 'ログイン';
include(__DIR__ . '/templates/header.php');
?>
```

- 共通の上部を出す（[templates.md](./templates.md)）。このファイルは `$currentUser` を用意しないので、ヘッダーには氏名・ログアウトボタン・メニューが出ない。

```php
<?php if ($infoMessage !== ''): ?>
    <p class="alert alert--info" role="status"><?= h($infoMessage) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <p class="alert alert--error" role="alert"><?= h($errorMessage) ?></p>
<?php endif; ?>
```

- 文があるときだけ出す。
- `role="status"`・`role="alert"`：画面の読み上げソフトに、案内や誤りであることを伝える。`alert` は、出たらすぐに読み上げられる。

```php
<form class="login__form" method="post" action="<?= h(appUrl('login.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <div class="form-field">
        <label class="form-field__label" for="login_id">ID</label>
        <input class="form-field__input" type="text" id="login_id" name="login_id" value="<?= h($loginId) ?>" maxlength="20" autocomplete="username" required autofocus>
    </div>
    <div class="form-field">
        <label class="form-field__label" for="password">パスワード</label>
        <input class="form-field__input" type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="button button--primary button--block" type="submit">ログイン</button>
</form>
```

- `method="post"` で、自分自身（`login.php`）に送る。送った先で、4章の POST の処理が動く。
- `<input type="hidden" name="csrf_token" …>`：画面には見えない入力欄に、CSRF トークンを入れておく。フォームを送ると、ほかの入力と一緒に `$_POST['csrf_token']` として届く。
- ID の欄は `value="<?= h($loginId) ?>"` で、**誤ったときに、入力した ID を残す**（打ち直しの手間を減らす）。最初に開いたときは `$loginId` が `''` なので空になる。パスワードの欄には値を戻さない。
- `maxlength="20"`：ブラウザで 20 文字より長く入力できないようにする。ただし、ブラウザの制限は外せるので、サーバーでも `strlen($loginId) <= 20` を確かめている（4章）。
- `autocomplete="username"`・`"current-password"`：ブラウザのパスワード管理に「ここが ID、ここがパスワード」と伝え、保存した ID・パスワードを入れてもらえるようにする。
- `<label for="login_id">` と `id="login_id"` を結びつけると、ラベルを押しても入力欄が選ばれる。

```php
<details class="login__forgot">
    <summary>パスワードをお忘れの方</summary>
    <p>システム管理者にお問い合わせください。</p>
</details>
```

- `<details>` と `<summary>`：`summary` を押すと、中身が開いたり閉じたりする。JavaScript を使わずに作れる。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 1つのファイルで GET と POST を受ける | `$_SERVER['REQUEST_METHOD'] === 'POST'` で分ける |
| 受け取った値の型を確かめる | `is_string($_POST['…'] ?? null)` |
| 失敗の理由を外に教えない | どの失敗も同じ文 |
| ブラウザの制限を信用しない | `maxlength` があっても、サーバーで長さを確かめる |
| POST のあとはリダイレクト | 成功したら `redirect()` |
| 一度だけの印は、使ったら消す | `timed_out` を `unset()` |
| フォームには CSRF トークン | `hidden` の入力欄と `requireValidCsrfToken()` |

## 関連するファイル

- ログインの照合：[src_auth.md](./src_auth.md)（`attemptLogin()`）
- セッション・CSRF：[src_session.md](./src_session.md)（`startAppSession()`・`csrfToken()`・`requireValidCsrfToken()`）
- 共通の関数：[src_functions.md](./src_functions.md)（`h()`・`appUrl()`・`redirect()`）
- 共通の上部・下部：[templates.md](./templates.md)
- ログアウト：[logout.md](./logout.md)
- DB の読み書き：`src/queries/login_accounts.php`（`fetchLoginAccount()`・`updateLastLoginAt()`）
