# `members/index.php`・`members/detail.php` を読む（社員の勤怠の画面）

## このファイルの役割

管理者（`manager`）が、**対象社員の今日の勤怠を一覧で見て**、「詳細」から**1人の月の記録をカレンダーで見る**画面（PR 10。D-070）。管理者だけが使える。メニューでは「承認」の下の「社員の勤怠」。

| ファイル | URL | 役割 |
| --- | --- | --- |
| `members/index.php` | `members/` | 対象社員の一覧と、今日の勤怠（状態・出勤・退勤・休憩・労働時間）、「詳細」のリンク |
| `members/detail.php` | `members/detail.php?id=2025001&month=2026-09` | 1人の月カレンダー。記録表示と同じものを**見るだけ**（申請・修正・取り消しはできない） |

- 対象社員は**承認範囲と同じ**（自分の部署／全社。D-025）。**自分**と**退職した社員**は出さない。
- 2つとも、判断と表示の組み立ては `src/members.php` に、SQL は `src/queries/employees.php` に任せる。このファイルは「集めて並べる」だけ（[approvals_index.md](./approvals_index.md) と同じ形）。
- 詳細のカレンダーは、記録表示の部品 `templates/calendar.php` を**そのまま使い回す**（[templates.md](./templates.md) の4章）。
- 一覧の取得の SQL（`fetchMemberAttendances()`）は**依頼者が書く関数**で、今は枠（処理の流れのコメントだけ）。書き終わるまでは、一覧を開くと例外になり、想定外のエラーの画面になる（[src_errors.md](./src_errors.md)）。

## 全体の構成

| ファイル・行のあたり | 中身 |
| --- | --- |
| `index.php` 先頭（5〜12行） | 読み込み、ログインと権限の確認 |
| `index.php` 14〜21行 | 今日の日付、一覧のデータを集めて、1行ずつ画面用の形にする |
| `index.php` `<main>` | 見出し（今日の日付・対象）、一覧の見出しの行、1人ずつの `<article>` |
| `detail.php` 先頭（5〜12行） | 読み込み、ログインと権限の確認（一覧と同じ） |
| `detail.php` 14〜21行 | URL の `id` を受け取り、見てよい社員かを確かめる（だめなら 404） |
| `detail.php` 23〜31行 | 表示する月を決め、その社員の勤怠・申請を読み、カレンダーを組み立てる |
| `detail.php` `<main>` | 戻るリンクと氏名、カレンダーの部品（`templates/calendar.php`）、`js/records.js` |

画面の流れを先に示す。

```mermaid
flowchart LR
    A["メニューの「社員の勤怠」"] --> B["members/ の一覧"]
    B -- "詳細" --> C["members/detail.php?id=…"]
    C -- "前の月・次の月" --> C
    C -- "日付を押す" --> D["その日の記録と申請（見るだけ）"]
    C -- "＜ 社員の勤怠" --> B
```

---

## `members/index.php`（一覧）

## 1. 読み込みと、ログイン・権限の確認

```php
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/members.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);
```

- 承認画面（`approvals/index.php`）と同じ形。最後の1行だけが、この画面のルールのファイル（`src/members.php`）になる。
- `src/members.php` は、`src/approvals.php`（`approvalDepartmentId()`）・`src/records.php`（カレンダーと労働時間）・`src/queries/employees.php`（SQL）をまとめて読み込む（[src_members.md](./src_members.md)）。
- `requireRole($currentUser, [ROLE_MANAGER])`：管理者でなければ 403 と「この画面を使う権限がありません。」で止める。社員も、システム管理者も、ここで止まる。

> **ここが重要**：メニューの「社員の勤怠」は管理者にしか出ない（`templates/menu.php`）。でも、URL の `members/` を直接打てば開けてしまうので、**画面の先頭で必ず権限を確かめる**。メニューの出し分けは見た目のためだけ（[templates.md](./templates.md) の2章）。

## 2. 今日の日付と、見出しの日付

```php
$now = time();
$today = date('Y-m-d', $now);
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabel = date('Y年n月j日', $now) . '（' . $weekdays[(int) date('w', $now)] . '）';
```

- `time()`：今の日時（1970年からの秒数）。**1回だけ取り、同じ値から日付と見出しを作る**。`date()` を何度も呼ぶと、ちょうど0時をまたいだときに「SQL は 10/26、見出しは 10/27」のように食い違うことがある。
- `date('Y年n月j日', $now)`：`n` は先頭に 0 を付けない月、`j` は先頭に 0 を付けない日 → `2026年10月26日`。`Y`・`n`・`j` 以外の文字（`年`・`月`・`日`）は、そのまま出る。
- `date('w', $now)`：曜日の番号（日曜が `0`）。文字列で返るので、`(int)` で数にしてから配列の番号に使う → `（月）`。

## 3. 一覧のデータを集める

```php
$settings = fetchWorkSettings($pdo);
$rows = fetchMemberAttendances($pdo, $currentUser['employee_id'], approvalDepartmentId($currentUser), $today);
$members = array_map(fn ($row) => memberRowView($row, $today, $settings), $rows);
```

- `fetchWorkSettings()`：勤務設定。労働時間の計算で、昼休憩（`lunch_start`・`lunch_end`）を引くために使う。
- `fetchMemberAttendances()`（`src/queries/employees.php`。**依頼者が書く**）：対象社員と、その日（`$today`）の勤怠を、1人1行で返す。打刻していない社員も入る（勤怠の列が `null`）。中身は [src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md) の6章。
  - 2つ目の引数（管理者の社員 ID）：**自分を除く**ため。
  - 3つ目の引数 `approvalDepartmentId($currentUser)`：承認範囲が「部署」なら管理者の部署 ID、「全社」なら `null`。承認画面の一覧と**同じ関数**で、同じ範囲にしている（[src_approvals.md](./src_approvals.md) の2章）。
- `array_map()` とアロー関数 `fn ($row) => …`：DB の1行ずつを、`memberRowView()` で**画面用の形**（`'statusLabel'`・`'checkIn'`・`'workTime'` など）にする。アロー関数の中では、外側の `$today`・`$settings` をそのまま使える。

> **ここが重要**：状態（勤務中など）や時刻の形（`09:01`・`--:--`）は、メイン画面と**同じ関数**（`attendanceView()`）で作る（`memberRowView()` の中）。画面ごとに作り方を書くと、「メイン画面では休憩中なのに、一覧では勤務中」のような食い違いが起きる。

```php
$pageTitle = '社員の勤怠';
include(__DIR__ . '/../templates/header.php');
```

- タイトルは「社員の勤怠｜勤怠管理」。

## 4. 見出し：今日の日付と、対象

```php
        <p class="members__note"><?= h($dateLabel) ?>　対象：<?= h($currentUser['approval_scope'] === 'all' ? '全社' : $currentUser['department_name']) ?></p>
```

- 「2026年10月26日（月）　対象：営業部」のように出す。どの範囲の社員が並んでいるかを、管理者が分かるようにする。承認画面の「承認範囲：」と同じ作り。

## 5. 一覧の見出しの行と、空のとき

```php
        <div class="member-list__head" aria-hidden="true">
            <div>氏名（社員コード）</div><div>部署</div><div>状態</div><div>出勤</div><div>退勤</div><div>休憩</div><div>労働時間</div><div></div>
        </div>
        <?php if ($members === []): ?>
            <p class="member-list__empty">対象の社員はいません。</p>
        <?php endif; ?>
```

- PC で、表の見出しのように並ぶ行。CSS の grid（8列）で、下の1人ずつの行と列をそろえる。最後の空の `<div>` は「詳細」の列の分。
- `aria-hidden="true"`：読み上げソフトには読ませない（1人ずつの `<article>` に名前を付けているため）。スマホのカード型では、CSS が隠す。
- 対象社員がいないときは、真っ白にせずに文を出す（部署の社員が全員退職した、など）。

## 6. 1人ずつの表示

```php
        <?php foreach ($members as $member): ?>
            <article class="member-item" aria-label="<?= h($member['name'] . 'さんの本日の勤怠') ?>">
                <div class="member-item__person">
                    <div class="member-item__name"><?= h($member['name']) ?></div>
                    <div class="member-item__sub"><?= h($member['employeeId']) ?></div>
                </div>
                <div class="member-item__department"><?= h($member['department']) ?></div>
```

- `$member` は `memberRowView()` が作った配列（キーは `employeeId`・`name`・`department`・`status`・`statusLabel`・`checkIn`・`checkOut`・`break`・`workTime`）。
- `aria-label`：読み上げソフトに「山田 太郎さんの本日の勤怠」と伝える。
- 氏名は**利用者（システム管理者）が入力した文字**なので、`h()` が大事。氏名に `<script>` と書かれても、文字としてそのまま出る（`pr10.js` で確かめている）。

```php
                <div class="member-item__status">
                    <span class="status-badge status-badge--<?= h(str_replace('_', '-', $member['status'])) ?>"><?= h($member['statusLabel']) ?></span>
                </div>
```

- 状態のバッジは、ヘッダーの状態バッジ（`templates/header.php`）と**同じクラス**。`'on_break'` の `_` を `-` にして `status-badge--on-break` にする。色はメイン画面と同じになる。

```php
                <div class="member-item__time"><span class="member-item__label">出勤</span><?= h($member['checkIn']) ?></div>
                <div class="member-item__time"><span class="member-item__label">退勤</span><?= h($member['checkOut']) ?></div>
                <div class="member-item__time"><span class="member-item__label">休憩</span><?= h($member['break']) ?></div>
                <div class="member-item__time"><span class="member-item__label">労働時間</span><?= h($member['workTime']) ?></div>
```

- 時刻の前の「出勤」などの言葉（`member-item__label`）は、CSS で **PC では隠し、スマホでは出す**。PC は見出しの行に書いてあるが、カード型には見出しの行が無いので、何の時刻かを添える（承認画面の「申請 」と同じ考え方）。
- 労働時間は、退勤済みの人だけ「8時間15分」、ほかは `--`（`memberRowView()` が決める）。

```php
                <div class="member-item__actions">
                    <a class="button button--outline" href="<?= h(appUrl('members/detail.php') . '?id=' . rawurlencode($member['employeeId'])) ?>" aria-label="<?= h($member['name'] . 'さんの勤怠の詳細') ?>">詳細</a>
                </div>
```

- 「詳細」は、ボタンの見た目の**リンク**（`<a>`）。押すと詳細の画面に移るだけで、何も変えないので、フォームや JavaScript は要らない。
- `rawurlencode()`：URL の中に入れる値を、URL で使える形にする（空白は `%20` など）。社員コードは英数字だけだが、**URL に値を入れるときは、いつも通しておく**。そのあと全体を `h()` で HTML 用にする（順番は「URL 用 → HTML 用」）。
- `aria-label`：画面に「詳細」がたくさん並ぶので、読み上げでは「山田 太郎さんの勤怠の詳細」と、だれの詳細かを伝える。

---

## `members/detail.php`（詳細）

## 7. URL の `id` を受け取り、見てよい社員かを確かめる

```php
$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);

$memberId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$member = $memberId === '' ? null : fetchEmployeeProfile($pdo, $memberId);
if ($member === null || !canViewMember($currentUser, $member)) {
    abort(404, MEMBER_NOT_VIEWABLE_MESSAGE);
}
```

- 読み込みと権限の確認は、一覧と同じ（管理者でなければ 403）。
- `is_string()`：`?id[]=2025001` のように送られると、`$_GET['id']` は配列になる。文字列のときだけ受け取り、それ以外は `''`（無し）にする（記録表示の `month` と同じ書き方）。
- `fetchEmployeeProfile()`：社員 ID で、氏名・部署名・入社日・部署 ID・**在籍状態**を読む。見つからなければ `null`。ヘッダーの表示にも使っている関数で、PR 10 で在籍状態（`e.employment_status`）の列を足す（**依頼者が書く**。[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md) の2章）。
- `canViewMember()`（`src/members.php`）：この管理者が、この社員を見てよいか。一覧に出す条件（管理者・自分でない・在籍中・承認範囲）と同じ。
- だめなら `abort(404, MEMBER_NOT_VIEWABLE_MESSAGE)`：404 と「この社員の勤怠は表示できません。」で止める。

> **ここが重要**：「詳細」のリンクは一覧に出た社員にしか無いが、URL の `id` は**ブラウザで誰でも書き換えられる**。だから詳細の画面でも、**一覧と同じ条件をもう一度確かめる**（承認の API が `canReview()` で確かめ直すのと同じ）。断るときは、ほかの部署の社員・存在しない社員 ID・自分・退職した社員の**どれも同じ 404 と同じ文**にする。理由を分けて返すと、「この社員 ID は存在する」「この人は退職した」ことが、範囲の外の管理者に分かってしまう。

> **補足**：依頼者が `fetchEmployeeProfile()` に在籍状態の列を足すまでは、`$member` に `employment_status` が無い。`canViewMember()` は「在籍状態が分からないときは見せない」作りなので、その間は**だれの詳細も 404 になる**（見せすぎる側には倒れない。[src_members.md](./src_members.md) の2章）。

```php
if ($member['joined_on'] === null) {
    throw new RuntimeException('入社日を取得できません: ' . $member['employee_id']);
}
```

- 入社日は月の範囲を決めるのに使う。社員なら必ずあるはずなので、無ければデータの誤りとして例外で止める（記録表示の `$currentUser['joined_on']` と同じ考え方）。

## 8. 月を決めて、カレンダーを組み立てる（記録表示と同じ）

```php
$today = date('Y-m-d');
$requested = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
$month = resolveMonth($requested, $today, $member['joined_on']);
$attendances = fetchMonthlyAttendances($pdo, $member['employee_id'], $month['first'], $month['last']);
$requests = fetchMonthlyRequests($pdo, $member['employee_id'], $month['first'], $month['last']);
$calendar = buildCalendar($month, $attendances, fetchWorkSettings($pdo), $today, $requests, $member['joined_on']);
```

- 記録表示（`records/index.php`）の3章と**ほとんど同じ**。違いは、`$currentUser`（自分）の代わりに `$member`（見ている社員）を渡しているところだけ。
- 月の範囲は、**その社員の**入社した月から今月まで（D-061）。範囲の外や、形の正しくない `month` は今月になる（`resolveMonth()`）。
- 勤怠と申請を読む社員 ID は、URL の `$memberId` ではなく、**DB から読んだ `$member['employee_id']`** を使っている。確かめが済んだ値を使う、という書き方。

```php
// 日付を押したときに表示する、その日の記録と申請（見るだけ）
$days = calendarDays($calendar, true);
```

- `calendarDays()`（`src/records.php`）：日付を押したときに JavaScript が見せる、日ごとの値を作る。2つ目の引数を `true`（見るだけ）にすると、**申請できるか（`canRequest`）・申請中の案内（`pendingCorrection`）・取り消せるか（`canCancel`）を、すべて `false` にする**（[src_records.md](./src_records.md) の6章）。

## 9. HTML：戻るリンクと氏名

```php
<main class="records member-detail">
    <div class="member-detail__head">
        <a class="member-detail__back" href="<?= h(appUrl('members/')) ?>"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>社員の勤怠</a>
        <h1 class="member-detail__name"><?= h($member['name']) ?><span class="member-detail__sub">（<?= h($member['employee_id']) ?>・<?= h($member['department_name']) ?>）</span></h1>
    </div>
```

- `class="records member-detail"`：クラスを2つ付けている。`records` で記録表示と同じ幅・余白にし（カレンダーの見た目もそのまま使える）、`member-detail` で見出しの部分だけを足す。
- 「＜ 社員の勤怠」：一覧へ戻るリンク。`chevron_left` は Material Symbols のアイコンの名前。
- 見出し（`<h1>`）は社員の氏名。「山田 太郎（2025001・営業部）」。どの社員の記録を見ているかを、いちばん大きく出す。

## 10. カレンダーの部品を読み込む

```php
    <?php
    $monthQuery = '?id=' . rawurlencode($member['employee_id']) . '&';
    $monthHeading = 'h2';
    $readOnly = true;
    include(__DIR__ . '/../templates/calendar.php');
    ?>
</main>
<script src="<?= h(assetUrl('js/records.js')) ?>" defer></script>
```

- `templates/calendar.php` は、読み込む前に用意された変数を使う部品（[templates.md](./templates.md) の4章）。記録表示は `'?'`・`'h1'`・`false`、この画面は次の値を渡す。

| 変数 | この画面の値 | 意味 |
| --- | --- | --- |
| `$monthQuery` | `'?id=2025001&'` | 月の切り替えのリンクの、`month=` より前の部分。前の月は `?id=2025001&month=2026-09` になり、**見ている社員のまま**月が変わる |
| `$monthHeading` | `'h2'` | 月の見出しのタグ。この画面は氏名が `<h1>` なので、月は `<h2>` にする（1つの画面に `<h1>` は1つ） |
| `$readOnly` | `true` | 見るだけ。「申請」ボタン、申請の API の URL と CSRF トークンを出さず、`#calendar` に `data-readonly="true"` を付ける |

- `$days`・`$month`・`$calendar` も、部品が使う変数。8章で用意してある。
- `js/records.js` は記録表示と同じファイル。`data-readonly="true"` を見て、**日付を押したときの表示だけ**を行う（[js_records.md](./js_records.md) の7章）。
- 申請ウィンドウ（`#request-dialog`）と取り消しの確認（`#cancel-dialog`）は、記録表示の `records/index.php` にだけ書いてあるので、この画面には**そもそも出ない**。

> **ここが重要**：「見るだけ」を、**3か所で重ねて**守っている。① `calendarDays($calendar, true)` で、ボタンを出す値を全部 `false` にする。② 部品が、申請の API の URL・CSRF トークン・「申請」ボタンを出さない。③ `js/records.js` が、申請ウィンドウの動きを用意しない。さらに、申請の API（`api/requests.php`）は今までどおり**自分の分しか受け付けない**ので、もし画面をいじって送っても、ほかの社員の申請はできない。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 画面の先頭で権限を確かめる | 2つの画面とも `requireRole($currentUser, [ROLE_MANAGER])` |
| URL の値は型を確かめてから使う | `is_string($_GET['id'])`、`is_string($_GET['month'])` |
| 一覧に出す条件を、詳細でも確かめ直す | `canViewMember()`。URL の `id` は書き換えられる |
| 断る理由を区別しない | 範囲外・存在しない・自分・退職、どれも同じ 404 と文 |
| 今の日時は1回だけ取る | `$now = time();` から、日付と見出しの両方を作る |
| 表示の組み立ては関数に任せる | `array_map()` と `memberRowView()` |
| 同じ部品を、変数を変えて使い回す | `templates/calendar.php` に `$monthQuery`・`$monthHeading`・`$readOnly` |
| URL に入れる値は `rawurlencode()`、HTML に出すときは `h()` | 「詳細」のリンク、月の切り替えのリンク |
| 1つの HTML を CSS で出し分ける | PC は表、スマホはカード型（D-052） |

## 関連するファイル

- 呼ぶ関数：[src_members.md](./src_members.md)（`canViewMember()`・`memberRowView()`・`MEMBER_NOT_VIEWABLE_MESSAGE`）、[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md)（`fetchMemberAttendances()`・`fetchEmployeeProfile()`）、[src_approvals.md](./src_approvals.md)（`approvalDepartmentId()`）、[src_records.md](./src_records.md)（`resolveMonth()`・`buildCalendar()`・`calendarDays()`）、[src_auth.md](./src_auth.md)（`requireLogin()`・`requireRole()`）、[src_functions.md](./src_functions.md)（`h()`・`appUrl()`・`assetUrl()`・`abort()`）
- カレンダーの部品とメニュー：[templates.md](./templates.md)（4章 `templates/calendar.php`、2章 `templates/menu.php`）
- 日付を押したときの動き：[js_records.md](./js_records.md)
- 同じ形の画面：[records_index.md](./records_index.md)（記録表示）、[approvals_index.md](./approvals_index.md)（承認）
- 見た目：[css_style.md](./css_style.md)（`.members`・`.member-list`・`.member-item`・`.member-detail`、768px 未満のカード型）
- 仕組みの説明：[../13_社員の勤怠.md](../13_社員の勤怠.md)
- テスト：`tests/MembersTest.php`・`tests/MembersDbTest.php`（[tests.md](./tests.md)）、`docs/検証スクリプト/pr10.js`（画面の動き）
