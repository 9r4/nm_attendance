# `api/requests.php` を読む（申請の受け口）

## このファイルの役割

申請に関する3つの操作を受け付ける **API**。画面（HTML）は出さず、結果を **JSON** で返す。

| `type` | 操作 | 呼ぶ画面 |
| --- | --- | --- |
| `correction` | 修正・新規登録の申請 | 記録表示（`js/records.js`） |
| `cancel` | 自分の申請中の申請の取り消し | 記録表示（`js/records.js`） |
| `overtime` | 簡単残業申請 | メイン画面（`js/main.js`） |

- 前半の「門番」は `api/attendance.php` とほぼ同じ（[api_attendance.md](./api_attendance.md)）。違いは、後半で `type` によって呼ぶ関数を分けるところ。
- 入力の確認と保存は、すべて `src/requests.php` の `submit…()` 関数に任せる（[src_requests.md](./src_requests.md)）。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭のコメント | 受け付ける値と、返す JSON の形 |
| 読み込み | `functions.php`・`dbconnect.php`・`auth.php`・`work_settings.php`・`requests.php` |
| 門番 | POST か（405）→ ログイン中か（401）→ CSRF（400）→ 社員か（403） |
| 振り分け | `type` ごとに `submitCorrectionRequest()`・`submitCancelRequest()`・`submitOvertimeRequest()` を呼ぶ |
| 最後 | 結果を 200 で返す |

---

## 1. 先頭のコメント：返す JSON の形

```php
// 受け付けるのは POST（csrf_token、type＝correction／overtime／cancel（取り消し）と、それぞれの入力）。結果は JSON
// 入力の誤りは ['ok' => false, 'errors' => [項目 => 文]]、受け付けたら ['ok' => true, 'message' => 文]
```

- このファイルが返す JSON の形は、2つだけ。
  - 受け付けた：`{"ok": true, "message": "申請を受け付けました。…"}`
  - 入力に誤りがある：`{"ok": false, "errors": {"check_in": "出勤時刻を入力してください。", …}}`
- `errors` のキーは、入力欄の名前（`check_in`・`overtime_end` など）か、ウィンドウ全体に出す `form`。画面の JavaScript は、このキーを手がかりに、どの欄の下に文を出すかを決める。

> **ここが重要**：**入力の誤りは、状態コード 200 で返している**。HTTP の 400 は「リクエストの形がおかしい（画面を使っていれば起きない）」ときに使い、「出勤時刻が空欄」のような、ふつうに起きる入力の誤りは 200 ＋ `ok: false` で返す。こうすると JavaScript は「200 なら `ok` を見る」「200 以外なら、予想外のエラーとして扱う」と、2段階で素直に分けられる。

## 2. 門番：POST・ログイン・CSRF

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['message' => '申請は、画面の申請ウィンドウから行ってください。']);
}

$currentUser = currentUser($pdo);
if ($currentUser === null) {
    jsonResponse(401, ['message' => 'ログインしてください。', 'redirect' => appUrl('login.php')]);
}

requireValidCsrfToken();
```

- `api/attendance.php` と同じ並び。POST 以外は 405、ログインしていなければ 401、CSRF トークンが違えば 400。
- ログインの確認を CSRF の確認より先にする理由も同じ（自動ログアウトでセッションが消えると、トークンの確認は必ず失敗するため）。くわしくは [api_attendance.md](./api_attendance.md) 3章・4章。
- `jsonResponse()` は戻り値の型が `never` で、呼んだらそこで終わる。

## 3. 門番：申請できるのは社員・管理者だけ

```php
// 申請できるのは社員・管理者（システム管理者は社員ではない）
if ($currentUser['role'] === ROLE_SYSTEM_ADMIN || $currentUser['joined_on'] === null) {
    jsonResponse(403, ['message' => 'システム管理者は、申請できません。']);
}
```

- `api/attendance.php` は `role` だけを見ていたが、こちらは **`joined_on`（入社日）が `null` でないこと**も確かめている。
- 修正・新規登録の確認（`validateCorrection()`）は、「対象日は入社日から今日まで」を調べるのに `$user['joined_on']` を文字列として使う（関数の引数の型が `string`）。`currentUser()` は、システム管理者の `joined_on` を `null` にしているので（[src_auth.md](./src_auth.md)）、ここで先に断っておけば、後ろの関数は「入社日は必ずある」と考えて書ける。

## 4. `type` で振り分ける

```php
$type = $_POST['type'] ?? '';
if ($type === 'correction') {
    $result = submitCorrectionRequest($pdo, $currentUser, $_POST, time());
    if ($result['ok']) {
        // 記録表示は、画面を読み込み直してバッジを出し、この通知を表示する
        setFlash($result['message']);
    }
} elseif ($type === 'cancel') {
    // 申請の取り消し（D-063）。記録表示は、画面を読み込み直してこの通知を表示する
    $result = submitCancelRequest($pdo, $currentUser, $_POST);
    if ($result['ok']) {
        setFlash($result['message']);
    }
} elseif ($type === 'overtime') {
    $result = submitOvertimeRequest($pdo, $currentUser, $_POST, time(), fetchWorkSettings($pdo)['standard_end']);
} else {
    jsonResponse(400, ['message' => '不正なリクエストです。画面を開き直して、もう一度お試しください。']);
}

jsonResponse(200, $result);
```

- `$type === 'correction'` のように `===` で比べるので、`type` が配列で送られてきても、どれにも一致せず、最後の `else` で 400 になる。`api/attendance.php` のように `is_string()` を別に書かなくても安全。
- 3つの `submit…()` には、`$_POST` を**まるごと**渡している。どの値を取り出すか、どう確かめるかは `src/requests.php` の中で行う（値の取り出しは `inputString()`。[src_requests.md](./src_requests.md) 2章）。
- 3つとも、戻り値は同じ形（`['ok' => true, 'message' => …]` か `['ok' => false, 'errors' => …]`）。だから最後の `jsonResponse(200, $result);` の1行で、どの場合も返せる。
- 簡単残業申請だけ、勤務設定の標準終了時刻（`standard_end`）を渡す。「標準終了時刻より後か」を確かめるため。

### `setFlash()` を使う場合と、使わない場合

`setFlash()` は `src/session.php` の関数で、文をセッションに入れておき、**次に開いた画面で1回だけ**出す（[src_session.md](./src_session.md)）。

- 修正・新規登録と取り消し：記録表示の JavaScript は、`ok: true` を受け取ると**画面を読み込み直す**（カレンダーのバッジを最新にするため）。読み込み直すと JavaScript の中の値は消えるので、通知の文は JSON ではなく、セッションに預けて、読み込み直した `records/index.php` が `takeFlash()` で取り出して表示する。
- 簡単残業申請：メイン画面の JavaScript は読み込み直さず、JSON の `message` をその場で通知（トースト）に出す。だから `setFlash()` は呼ばない。呼ぶと、文がセッションに残ったままになり、あとで記録表示を開いたときに、もう一度出てしまう。

> **ここが重要**：「通知をどう出すか」は、**画面が読み込み直すかどうか**で決まる。読み込み直すなら、サーバーのセッションに預ける。読み込み直さないなら、JSON で返して JavaScript が出す。同じ API の中でも、呼ぶ画面の作りに合わせて使い分けている。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 門番を上から並べる | 405 → 401 → 400 → 403 の順に確かめる |
| 後ろの関数の前提を、先に確かめる | `joined_on === null` を 403 で断る |
| 入力の誤りと、形の誤りを分ける | 入力の誤りは 200 ＋ `ok: false`、形の誤りは 400 |
| 戻り値の形をそろえる | 3つの `submit…()` が同じ形を返すので、最後の1行で返せる |
| `===` で比べる | 知らない `type`（配列を含む）は、すべて `else` の 400 |
| 通知の出し方を画面に合わせる | 読み込み直す画面は `setFlash()`、読み込み直さない画面は JSON の `message` |

## 関連するファイル

- 呼ぶ側：`js/records.js`（修正・新規登録、取り消し。[js_records.md](./js_records.md)）、`js/main.js`（簡単残業申請。[js_main.md](./js_main.md)）
- 処理を任せる先：`src/requests.php` の `submitCorrectionRequest()`・`submitCancelRequest()`・`submitOvertimeRequest()`（[src_requests.md](./src_requests.md)）
- 通知の表示：`records/index.php` の `takeFlash()`（[records_index.md](./records_index.md)）
- ログイン・CSRF・通知：[src_auth.md](./src_auth.md)、[src_session.md](./src_session.md)
- 同じ形の API：`api/attendance.php`（[api_attendance.md](./api_attendance.md)）
