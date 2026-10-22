# `api/approvals.php` を読む（承認・却下の受け口）

## このファイルの役割

承認画面の「承認」「却下する」のボタンから、JavaScript（`js/approvals.js`）が送ってくる **POST を受けて、結果を JSON で返す**ファイル。

- HTML は出さない。返すのは JSON だけ。
- このファイルがするのは**入口の確認**（POST か・ログイン中か・CSRF トークン・管理者か・値の形）だけ。承認・却下の中身（承認範囲の確認、勤怠への反映、トランザクション）は `src/approvals.php` の `reviewRequest()` に任せる。
- 受け付ける値：`csrf_token`、`request_id`（申請の ID）、`decision`（`approve`＝承認／`reject`＝却下）、却下のときは `comment`（任意）。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭 | 読み込み |
| 確認 1 | POST でなければ 405 |
| 確認 2 | ログインしていなければ 401 |
| 確認 3 | CSRF トークンが正しくなければ 400 |
| 確認 4 | 管理者でなければ 403 |
| 確認 5 | `request_id`・`decision` の形が正しくなければ 400 |
| 処理 | `reviewRequest()` を呼び、成功なら通知を予約して、結果を返す |

**上から順に「だめなら、すぐ返して終わる」**を重ねている（早期リターンと同じ考え方）。最後まで来たときには、すべての確認が済んでいる。

---

## 1. 読み込み

```php
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/approvals.php');
```

- `src/approvals.php` は、さらに `src/requests.php` と `src/queries/attendances.php` を読み込む（`src/requests.php` は、申請の SQL の関数と `src/attendance.php` を読み込む）。下で使う `inputString()` は `src/requests.php` の関数。
- `src/dbconnect.php` は `src/errors.php` も読み込む。これで、想定外の例外が起きたときの扱い（ログに書き、画面には定型の文だけを出す）が決まる（6章）。

## 2. POST でなければ 405

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['message' => '承認・却下は、承認画面のボタンから行ってください。']);
}
```

- `$_SERVER['REQUEST_METHOD']`：送られてきたリクエストの種類（`GET`・`POST` など）。
- 承認・却下は**データを変える操作**なので、POST だけを受け付ける。GET（URL を開くだけ）で処理してしまうと、リンクを踏ませるだけで承認させられる、といった危険がある。
- 405（Method Not Allowed）を返すときは、`Allow: POST` のヘッダーで「使えるのは POST」と伝えるのが HTTP の決まり。
- `jsonResponse()`（`src/functions.php`）：HTTP の状態の番号を決め、配列を JSON にして返し、**そこで処理を終える**（中で `exit`）。中で `exit` するので、`if` の後ろに `else` を書かなくても、下の行は実行されない。戻り値の型 `never` は「この関数は戻ってこない」という印（PHP がその約束を確かめる）で、処理を止めているのは `exit`。

## 3. ログインしていなければ 401

```php
$currentUser = currentUser($pdo);
if ($currentUser === null) {
    jsonResponse(401, ['message' => 'ログインしてください。', 'redirect' => appUrl('login.php')]);
}
```

- 画面（`approvals/index.php`）では `requireLogin()` を使い、ログイン画面へ移していた。でも API は JavaScript から呼ばれるので、移しても意味が無い（JavaScript が受け取るのは、ログイン画面の HTML になってしまう）。
- そこで、画面を移さない `currentUser()` を使い、ログインしていなければ **401 を JSON で返す**。JavaScript は 401 を見て、自分でログイン画面へ移す。
- `currentUser()` は、ログイン中に利用不可にされたアカウントも `null` にする（その場でログアウトさせる）。

> **ここが重要**：ログインの確認を、次の CSRF の確認より**先に**している。無操作で時間切れになると、セッションの中身（ログイン状態も CSRF トークンも）が消える。先に CSRF を調べると「不正なリクエストです」という分かりにくい誤りになるが、この順なら 401 になり、JavaScript がログイン画面へ案内できる。

## 4. CSRF トークンが正しくなければ 400

```php
requireValidCsrfToken();
```

- `src/session.php` の関数。送られてきた `csrf_token` が、セッションに入っている値と同じでなければ、400 と「不正なリクエストです。…」を出して終わる。
- ほかのサイトに仕込まれたフォームから、ログイン中の管理者のブラウザに勝手に POST させる攻撃（CSRF）を防ぐ。ほかのサイトは、この管理者のセッションの CSRF トークンを知り得ないので、正しい値を送れない。
- この関数は `abort()` を使うので、返すのは JSON ではなく、ただの文（`text/plain`）。JavaScript 側は「JSON として読めなかった」と判断し、決まった誤りの文を出す（[js_approvals.md](./js_approvals.md)）。

## 5. 管理者でなければ 403

```php
// 承認できるのは管理者だけ（D-024）
if ($currentUser['role'] !== ROLE_MANAGER) {
    jsonResponse(403, ['message' => '承認・却下は、管理者だけができます。']);
}
```

- 社員（`employee`）もシステム管理者（`system_admin`）も、ここで断る。システム管理者は設定・アカウント管理だけを行い、承認はしない（D-024）。
- 画面では `requireRole()` を使っていたが、あれは 403 をただの文で返す。API では、JavaScript が文を読めるように、`jsonResponse()` で JSON にして返している。

> **ここが重要**：画面（`approvals/index.php`）でも権限を確かめているが、**API でも必ず確かめ直す**。API は URL さえ分かれば、画面を通さずに直接 POST できるからだ。「画面で止めたから大丈夫」とは考えない。

## 6. 値の形を確かめる

```php
$requestId = inputString($_POST, 'request_id');
$decision = inputString($_POST, 'decision');
if (!preg_match('/\A[1-9]\d{0,18}\z/', $requestId) || !in_array($decision, ['approve', 'reject'], true)) {
    jsonResponse(400, ['message' => '不正なリクエストです。画面を開き直して、もう一度お試しください。']);
}
```

- `inputString()`：`$_POST` から値を取り出し、前後の空白を取る。値が無いとき、または**文字列でないとき**（`request_id[]=1` のように配列で送られたときなど）は `''` にする。PHP の `$_POST` は配列も入りうるので、この1枚をかませておくと、下の処理が「必ず文字列」と思って書ける。
- 正規表現 `/\A[1-9]\d{0,18}\z/`：
  - `\A` と `\z` は「文字列の最初」と「最後」。間に書いた形だけで、**全体**ができているかを調べる（`^`・`$` と違い、末尾の改行も許さない）。
  - `[1-9]\d{0,18}`：1〜9 で始まり、そのあと数字が 0〜18 個。つまり「0 で始まらない、19 桁までの正の整数」。`0`・`-1`・`1e3`・`12abc` は通らない。
  - 19 桁までにしているのは、PHP の整数の最大値（9223372036854775807）が 19 桁だから。それより長い数字は、最初から受け付けない（19 桁で最大値を超える数字は、下の `(int)` で最大値になるが、そんな ID の申請は無いので「見つかりません」で終わる）。
- `in_array($decision, ['approve', 'reject'], true)`：3つ目の `true` で、型まで同じかを調べる（厳密な比較）。決めた2つの文字だけを受け付ける。
- どちらかがおかしければ 400。画面から普通に押せば、こうはならない（誰かが値を書き換えたか、画面が壊れている）。

## 7. 承認・却下の処理を呼び、結果を返す

```php
$result = reviewRequest($pdo, $currentUser, (int) $requestId, $decision, inputString($_POST, 'comment'));
if ($result['ok']) {
    // 承認画面は、読み込み直して一覧と通知を出す
    setFlash($result['message']);
}
jsonResponse(200, $result);
```

- `(int) $requestId`：文字列を整数に変える（型変換、キャスト）。6章で数字だけと確かめてあるので、安全に変えられる。`reviewRequest()` の引数は `int $requestId` なので、文字列のままだと渡せない。
- `reviewRequest()`（`src/approvals.php`）が、申請のロック・申請中か・承認範囲か・自分の申請でないかの確認、勤怠への反映、申請の状態の更新を、1つのトランザクションで行う。戻り値は次のどちらか。
  - 成功：`['ok' => true, 'message' => '山田 太郎 さんの申請を承認し、勤怠に反映しました。']`
  - 断られた：`['ok' => false, 'errors' => ['form' => 'この申請は、すでに処理されています。…']]`（コメントが長すぎるときは `'comment'` のキー）
- 成功のときだけ、`setFlash()` で通知をセッションに入れておく。JavaScript は成功を受け取ると画面を読み込み直し、`approvals/index.php` が `takeFlash()` でこの通知を取り出して出す。
- **成功でも、断られた場合でも、HTTP の状態は 200** にしている。

> **ここが重要**：HTTP の状態の番号は、**「リクエストそのものがおかしい」**とき（405・401・400・403）に使い分け、**「正しいリクエストだが、業務のルールで断った」**とき（すでに処理済み・範囲外など）は 200 と `ok: false` で返す。JavaScript は、番号を見れば「画面を読み込み直す・ログインへ移す」などの扱いを決められ、`ok` と `errors` を見れば、利用者に出す文が分かる。申請の受け口（`api/requests.php`）も同じ形にしている。

### 想定外の例外のとき

- このファイルには `try`〜`catch` が無い。`reviewRequest()` の中で DB の障害などの例外が起きると、`reviewRequest()` はトランザクションを元に戻してから、例外を投げ直す。
- 投げ直された例外は、`src/errors.php` で登録した `set_exception_handler()` が受け止める。内容をエラーログに書き、画面には 500 と「エラーが発生しました。…」の文だけを返す（**誤りの詳細は利用者に見せない**）。

## 返す値の一覧

| 場合 | HTTP の状態 | 返す中身 |
| --- | --- | --- |
| POST でない | 405 | JSON（`message`）。`Allow: POST` のヘッダー付き |
| ログインしていない | 401 | JSON（`message`・`redirect`） |
| CSRF トークンが違う | 400 | ただの文（`text/plain`） |
| 管理者でない | 403 | JSON（`message`） |
| `request_id`・`decision` の形が違う | 400 | JSON（`message`） |
| 断られた（処理済み・範囲外・自分の申請・見つからない・コメントが長い） | 200 | JSON（`ok: false`・`errors`） |
| 成功 | 200 | JSON（`ok: true`・`message`）。通知をセッションに予約 |
| 想定外の例外 | 500 | ただの文（`text/plain`） |

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| データを変える操作は POST だけ | GET は 405 と `Allow: POST` |
| 確かめる順番を考える | ログイン（401）を CSRF（400）より先に |
| API でも権限を確かめ直す | 画面とは別に、管理者かを調べる |
| API は JSON で答える | `requireRole()` ではなく `jsonResponse(403, …)` |
| 送られた値は形を決めて確かめる | `\A[1-9]\d{0,18}\z` と `in_array(…, true)` |
| 入口と中身を分ける | 入口の確認はここ、業務のルールは `reviewRequest()` |
| HTTP の番号と `ok` を使い分ける | リクエストの誤りは番号、業務の断りは 200＋`ok: false` |
| 想定外の誤りの詳細は見せない | 例外は `src/errors.php` がログに書いて 500 |

## 関連するファイル

- 呼ぶ側：[js_approvals.md](./js_approvals.md)（承認・却下のボタン）
- 呼ばれる側：[src_approvals.md](./src_approvals.md)（`reviewRequest()`）、[src_requests.md](./src_requests.md)（`inputString()`）、[src_auth.md](./src_auth.md)（`currentUser()`）、[src_session.md](./src_session.md)（`requireValidCsrfToken()`・`setFlash()`）、[src_functions.md](./src_functions.md)（`jsonResponse()`）
- 同じ形の受け口：[api_requests.md](./api_requests.md)
- 通知を出す画面：[approvals_index.md](./approvals_index.md)
- テスト：`docs/検証スクリプト/pr6.js`（GET・CSRF・未ログイン・社員・範囲外の申請などを、画面を通さずに送る確認）
