# `templates/` と `js/menu.js` を読む（画面の共通の上部・下部、メニュー、月カレンダーの部品）

## このファイルの役割

どの画面にも共通する部分を、1か所にまとめたファイル群。各画面の PHP が `include` で読み込む。

| ファイル | 役割 |
| --- | --- |
| `templates/header.php` | `<head>`（CSS・フォントの読み込み）と、画面上部のヘッダー（氏名・状態バッジ・ログアウトボタン） |
| `templates/menu.php` | ハンバーガーメニュー（ほかの画面への移動）。権限ごとに項目を出し分ける |
| `templates/footer.php` | `</body></html>` を閉じるだけ |
| `templates/calendar.php` | 月カレンダーの部品（月の切り替え・集計・カレンダー・その日の記録のウィンドウ）。記録表示と、社員の勤怠の詳細で使う（PR 10） |
| `js/menu.js` | ハンバーガーメニューを開く・閉じる動き |

- 画面ごとに `<head>` やヘッダーを書くと、直すときに全部の画面を直すことになる。共通の部分を1か所にしておけば、1つ直すだけで全画面に効く。`templates/calendar.php` は全画面ではなく、**2つの画面で同じ HTML を使う**ための部品（4章）。
- 各画面は、次の形で使う。

```php
$pageTitle = 'メイン';
include(__DIR__ . '/templates/header.php');
?>
<main>…この画面の中身…</main>
<?php include(__DIR__ . '/templates/footer.php'); ?>
```

## 全体の構成

| ファイル・行のあたり | 中身 |
| --- | --- |
| `header.php` 1〜8行 | 使う変数の既定値 |
| `header.php` 9〜25行 | `<head>`：タイトル、CSS・フォント、`js/menu.js` |
| `header.php` 27〜57行 | ヘッダー：メニューボタン、アプリ名、氏名・部署・状態バッジ・ログアウト |
| `header.php` 58〜60行 | ログイン中なら、メニューを読み込む |
| `menu.php` 1〜17行 | 権限ごとの項目と、今いる画面 |
| `menu.php` 18〜28行 | メニューの HTML（`<dialog>`） |
| `footer.php` | `</body></html>` |
| `calendar.php` 1〜10行 | 使う変数の説明と、見出しのタグの確かめ |
| `calendar.php` 11〜32行 | 月の切り替え、出勤日数・総労働時間、「申請」ボタン |
| `calendar.php` 34〜88行 | カレンダー本体（`#calendar` と `data-*`、曜日の見出し、週とセル） |
| `calendar.php` 90〜112行 | その日の記録と申請のウィンドウ（`#day-dialog`） |
| `js/menu.js` | 開く・閉じる |

---

## 1. `templates/header.php`

### 使う変数の既定値

```php
<?php
// 画面の共通の上部（<head> と、画面上部のヘッダー）
// 使う前に $pageTitle（画面の名前）を用意しておく。ログイン中の画面では $currentUser（requireLogin() の戻り値）も用意する
// 状態バッジを出す画面（メイン画面）では、$headerStatus（attendanceView() の戻り値）も用意する
$pageTitle = $pageTitle ?? '';
$currentUser = $currentUser ?? null;
$headerStatus = $headerStatus ?? null;
?>
```

- `include` で読み込んだファイルは、**読み込んだ側と同じ変数を使える**。関数の引数のように渡すのではなく、読み込む前に変数を用意しておく、という約束で受け渡す。
- `$x = $x ?? 既定値;`：変数が無い（または `null`）なら、既定値を入れる。読み込む側が用意しなかった変数も、ここで必ず定義されるので、下で「未定義の変数」の警告が出ない。
- 3つの変数の意味：

| 変数 | 用意する画面 | 無いとき |
| --- | --- | --- |
| `$pageTitle` | すべての画面 | タイトルは「勤怠管理」だけ |
| `$currentUser` | ログイン中の画面（`requireLogin()` の戻り値） | 氏名・ログアウト・メニューを出さない（ログイン画面） |
| `$headerStatus` | メイン画面だけ（`attendanceView()` の戻り値） | 状態バッジを出さない |

### `<head>`

```php
<title><?= h($pageTitle === '' ? '勤怠管理' : $pageTitle . '｜勤怠管理') ?></title>
<link rel="stylesheet" href="https://unpkg.com/ress@5.0.2/dist/ress.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+JP:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=block">
<link rel="stylesheet" href="<?= h(assetUrl('css/style.css')) ?>">
<?php if ($currentUser !== null): ?>
    <script src="<?= h(assetUrl('js/menu.js')) ?>" defer></script>
<?php endif; ?>
```

- タイトル：`$pageTitle` が `'メイン'` なら「メイン｜勤怠管理」。三項演算子で、空のときは「勤怠管理」だけにする。
- `ress`：ブラウザごとに違う標準の見た目（余白など）をそろえる、リセット CSS。版（`5.0.2`）まで URL に書いて固定している。
- フォントは IBM Plex Sans JP、アイコンは Material Symbols（どちらも Google Fonts。D-038）。`preconnect` は、フォントのサーバーへの接続を先に始めておく指定で、表示が少し速くなる。
- 自分の CSS（`css/style.css`）は、最後に読み込む。後から読み込んだ CSS が優先されるので、リセット CSS の上に自分の見た目を重ねられる。URL は `assetUrl()` で作り、ファイルを変えたらブラウザが新しいものを読むようにしている（D-068。[src_functions.md](./src_functions.md)）。
- `js/menu.js` は、ログイン中（メニューがある画面）だけ読み込む。`defer` で、HTML を読み終えてから実行する。

### ヘッダー：メニューボタンとアプリ名

```php
<header class="app-header">
    <div class="app-header__left">
        <?php if ($currentUser !== null): ?>
            <button class="app-header__menu-button" type="button" id="menu-open" aria-label="メニューを開く" aria-controls="menu" aria-expanded="false">
                <span class="material-symbols-outlined" aria-hidden="true">menu</span>
            </button>
        <?php endif; ?>
        <a class="app-header__brand" href="<?= h(appUrl()) ?>">
            <span class="material-symbols-outlined app-header__icon" aria-hidden="true">schedule</span>
            <span class="app-header__name">勤怠管理</span>
        </a>
    </div>
```

- Material Symbols のアイコンは、`<span class="material-symbols-outlined">menu</span>` のように、**アイコンの名前を文字で書く**と、フォントがその形（≡）に置き換えて表示する。
- `aria-hidden="true"`：画面の読み上げソフトに、アイコンの文字（`menu`）を読ませない。ボタンの意味は `aria-label="メニューを開く"` で伝える。
- `aria-controls="menu"`：このボタンが `id="menu"` の要素を開け閉めすることを伝える。`aria-expanded`：今、開いているか（`js/menu.js` が `true`／`false` を切り替える）。
- アプリ名のリンクは `appUrl()`（メイン画面）へ。

### ヘッダー：氏名・状態バッジ・ログアウト

```php
<?php if ($currentUser !== null): ?>
    <div class="app-header__user">
        <div class="app-header__profile">
            <div class="app-header__user-name"><?= h($currentUser['name']) ?></div>
            <?php if ($currentUser['department_name'] !== ''): ?>
                <div class="app-header__department"><?= h($currentUser['department_name']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($headerStatus !== null): ?>
            <span class="status-badge status-badge--<?= h(str_replace('_', '-', $headerStatus['status'])) ?>" id="status-badge"><?= h($headerStatus['statusLabel']) ?></span>
        <?php endif; ?>
        <form method="post" action="<?= h(appUrl('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button class="button button--outline" type="submit">ログアウト</button>
        </form>
    </div>
<?php endif; ?>
</header>
```

- 部署名は、空でないときだけ出す。システム管理者は `currentUser()` が部署名を `''` にしているので、氏名（「システム管理者」）だけが出る（[src_auth.md](./src_auth.md)）。
- 状態バッジ：`$headerStatus['status']`（`'not_started'` など）の `_` を `-` にして、クラス名（`status-badge--not-started`）にする。CSS が、状態ごとに色を変える。`id="status-badge"` は、ボタンを押したあとに `js/main.js` が書き換える目印。
- ログアウトは**リンクではなく、POST のフォーム**。CSRF トークンを付けて `logout.php` に送る（[logout.md](./logout.md)）。

> **ここが重要**：ログアウトをリンク（`<a href="logout.php">`）にすると、GET で状態を変えることになり、ほかのサイトから勝手にログアウトさせられる。見た目はボタン1つでも、中身は「CSRF トークン付きの POST」にしている。全画面のヘッダーにこのフォームがあるので、`csrfToken()` もここで呼ばれ、セッションにトークンが用意される。

### メニューを読み込む

```php
<?php if ($currentUser !== null): ?>
    <?php include(__DIR__ . '/menu.php'); ?>
<?php endif; ?>
```

- ログイン中だけ、メニューを読み込む。`menu.php` も、同じ変数（`$currentUser`）をそのまま使う。
- `header.php` は `<body>` を開いたまま終わる。各画面の中身（`<main>`）がその後ろに続き、`</body>` は `footer.php` が閉じる。

## 2. `templates/menu.php`

### 権限ごとの項目

```php
$menuItems = [['path' => '', 'label' => 'メイン画面']];
if ($currentUser['role'] !== ROLE_SYSTEM_ADMIN) {
    $menuItems[] = ['path' => 'records/', 'label' => '記録表示'];
}
if ($currentUser['role'] === ROLE_MANAGER) {
    $menuItems[] = ['path' => 'approvals/', 'label' => '承認'];
    $menuItems[] = ['path' => 'members/', 'label' => '社員の勤怠'];
}
if ($currentUser['role'] === ROLE_SYSTEM_ADMIN) {
    array_push($menuItems, ...ADMIN_MENU_ITEMS);
}
```

- まず「メイン画面」だけの配列を作り、権限に合わせて項目を足していく。`$配列[] = 値;` は、配列の最後に1つ足す書き方。
- 足した順にメニューに並ぶ。「社員の勤怠」（PR 10。D-070）は、「承認」の次に足しているので、「承認」の下に出る。

| 権限 | 出る項目 |
| --- | --- |
| 社員 | メイン画面・記録表示 |
| 管理者 | メイン画面・記録表示・承認・社員の勤怠 |
| システム管理者 | メイン画面・アカウント管理・部署・勤務設定 |

- `array_push($menuItems, ...ADMIN_MENU_ITEMS)`：`...` は**展開**（スプレッド構文）。配列 `ADMIN_MENU_ITEMS` の3つの要素を、1つずつの引数にして渡す。`array_push($menuItems, 要素1, 要素2, 要素3)` と同じになり、3つの項目が最後に足される。`...` を付けないと、配列まるごとが1つの項目として入ってしまう。
- システム管理者の項目は、`src/auth.php` の定数 `ADMIN_MENU_ITEMS` を使う。メイン画面のボタン（`index.php`）も同じ定数を使うので、2つが食い違わない。

> **ここが重要**：メニューに出さないのは**見た目のためだけ**。社員が承認の画面（`approvals/`）や社員の勤怠（`members/`）の URL を直接打っても、その画面の PHP が `requireRole()` で 403 にする（[src_auth.md](./src_auth.md)）。守りはそれぞれの画面にある。

### 今いる画面に印を付ける

```php
// 今いる画面の項目に印を付ける（index.php は、フォルダの URL と同じ扱い）
$currentPath = preg_replace('#index\.php$#', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
```

内側から順に読む。

1. `$_SERVER['REQUEST_URI']`：開いている URL の、ドメインより後ろ（例：`/attendance/records/index.php?month=2026-10`）。
2. `parse_url(…, PHP_URL_PATH)`：`?` より後ろ（クエリ）を除いた、パスの部分だけを取り出す（`/attendance/records/index.php`）。取れないときは `?? ''` で空文字にする。
3. `preg_replace('#index\.php$#', '', …)`：最後の `index.php` を取る（`/attendance/records/`）。正規表現の `#…#` は区切りの記号、`\.` は「`.` という文字」、`$` は「最後」。`/attendance/records/` と `/attendance/records/index.php` は同じ画面なので、同じ形にそろえてから比べる。

```php
<dialog class="menu" id="menu" aria-label="メニュー">
    <nav class="menu__body">
        <div class="menu__head">
            <span class="menu__title">メニュー</span>
            <button class="menu__close" type="button" id="menu-close" aria-label="メニューを閉じる"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
        </div>
        <?php foreach ($menuItems as $item): ?>
            <a class="menu__link" href="<?= h(appUrl($item['path'])) ?>"<?= appUrl($item['path']) === $currentPath ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</dialog>
```

- メニューは `<dialog>`（HTML の標準のウィンドウ）で作る。開くと後ろの画面は押せなくなる。
- 項目の URL（`appUrl($item['path'])`）が、今の画面のパスと同じなら、`aria-current="page"`（「今いる画面」の印）を付ける。CSS はこの属性を見て、項目を目立たせる。読み上げソフトも「現在のページ」と伝える。

## 3. `templates/footer.php`

```php
<?php
// 画面の共通の下部
?>
</body>

</html>
```

- `header.php` が開いた `<body>` と `<html>` を閉じるだけ。今は中身が少ないが、全画面に共通の下部（著作権の表示など）を足すときは、ここに書けば全画面に出る。

## 4. `templates/calendar.php`（月カレンダーの部品）

記録表示（`records/index.php`）のカレンダーの HTML を、PR 10 で部品にしたもの。**記録表示**と、**社員の勤怠の詳細**（`members/detail.php`）の2つの画面が読み込む。日付を押したときの動きは、どちらの画面も `js/records.js`。

### 使う変数と、見出しのタグ

```php
<?php
// 月カレンダー（記録表示と、社員の勤怠の詳細で使う。D-070）：月の切り替え・出勤日数と総労働時間・カレンダー・その日の記録と申請のウィンドウ
// 日付を押したときの動きは js/records.js。読み込む前に、次を用意しておく
// $month：resolveMonth() の戻り値。$calendar：buildCalendar() の戻り値。$days：calendarDays() の戻り値
// $monthQuery：月の切り替えのリンクの、month= より前の部分（記録表示は '?'、詳細は '?id=2025001&'）
// $monthHeading：月の見出しのタグ（画面の一番大きい見出しなら 'h1'、ほかに h1 がある画面は 'h2'）
// $readOnly：見るだけなら true（「申請」ボタンと、申請の API の URL・CSRF トークンを出さない）
// $readOnly が false のときは、$requestMin・$requestMax（「申請」ボタンで選べる日の範囲）も用意する
$monthHeading = $monthHeading === 'h2' ? 'h2' : 'h1';
?>
```

- `header.php` と同じく、**読み込む前に変数を用意しておく**約束で受け渡す。どの変数が要るかを、先頭のコメントに全部書いている。読み込む側は、ここを見れば何を用意すればよいか分かる。

| 変数 | 記録表示 | 社員の勤怠の詳細 |
| --- | --- | --- |
| `$month`・`$calendar`・`$days` | 自分の月の値 | 見ている社員の月の値 |
| `$monthQuery` | `'?'` | `'?id=2025001&'` |
| `$monthHeading` | `'h1'` | `'h2'`（氏名が `<h1>` のため） |
| `$readOnly` | `false` | `true` |
| `$requestMin`・`$requestMax` | 用意する | 用意しない（使わない） |

- `$monthHeading = $monthHeading === 'h2' ? 'h2' : 'h1';`：受け取った値が `'h2'` ならそのまま、**それ以外は全部 `'h1'`** にする。下で、この値をタグの名前として `h()` を通さずに HTML に出すので、**出してよい2つの値だけに決めてから使う**（ホワイトリスト）。

### 月の切り替えと集計

```php
            <?php if ($month['prev'] !== null): ?>
                <a class="month-nav__button" href="<?= h($monthQuery . 'month=' . $month['prev']) ?>" aria-label="前の月"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></a>
            <?php else: ?>
                <span class="month-nav__button month-nav__button--disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_left</span></span>
            <?php endif; ?>
            <<?= $monthHeading ?> class="month-nav__label"><?= h($month['label']) ?></<?= $monthHeading ?>>
```

- 前の月に行けるときはリンク（`<a>`）、行けないとき（入社した月）は、押せない見た目の `<span>` を出す。`next` も同じで、今月より先には行けない。
- リンクの先は `$monthQuery . 'month=' . $month['prev']`。記録表示では `?month=2026-08`、詳細では `?id=2025001&month=2026-08`。`?` から始まる URL は、「今のページのまま、後ろの `?…` だけ変える」という意味になる。詳細では社員 ID も一緒に付けるので、**見ている社員のまま**月が変わる。
- URL 全体を `h()` に通す。`&` は HTML では `&amp;` に変わるが、ブラウザが属性を読むときに `&` に戻すので、リンクは正しく動く。
- `<<?= $monthHeading ?> class=…>`：最初の `<` は HTML のタグの始まり、そのあとの `<?= … ?>` が `h1` か `h2` を出す → `<h1 class="month-nav__label">`。閉じるほうも同じで `</h1>` になる。
- `chevron_left` は、Google のアイコンフォント（Material Symbols）の名前。`aria-hidden="true"` で読み上げから外し、代わりに `aria-label="前の月"` を読ませる。

```php
            <span class="summary__item">出勤日数 <b id="summary-days"><?= h($calendar['workDays']) ?>日</b></span>
            <span class="summary__item">総労働時間 <b id="summary-total"><?= h(formatMinutes($calendar['totalMinutes'])) ?></b></span>
```

- 出勤日数と総労働時間（表示中の月の合計。D-049）。`formatMinutes()` で、分を `160時間30分` の形にする。数え方は [src_records.md](./src_records.md) で説明する。

```php
        <?php if (!$readOnly && $requestMin <= $requestMax): ?>
            <button class="button button--primary records__request" type="button" id="request-open">申請</button>
        <?php endif; ?>
```

- 日付を選ばずに開く「申請」ボタン（D-052）。見るだけのときと、選べる日が1日も無いとき（例：入社日がまだ来ていない）は出さない。
- `&&` は左から順に調べ、左が `false` ならそこで止まる（右を調べない）。見るだけの画面は `$requestMin`・`$requestMax` を用意しないが、`!$readOnly` が先に `false` になるので、右の `$requestMin` は読まれず、「未定義の変数」の警告も出ない。**順番に意味がある**。

### カレンダー本体と、JavaScript に渡す値

```php
    <?php if ($readOnly): ?>
        <div class="calendar" id="calendar" data-readonly="true"
            data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <?php else: ?>
        <div class="calendar" id="calendar"
            data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
            data-api-url="<?= h(appUrl('api/requests.php')) ?>"
            data-login-url="<?= h(appUrl('login.php')) ?>"
            data-csrf-token="<?= h(csrfToken()) ?>">
    <?php endif; ?>
```

- `<div class="calendar">` の**開始タグだけ**を2通りに書き分けている。中身と閉じる `</div>` は、どちらの場合も同じものを使う。
- `data-…` 属性は、HTML に好きな値を持たせる仕組み。JavaScript からは `calendar.dataset.days`・`calendar.dataset.apiUrl` のように読める（`data-api-url` は `apiUrl` という名前になる）。
- `json_encode()`：PHP の配列を JSON の文字列にする。
  - `JSON_UNESCAPED_UNICODE`：日本語を `日` のような記号の形（エスケープ）にせず、`日` のまま出す（HTML が読みやすく、小さくなる）。
  - `JSON_THROW_ON_ERROR`：変換に失敗したら例外にする。これが無いと、失敗したときに `false` が返り、空の属性になって、JavaScript 側で原因の分からないエラーになる。
- JSON を `h()` に通してから属性に入れている。JSON には `"` が入るので、そのままだと属性が途中で切れてしまう。`h()` で `&quot;` に変えておくと、ブラウザが属性を読むときに元の `"` に戻すので、`dataset.days` では元の JSON がそのまま取り出せる。
- 見るだけでないとき（記録表示）：
  - `data-csrf-token`：申請を送るときに付ける CSRF トークン（推測できない文字列）。サーバーは、トークンが正しくない POST を受け付けない。別のサイトから勝手に申請を送らせる攻撃（CSRF）を防ぐ。
  - API とログイン画面の URL も `appUrl()` で作って渡す。アプリを置いた場所（`/attendance/` など）が変わっても、JavaScript を直さなくてよい。
- 見るだけのとき（社員の勤怠の詳細）：`data-readonly="true"` を付け、**申請の API の URL と CSRF トークンを出さない**。この画面からは申請を送らないので、送るための材料を最初から渡さない。`js/records.js` は `data-readonly` を見て、日付を押したときの表示だけを行う（[js_records.md](./js_records.md) の2章・7章）。

> **ここが重要**：PHP から JavaScript に値を渡すとき、`<script>` の中に `var days = <?= json_encode(...) ?>;` と直接書く方法もあるが、ここでは **`data-` 属性 ＋ `h()`** にしている。属性に入れる値は `h()` だけで安全にできるので、エスケープの考え方が「画面に出す値は `h()`」の1つで済む。JavaScript を別ファイル（`js/records.js`）に分けたままにできる良さもある。

```php
        <div class="calendar__head" aria-hidden="true">
            <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $weekdayName): ?>
                <div class="calendar__weekday calendar__weekday--<?= h($i) ?>"><?= h($weekdayName) ?></div>
            <?php endforeach; ?>
        </div>
```

- 曜日の見出し。`as $i => $weekdayName` で、番号（0〜6）と名前の両方を受け取り、番号をクラス名（`calendar__weekday--0` など）に使う。

```php
        <?php foreach ($calendar['weeks'] as $week): ?>
            <div class="calendar__week">
                <?php foreach ($week as $cell): ?>
                    <?php if ($cell === null): ?>
                        <div class="calendar__blank"></div>
                    <?php else: ?>
```

- 週ごと・セルごとの二重の `foreach`。月の外の日（`null`）は空の箱にする。セルの並べ方は `buildCalendar()` が決めているので、ここは順に出すだけ。

```php
                        <?php
                        $classes = ['calendar__day'];
                        if ($cell['isRestDay']) {
                            $classes[] = 'calendar__day--rest';
                        }
                        if ($cell['isToday']) {
                            $classes[] = 'calendar__day--today';
                        }
                        $dateClass = ($cell['weekday'] === 0 || $cell['holiday'] !== '') ? ' calendar__date--sunday' : ($cell['weekday'] === 6 ? ' calendar__date--saturday' : '');
                        $ariaLabel = $cell['label'] . ($cell['holiday'] !== '' ? ' ' . $cell['holiday'] : '') . ($cell['badge'] !== null ? ' ' . $cell['badgeLabel'] : '') . 'の記録';
                        ?>
```

- クラス名を配列に集めて、あとで `implode(' ', $classes)` で空白区切りにする。条件が増えても、`$classes[] = …` を足すだけでよい。
- 休日（土日・祝日）は `--rest`、今日は `--today` を付ける。
- 日付の数字の色：日曜と祝日は赤（`--sunday`）、土曜は青（`--saturday`）。
- 三項演算子（`条件 ? A : B`）を2つ重ねている。**PHP 8 では、三項演算子を重ねるときに `( )` が必須**（付けないとエラー）。どちらを先に判断するかが、読む人にもはっきりする。
- `$ariaLabel`：画面読み上げ用の名前。例えば `9月21日（月） 敬老の日 申請中の記録`。見た目では色や小さな文字で伝えていることを、言葉でも伝える。

```php
                        <button class="<?= h(implode(' ', $classes)) ?>" type="button" data-date="<?= h($cell['date']) ?>" aria-label="<?= h($ariaLabel) ?>"<?= $cell['isToday'] ? ' aria-current="date"' : '' ?>>
```

- 1日を1つの `<button>` にする。押せる部品をボタンにしておくと、キーボード（Tab と Enter）でも操作できる。見るだけの画面でも、日付を押せばその日の記録が見られるので、同じくボタン。
- `data-date="2026-09-01"`：どの日が押されたかを、JavaScript がこの属性で知る。`$days` のキーと同じ形。
- `aria-current="date"`：今日のセルであることを伝える。固定の文字列なので `h()` は通していない。

```php
                            <?php if ($cell['hasRecord']): ?>
                                <span class="calendar__times">
                                    <span><span class="calendar__mark">出</span> <?= h($cell['checkIn']) ?></span>
                                    <span><span class="calendar__mark">退</span> <?= h($cell['checkOut']) ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($cell['badge'] !== null): ?>
                                <span class="request-badge request-badge--<?= h($cell['badge']) ?>"><?= h($cell['badgeLabel']) ?></span>
                            <?php endif; ?>
```

- 記録がある日は、出勤・退勤の時刻を出す。無い時刻は `--:--`（`formatClock()` が作る）。
- 申請がある日は、状態のバッジ（申請中・承認済み・却下）を出す。`badge` は `pending` などで、クラス名（`request-badge--pending`）で色を変える。

### その日の記録と申請のウィンドウ

```php
    <dialog class="dialog" id="day-dialog" aria-labelledby="day-title">
        <form method="dialog" class="dialog__body">
            <h2 class="dialog__title" id="day-title"></h2>
            …
            <p class="alert alert--info" id="day-pending" hidden>申請中のため、新しい申請はできません。新しく申請する場合は、申請中の申請を取り消してください。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="submit" value="close">閉じる</button>
                <button class="button button--primary" type="button" id="day-request" hidden></button>
            </div>
        </form>
    </dialog>
```

- `<dialog>` は、HTML に標準で用意されたウィンドウ。JavaScript の `showModal()` で開くと、後ろの画面は押せなくなり、Esc キーで閉じられる。
- `aria-labelledby="day-title"`：ウィンドウの名前は、`id="day-title"` の見出しだと伝える。
- **`<form method="dialog">`**：この中の送信ボタン（`type="submit"`）を押すと、サーバーには何も送らずに、ウィンドウが閉じる。「閉じる」ボタンには JavaScript が要らない。
- 見出しや時刻の欄（`day-check-in` など）は空で置いておき、日付を押したときに JavaScript が中身を入れる。
- 申請中の案内（`day-pending`）と「修正」「申請する」のボタン（`day-request`）は、最初は `hidden`。出すかどうかは、JavaScript が `$days` の値（`pendingCorrection`・`canRequest`）で決める。見るだけの画面では、`calendarDays($calendar, true)` がどちらも `false` にしているので、出ることはない。
- 申請ウィンドウ（`#request-dialog`）と取り消しの確認（`#cancel-dialog`）は、この部品には入れていない。記録表示の `records/index.php` にだけ書いてある（[records_index.md](./records_index.md) の8章）。

> **ここが重要**：この部品は、PR 10 で `records/index.php` から**中身を変えずに**移した。部品にするときは、まず「今と1文字も変わらない HTML が出る」ように移し、違いは変数（`$monthQuery`・`$monthHeading`・`$readOnly`）だけで出す。そうすれば、前の PR のブラウザの確認（記録表示の `pr4.js`・`pr5.js` など）を流し直すだけで、「記録表示が壊れていない」ことを確かめられる。

## 5. `js/menu.js`

```js
// ハンバーガーメニュー：「≡」で開き、×・Esc・外側を押すと閉じる
(() => {
    'use strict';

    const menu = document.getElementById('menu');
    const openButton = document.getElementById('menu-open');
    if (!menu || !openButton) {
        return;
    }
```

- `(() => { … })();`：関数を作って、その場で1回だけ実行する書き方（即時実行関数）。中で作った変数（`menu` など）は、この関数の中だけで使える。ほかの JavaScript（`js/main.js` など）の変数と、名前がぶつからない。
- `'use strict';`：厳しいモード。宣言していない変数に代入するなどの誤りを、エラーにしてくれる。
- `const`：あとから入れ直さない変数。
- メニューやボタンが無ければ（`null`）、何もせずに終わる（早期リターン）。

```js
    openButton.addEventListener('click', () => {
        menu.showModal();
        openButton.setAttribute('aria-expanded', 'true');
    });
    document.getElementById('menu-close').addEventListener('click', () => menu.close());
```

- `addEventListener('click', 関数)`：押されたときに動く関数を登録する。`() => { … }` はアロー関数（短い書き方の関数）。
- `showModal()`：`<dialog>` を、手前に出すウィンドウとして開く。開いている間、後ろの画面は押せない。
- ×ボタンで `close()`。

```js
    menu.addEventListener('click', (event) => {
        if (event.target === menu) {
            menu.close();
        }
    });
```

- メニューの外側（暗くなった部分）を押したら閉じる。なお、`<dialog>` は画面の高さいっぱいだが、中の `<nav>` はリンクの分の高さしかない。そのため、リンクの下の白い空いた所を押しても `event.target` は `<dialog>` になり、メニューが閉じる。
- `event.target`：実際に押された要素。`showModal()` で開いた `<dialog>` の外側（背景）を押すと、`event.target` は `<dialog>` そのものになる。メニューの中身（`<nav>` やリンク）を押したときは、`event.target` はその中身の要素になるので、閉じない。

```js
    menu.addEventListener('close', () => {
        openButton.setAttribute('aria-expanded', 'false');
    });
})();
```

- `close` イベント：`<dialog>` が閉じたときに起きる。×ボタン・外側・**Esc キー**の、どの閉じ方でも起きる。Esc キーで閉じるのは `<dialog>` の標準の動きなので、自分で書かなくてよい。
- 閉じ方ごとに `aria-expanded` を戻すのではなく、`close` イベント1か所で戻す。閉じ方が増えても、戻し忘れが起きない。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 共通の部分は1か所に | `<head>`・ヘッダー・メニュー・下部を `templates/` に |
| `include` の変数には既定値 | `$pageTitle = $pageTitle ?? '';` |
| 状態を変える操作は POST | ログアウトは CSRF トークン付きのフォーム |
| 一覧の定数を使い回す | `ADMIN_MENU_ITEMS` をメニューとメイン画面で |
| メニューの出し分けは見た目だけ | 守りは各画面の `requireRole()` |
| 配列の展開 | `array_push($menuItems, ...ADMIN_MENU_ITEMS)` |
| HTML の標準の部品を使う | `<dialog>` の `showModal()`・Esc・`close` イベント |
| 読み上げにも配慮する | `aria-label`・`aria-expanded`・`aria-current`・`aria-hidden` |
| 2つの画面で同じ HTML は部品に | `templates/calendar.php` を記録表示と社員の勤怠の詳細で |
| タグの名前に使う値は、決めた値だけ | `$monthHeading` は `'h2'` 以外を全部 `'h1'` に |
| `&&` の順番で、無い変数を読まない | `!$readOnly && $requestMin <= $requestMax` |
| JS の変数を閉じ込める | 即時実行関数と `'use strict'` |

## 関連するファイル

- 読み込む側：[index.md](./index.md)、[login.md](./login.md)、[records_index.md](./records_index.md)、[approvals_index.md](./approvals_index.md)、[members.md](./members.md)、[admin_accounts.md](./admin_accounts.md)、[admin_departments_settings.md](./admin_departments_settings.md)。`templates/calendar.php` を読み込むのは [records_index.md](./records_index.md) と [members.md](./members.md)（`members/detail.php`）
- カレンダーの値を作る関数：[src_records.md](./src_records.md)（`resolveMonth()`・`buildCalendar()`・`calendarDays()`）。日付を押したときの動き：[js_records.md](./js_records.md)
- `$currentUser` を作る関数・権限の定数：[src_auth.md](./src_auth.md)（`requireLogin()`・`ROLE_*`・`ADMIN_MENU_ITEMS`）
- `$headerStatus` を作る関数：[src_attendance.md](./src_attendance.md)（`attendanceView()`）
- CSRF トークン：[src_session.md](./src_session.md)（`csrfToken()`）
- URL の関数：[src_functions.md](./src_functions.md)（`h()`・`appUrl()`・`assetUrl()`）
- 状態バッジを書き換える JS：[js_main.md](./js_main.md)（`render()`）
- メニューの動きの確認：`docs/検証スクリプト/pr4.js`（開く・×・Esc・外側で閉じる）、`pr10.js`（管理者のメニューの「社員の勤怠」）
