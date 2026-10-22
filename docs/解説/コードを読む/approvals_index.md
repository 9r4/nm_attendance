# `approvals/index.php` を読む（承認画面）

## このファイルの役割

管理者が、**承認範囲の申請を一覧で見て、承認・却下のボタンを押す画面**（`approvals/` の URL で開く）。管理者（`manager`）だけが使える。

- 一覧のデータを集めて、HTML を出す。承認・却下の処理そのものはしない。ボタンを押したあとは `js/approvals.js` が `api/approvals.php` に送る。
- 一覧の取得は `src/queries/attendance_requests.php`、1件の表示の組み立ては `src/approvals.php` の関数に任せる。このファイルは「集めて並べる」だけ。
- PC はリスト型、スマホ（768px 未満）はカード型で見せる（D-052）。**HTML は1つで、切り替えは CSS だけ**で行う。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭（PHP） | 読み込み、ログインと権限の確認、一覧のデータを集める |
| `<main>` の開始 | JavaScript に渡す値（`data-*`）、通知（トースト） |
| 見出しとタブ | 承認範囲の表示、「未処理」「処理済み」のタブと件数 |
| 一覧 | 1件ずつ `<article>` で出す。未処理はボタン、処理済みは結果 |
| 一番下 | 誤りを出す場所、却下のウィンドウ（`<dialog>`）、JavaScript の読み込み |

上の PHP で**値をすべて用意してから**、下の HTML でそれを出すだけ、という順になっている。HTML の中では、DB を読んだり判断したりしない。

---

## 1. 読み込みと、ログイン・権限の確認

```php
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/approvals.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);
```

- `__DIR__` は「このファイルがあるフォルダ」（`approvals/`）。`/../src/…` で1つ上の `src/` を指す。どこから開かれても、正しい場所のファイルを読める。
- 関数を定義するファイルは `include_once` で読む。同じファイルを2回読むと「同じ名前の関数がすでにある」というエラーになるので、`_once` で1回だけにする。
- `src/dbconnect.php` を読むと、DB の接続が `$pdo` に入る。
- `requireLogin()`：ログインしていなければ、ログイン画面へ移して終わる（`redirect()` の中で `exit`）。ログイン中なら、利用者の情報（社員 ID・権限・承認範囲・部署 ID など）を配列で返す。
- `requireRole()`：権限が `ROLE_MANAGER`（管理者）でなければ、403 と「この画面を使う権限がありません。」を出して終わる。社員もシステム管理者も、ここで止まる（承認は管理者だけ。D-024）。

> **ここが重要**：メニューの「承認」は管理者にしか出ない（`templates/menu.php`）。でも、**メニューに出さないだけでは守れない**。URL を直接打てば開けてしまうので、画面の先頭で必ず権限を確かめる。

## 2. 一覧のデータを集める

```php
$tab = ($_GET['tab'] ?? '') === 'done' ? 'done' : 'todo';
```

- URL の `?tab=done` で「処理済み」、それ以外は全部「未処理」。`$_GET['tab']` が無いときは `??` で `''` になる。
- **受け付ける値を2つに決めて、それ以外は既定の値にする**書き方。送られてきた値をそのまま使わないので、おかしな値が来ても困らない。

```php
$standardEnd = fetchWorkSettings($pdo)['standard_end'];
$departmentId = approvalDepartmentId($currentUser);
$pending = fetchPendingRequests($pdo, $currentUser['employee_id'], $departmentId);
$processed = fetchProcessedRequests($pdo, $currentUser['employee_id'], $departmentId, APPROVAL_PROCESSED_LIMIT);
```

- `fetchWorkSettings($pdo)['standard_end']`：関数が返した配列から、そのまま1つの値を取り出せる。標準終了時刻（`'18:00:00'` など）は、残業の申請に「判断の材料」として添えるために使う。
- `approvalDepartmentId()`（`src/approvals.php`）：承認範囲が「部署」なら管理者の部署 ID、「全社」なら `null` を返す。
- 2つの一覧を取得する。どちらも「自分の申請は除く」ために、管理者の社員 ID を渡す。
  - `fetchPendingRequests()`：申請中の申請を、古い順に全部。
  - `fetchProcessedRequests()`：承認・却下された申請を、新しい順に `APPROVAL_PROCESSED_LIMIT`（50）件。
- **今どちらのタブを見ていても、2つとも取得する**。タブに「未処理（3）」「処理済み（12）」のように、両方の件数を出すため。

```php
$items = array_map(fn ($row) => approvalItemView($row, $standardEnd), $tab === 'todo' ? $pending : $processed);
$flash = takeFlash();
```

- `array_map(関数, 配列)`：配列の1つずつに関数を当てて、結果を新しい配列にする。ここでは「DB の行」→「画面に出す形」に変えている。
- `fn ($row) => …` は**アロー関数**（PHP 7.4 以降）。1つの式だけの短い関数を書ける。外側の変数（ここでは `$standardEnd`）を、`use` を書かなくてもそのまま使える。
- 変えるのは、今見ているタブの一覧だけ（三項演算子で選ぶ）。
- `takeFlash()`：承認・却下のあと、API がセッションに入れておいた通知（「○○ さんの申請を承認し、…」）を取り出す。取り出したら消えるので、画面を読み込み直しても2回は出ない。

> **ここが重要**：DB の行（`requested_check_out_at` などの列）を HTML の中で直接いじらず、`approvalItemView()` で**画面用の形**（`'name'`・`'changes'`・`'targetDate'` など）にしてから渡している。日付の形や「変更前 → 申請値」の作り方は `src/approvals.php` と `src/requests.php` にまとまり、ここは並べるだけになる。表示の組み立ては DB を使わないので、テスト（`tests/ApprovalsTest.php`）もしやすい。

```php
$pageTitle = '承認';
include(__DIR__ . '/../templates/header.php');
```

- 共通の上部（`<head>` とヘッダー）を読み込む。`header.php` は、先に用意された `$pageTitle` と `$currentUser` を使う（タイトル「承認｜勤怠管理」、ログイン中の名前、メニュー）。
- テンプレートは HTML を出すファイルなので、`include`（`_once` なし）で読む。

## 3. `<main>` と、JavaScript に渡す値

```php
<main class="approvals" id="approvals"
    data-api-url="<?= h(appUrl('api/approvals.php')) ?>"
    data-login-url="<?= h(appUrl('login.php')) ?>"
    data-csrf-token="<?= h(csrfToken()) ?>">
```

- `<?= … ?>` は `<?php echo …; ?>` の短い書き方。
- `data-` で始まる属性は、HTML に自由な値を持たせる仕組み。JavaScript では `root.dataset.apiUrl` のように読める（`data-api-url` → `apiUrl`。ハイフンの後ろが大文字になる）。
- `appUrl()` は、アプリを置いた場所（`/attendance` など）を先頭に付けた URL を作る。**JavaScript のファイルは置き場所を知らない**ので、PHP が作った URL を渡している。
- `csrfToken()`：セッションに入っている、推測できない文字列。JavaScript は承認・却下を送るときに、これを一緒に送る。API は、送られてきた値とセッションの値が同じかを確かめる（CSRF 対策）。
- 値はすべて `h()` を通す（`htmlspecialchars()`。`"` なども変える）。属性の値の中に `"` が入って、属性が途中で切れることを防ぐ。

```php
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php else: ?>
        <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
    <?php endif; ?>
```

- `if (…):` 〜 `endif;` は、HTML の中で使いやすい**もう1つの if の書き方**（コロン構文）。`{ }` よりも、どこで閉じたかが分かりやすい。
- 通知があれば中身を入れて出し、無ければ `hidden`（隠す）で空の箱だけ置く。通知は 5 秒後に `js/approvals.js` が隠す。
- `role="status"`・`aria-live="polite"`：画面読み上げソフトに、「ここに出た文を読み上げる」と伝える。

## 4. 見出しとタブ

```php
        <p class="approvals__scope">承認範囲：<?= h($currentUser['approval_scope'] === 'all' ? '全社' : $currentUser['department_name']) ?></p>
```

- 承認範囲が「全社（`all`）」なら「全社」、「部署（`department`）」なら自分の部署名を出す（D-025）。どの範囲の申請が並んでいるかを、管理者が分かるようにする。

```php
    <nav class="tabs" aria-label="申請の切り替え">
        <a class="tabs__item" href="?tab=todo"<?= $tab === 'todo' ? ' aria-current="page"' : '' ?>>未処理（<?= h(count($pending)) ?>）</a>
        <a class="tabs__item" href="?tab=done"<?= $tab === 'done' ? ' aria-current="page"' : '' ?>>処理済み（<?= h(count($processed)) ?>）</a>
    </nav>
```

- タブは、ただのリンク。`href="?tab=done"` のように `?` から始めると、今の URL のまま、後ろの部分だけが変わる。押すと画面を読み込み直す（JavaScript を使わない）。
- 選んでいるタブに `aria-current="page"` を付ける。読み上げソフトに「今のページ」と伝え、CSS もこの属性で選んでいるタブの見た目を変える。
- `count()` で件数を数える。2章で両方の一覧を取得したのは、ここで使うため。

```php
    <?php if ($tab === 'done'): ?>
        <p class="approvals__note">新しい順に <?= h(APPROVAL_PROCESSED_LIMIT) ?> 件まで表示します。</p>
    <?php endif; ?>
```

- 処理済みは 50 件までしか出さない（D-064）。それを画面にも書いておく。件数は定数から出すので、50 を変えても文が食い違わない。

## 5. 一覧の見出しと、空のとき

```php
    <div class="approval-list">
        <div class="approval-list__head" aria-hidden="true">
            <div>申請者</div><div>種別</div><div>対象日</div><div>内容（変更前 → 申請値）</div><div>申請日時</div><div><?= $tab === 'todo' ? '操作' : '結果' ?></div>
        </div>
```

- PC のリスト型で、表の見出しのように並ぶ行。CSS の grid（6 列）で、下の1件ずつの行と列をそろえている。
- `aria-hidden="true"`：読み上げソフトには読ませない。1件ずつの `<article>` に「○○さんの修正の申請」という名前（`aria-label`）を付けているので、見出しの行は要らない。
- スマホのカード型では、CSS がこの行を隠す（`display: none`）。

```php
        <?php if ($items === []): ?>
            <p class="approval-list__empty"><?= $tab === 'todo' ? '未処理の申請はありません。' : '処理済みの申請はありません。' ?></p>
        <?php endif; ?>
```

- `$items === []`：配列が空かどうか。何も無いときに、真っ白にせず、文を出す。

## 6. 1件ずつの表示

```php
        <?php foreach ($items as $item): ?>
            <article class="approval-item" aria-label="<?= h($item['name'] . 'さんの' . $item['type'] . 'の申請') ?>">
                <div class="approval-item__applicant">
                    <div class="approval-item__name"><?= h($item['name']) ?></div>
                    <div class="approval-item__department"><?= h($item['department']) ?></div>
                </div>
                <div><span class="approval-item__type"><?= h($item['type']) ?></span></div>
                <div class="approval-item__date"><?= h($item['targetDate']) ?></div>
```

- `foreach (…):` 〜 `endforeach;` も、コロン構文。
- `$item` は `approvalItemView()` が作った配列。`'type'` は「修正」「新規登録」「残業」、`'targetDate'` は「10/14（水）」の形。

```php
                    <ul class="approval-item__changes">
                        <?php foreach ($item['changes'] as $change): ?>
                            <li><?= h($change['label']) ?>　<?= h($change['before']) ?> → <b><?= h($change['after']) ?></b></li>
                        <?php endforeach; ?>
                    </ul>
```

- `'changes'` は、**変わった項目だけ**の一覧（`requestChanges()` が作る）。例：「退勤　17:30 → **18:45**」。申請の値を `<b>` で太くして目立たせる。
- 申請は4つの時刻の全体を持つが（DB設計書 P-4）、画面では変わった項目だけを見せる。

```php
                    <?php if ($item['reference'] !== ''): ?>
                        <p class="approval-item__note"><?= h($item['reference']) ?></p>
                    <?php endif; ?>
                    <?php if ($item['reason'] !== ''): ?>
                        <p class="approval-item__note">理由：<?= h($item['reason']) ?></p>
                    <?php endif; ?>
```

- `'reference'`：残業の申請だけに入る「標準終了 18:00・今の退勤 19:45」。管理者が、残業の時刻が妥当かを判断する材料。
- `'reason'`：社員が書いた理由。空なら行ごと出さない。
- 理由やコメントは**利用者が自由に書いた文**なので、`h()` が特に大事。`<script>` と書かれても、文字としてそのまま出る（XSS 対策）。

```php
                <div class="approval-item__created"><span class="approval-item__label">申請 </span><?= h($item['createdAt']) ?></div>
```

- 「申請 」という言葉は、CSS で PC では隠し、スマホでは出す。PC は見出しの行に「申請日時」と書いてあるが、カード型には見出しの行が無いので、何の日時かを添える。

### 未処理：承認・却下のボタン

```php
                <?php if ($tab === 'todo'): ?>
                    <div class="approval-item__actions">
                        <button class="button button--primary" type="button" data-approve="<?= h($item['id']) ?>">承認</button>
                        <button class="button button--reject" type="button" data-reject="<?= h($item['id']) ?>" data-target="<?= h($item['name'] . ' さん・' . $item['type'] . '（' . $item['targetDate'] . '）') ?>">却下</button>
                    </div>
```

- ボタンに、申請の ID を `data-approve`・`data-reject` で持たせる。JavaScript は、押されたボタンからこの ID を読んで送る。
- `data-target`：却下のウィンドウに出す「山田 太郎 さん・修正（10/14（水））」の文。PHP で作っておけば、JavaScript は入れるだけで済む。
- `type="button"`：ボタンの既定の動き（フォームの送信）をさせない。押したときの動きは、すべて JavaScript で決める。

> **ここが重要**：ボタンに付けた ID は、**ブラウザで誰でも書き換えられる**。だから API は「この ID の申請を、この管理者が処理してよいか」を、送られてくるたびに DB で確かめ直す（`src/approvals.php` の `canReview()`）。一覧に出ているかどうかは、守りにはならない。

### 処理済み：結果

```php
                <?php else: ?>
                    <div class="approval-item__result">
                        <span class="request-badge request-badge--<?= h($item['status']) ?>"><?= h($item['statusLabel']) ?></span>
                        <div class="approval-item__note"><?= h($item['reviewerName']) ?>　<?= h($item['reviewedAt']) ?></div>
                        <?php if ($item['reviewComment'] !== ''): ?>
                            <div class="approval-item__note">コメント：<?= h($item['reviewComment']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
```

- 状態（`approved`／`rejected`）を、そのままクラス名の一部にしている（`request-badge--approved`）。CSS が、状態ごとにバッジの色を変える。記録表示のバッジと同じクラスなので、見た目がそろう。
- 処理した管理者の氏名と日時を出す。同じ範囲を複数の管理者が見るので、「誰が処理したか」が分かるようにする（画面モックの差異 13）。
- `'status'`・`'reviewerName'` などのキーは、処理済みの行のときだけ `approvalItemView()` が作る。だから、ここ（`$tab === 'done'` のとき）でしか読まない。

## 7. 誤りを出す場所と、却下のウィンドウ

```php
    <p class="alert alert--error approvals__error" id="approval-error" role="alert" hidden></p>
```

- 承認が断られたとき（「この申請は、すでに処理されています。」など）に、JavaScript が文を入れて出す。最初は `hidden` で隠しておく。
- `role="alert"`：中身が出たら、読み上げソフトがすぐに読み上げる。

```php
    <dialog class="dialog dialog--sheet" id="reject-dialog" aria-labelledby="reject-title">
        <form class="dialog__body" id="reject-form" novalidate>
            <h2 class="dialog__title" id="reject-title">申請を却下します</h2>
            <p class="dialog__subtitle" id="reject-target"></p>
            <p class="alert alert--error" id="reject-error" role="alert" hidden></p>
            <div class="form-field">
                <label class="form-field__label" for="reject-comment">コメント（任意）</label>
                <textarea class="form-field__input form-field__textarea" id="reject-comment" name="comment" rows="4" maxlength="<?= h(APPROVAL_COMMENT_MAX_LENGTH) ?>"></textarea>
            </div>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" id="reject-cancel">キャンセル</button>
                <button class="button button--danger" type="submit" id="reject-submit">却下する</button>
            </div>
        </form>
    </dialog>
```

- `<dialog>` は、ブラウザに最初からある「ウィンドウ」の部品。JavaScript から `showModal()` で開くと、後ろの画面を押せなくなり、Esc キーでも閉じられる。自分で一から作るより、作りが少なくて済む。
- `aria-labelledby="reject-title"`：ウィンドウの名前は、見出し（`id="reject-title"`）の文だと伝える。
- `novalidate`：ブラウザの入力チェックの吹き出しを使わない。誤りは、サーバーが返した文をウィンドウの中（`reject-error`）に出す。
- `maxlength` に定数 `APPROVAL_COMMENT_MAX_LENGTH`（500）を使う。サーバーの確認と同じ数なので、食い違わない。ただし `maxlength` は入力を助けるだけで、**サーバーでも必ず文字数を確かめる**（`reviewRequest()`）。
- 「キャンセル」は `type="button"`、「却下する」は `type="submit"`。「却下する」を押すと、フォームの `submit` が起き、JavaScript がそれを受けて送る。
- スマホでは、CSS（`.dialog--sheet`）でウィンドウを画面の下から出す。

## 8. JavaScript の読み込み

```php
<script src="<?= h(assetUrl('js/approvals.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
```

- `assetUrl()`：URL の後ろに、ファイルを最後に変えた日時を付ける（`?v=1761100000`）。ファイルを変えると URL が変わるので、ブラウザが古い JavaScript を使い続けない（D-068）。
- `defer`：HTML を全部読み終えてから、JavaScript を実行する。JavaScript が `document.getElementById('approvals')` などで探す部品が、確実にそろっている。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 画面の先頭で権限を確かめる | `requireLogin()` と `requireRole($currentUser, [ROLE_MANAGER])` |
| 受け付ける値を決めておく | `tab` は `done` 以外を全部 `todo` にする |
| 値を全部用意してから HTML を出す | 先頭の PHP で `$items`・`$pending`・`$processed` を作る |
| 表示の組み立ては関数に任せる | `array_map()` と `approvalItemView()` |
| 出す値は必ずエスケープする | 理由・コメント・氏名・属性の値、すべて `h()` |
| JavaScript への値は `data-*` で渡す | API の URL・ログイン画面の URL・CSRF トークン・申請の ID |
| 1つの HTML を CSS で出し分ける | PC はリスト型、スマホはカード型（D-052） |
| ブラウザの部品を使う | 却下のウィンドウは `<dialog>` |

## 関連するファイル

- 呼ぶ関数：[src_approvals.md](./src_approvals.md)（`approvalDepartmentId()`・`approvalItemView()`・定数）、[src_queries_attendance_requests.md](./src_queries_attendance_requests.md)（`fetchPendingRequests()`・`fetchProcessedRequests()`）、[src_auth.md](./src_auth.md)（`requireLogin()`・`requireRole()`）、[src_session.md](./src_session.md)（`csrfToken()`・`takeFlash()`）、[src_functions.md](./src_functions.md)（`h()`・`appUrl()`・`assetUrl()`）
- 画面の動き：[js_approvals.md](./js_approvals.md)
- ボタンの送り先：[api_approvals.md](./api_approvals.md)
- 共通の上部・下部とメニュー：[templates.md](./templates.md)
- 見た目：`css/style.css`（「承認画面」の部分と、768px 未満の切り替え）
- テスト：`tests/ApprovalsTest.php`（表示の組み立て）、`docs/検証スクリプト/pr6.js`（画面の動き）
