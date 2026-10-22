# `logout.php` を読む（ログアウト）

## このファイルの役割

画面上部の「ログアウト」ボタンが送る先。ログイン状態を消して、ログイン画面へ移す。画面（HTML）は持たない。

- 受け付けるのは、**ログアウトボタンからの POST だけ**。リンクを開く（GET）だけではログアウトしない。
- ログアウトの中身（セッションと Cookie を消す）は、`src/auth.php` の `logoutUser()` に任せる（[src_auth.md](./src_auth.md)）。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 1〜5行 | 共通の部品を読み込む |
| 7〜10行 | POST 以外なら、405 で止める |
| 12〜20行 | セッションを始め、すでにログアウトしていればログイン画面へ。そうでなければ CSRF トークンを確かめ、ログアウトして、ログイン画面へ移す |

---

## 1. 共通の部品を読み込む

```php
<?php
// ログアウト（FR-002）。画面のログアウトボタン（POST）からのみ受け付ける
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/errors.php');
include_once(__DIR__ . '/src/auth.php');
```

- ほかの画面と違い、`src/dbconnect.php` を読み込まない。ログアウトは DB を使わないから。
- その代わり、`src/errors.php`（共通のエラー処理）を直接読み込む。ふだんは `src/dbconnect.php` の中で読み込まれるが、ここでは `dbconnect.php` を読まないので、自分で読み込んでおく（[src_errors.md](./src_errors.md)）。

## 2. POST 以外は受け付けない

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    abort(405, 'ログアウトは、画面のログアウトボタンから行ってください。');
}
```

- `405 Method Not Allowed` は「この送り方（GET など）は受け付けない」という HTTP の状態。`Allow: POST` のヘッダーで「POST なら受け付ける」と伝える（405 を返すときは、`Allow` を付ける決まりになっている）。
- `abort()` は、文を出して**そこで処理を終える**（[src_functions.md](./src_functions.md)）。

> **ここが重要**：GET でログアウトできると、ほかのサイトに `<img src="…/logout.php">` のような1行を置かれただけで、そのページを見た人が勝手にログアウトさせられる。**状態を変える操作は、POST と CSRF トークンで受け付ける**のが、このシステムの決まり。ログアウトも例外にしない。

## 3. ログアウトして、ログイン画面へ

```php
startAppSession();
// すでにログアウトしている（自動ログアウトなど）なら、確かめずにログイン画面へ移す。
// ログインしていない人を「ログアウト」させても何も起きないので、CSRF の確認は要らない（D-069）
if (!isset($_SESSION['user'])) {
    redirect('login.php');
}
requireValidCsrfToken();
logoutUser();
redirect('login.php');
```

- `startAppSession()`：セッションを始める。これをしないと `$_SESSION` が空のままで、トークンを確かめられない（[src_session.md](./src_session.md)）。
- `if (!isset($_SESSION['user']))`：ログイン状態が無ければ、何もせずにログイン画面へ移す。`redirect()` はそこで処理を終えるので、下の行は動かない（[src_functions.md](./src_functions.md)）。
- `requireValidCsrfToken()`：ログアウトボタンのフォームに埋め込まれた CSRF トークンを確かめる。違えば 400 で止める。
- `logoutUser()`：セッションの中身と、ブラウザの Cookie を消す。
- `redirect('login.php')`：ログイン画面へ移す。

> **ここが重要**：2時間以上操作しなかったあとで「ログアウト」を押すと、`startAppSession()` が先にログイン状態を消し、そのとき CSRF トークンも一緒に消える。前は、ここで `requireValidCsrfToken()` が 400（「不正なリクエストです。…」）で止まっていた。PR 9 で、**ログイン状態が無ければ CSRF を確かめずにログイン画面へ移す**ように直した（D-069）。ログインしていない人を「ログアウト」させても何も変わらないので、確かめなくても困らない。移った先のログイン画面には、`startAppSession()` が残した印（`timed_out`）で「一定時間操作がなかったため、ログアウトしました。…」が出る（[login.md](./login.md)）。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 状態を変える操作は POST だけ | GET は 405 で断る |
| 405 には `Allow` を付ける | `header('Allow: POST');` |
| CSRF トークンで確かめる | `requireValidCsrfToken()` |
| 確かめる意味が無いときは先に返す | ログイン状態が無ければ、すぐ `redirect('login.php')` |
| 使う部品だけ読み込む | DB を使わないので `dbconnect.php` を読まない |

## 関連するファイル

- ログアウトの中身：[src_auth.md](./src_auth.md)（`logoutUser()`）
- セッション・CSRF：[src_session.md](./src_session.md)
- ログアウトボタン：[templates.md](./templates.md)（`templates/header.php` のフォーム）
- 移る先：[login.md](./login.md)
