# `templates/` と `js/menu.js` を読む（画面の共通の上部・下部とメニュー）

## このファイルの役割

どの画面にも共通する部分を、1か所にまとめたファイル群。各画面の PHP が `include` で読み込む。

| ファイル | 役割 |
| --- | --- |
| `templates/header.php` | `<head>`（CSS・フォントの読み込み）と、画面上部のヘッダー（氏名・状態バッジ・ログアウトボタン） |
| `templates/menu.php` | ハンバーガーメニュー（ほかの画面への移動）。権限ごとに項目を出し分ける |
| `templates/footer.php` | `</body></html>` を閉じるだけ |
| `js/menu.js` | ハンバーガーメニューを開く・閉じる動き |

- 画面ごとに `<head>` やヘッダーを書くと、直すときに全部の画面を直すことになる。共通の部分を1か所にしておけば、1つ直すだけで全画面に効く。
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
| `menu.php` 1〜15行 | 権限ごとの項目と、今いる画面 |
| `menu.php` 16〜27行 | メニューの HTML（`<dialog>`） |
| `footer.php` | `</body></html>` |
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
}
if ($currentUser['role'] === ROLE_SYSTEM_ADMIN) {
    array_push($menuItems, ...ADMIN_MENU_ITEMS);
}
```

- まず「メイン画面」だけの配列を作り、権限に合わせて項目を足していく。`$配列[] = 値;` は、配列の最後に1つ足す書き方。

| 権限 | 出る項目 |
| --- | --- |
| 社員 | メイン画面・記録表示 |
| 管理者 | メイン画面・記録表示・承認 |
| システム管理者 | メイン画面・アカウント管理・部署・勤務設定 |

- `array_push($menuItems, ...ADMIN_MENU_ITEMS)`：`...` は**展開**（スプレッド構文）。配列 `ADMIN_MENU_ITEMS` の3つの要素を、1つずつの引数にして渡す。`array_push($menuItems, 要素1, 要素2, 要素3)` と同じになり、3つの項目が最後に足される。`...` を付けないと、配列まるごとが1つの項目として入ってしまう。
- システム管理者の項目は、`src/auth.php` の定数 `ADMIN_MENU_ITEMS` を使う。メイン画面のボタン（`index.php`）も同じ定数を使うので、2つが食い違わない。

> **ここが重要**：メニューに出さないのは**見た目のためだけ**。社員が承認の画面の URL を直接打っても、承認の画面の PHP が `requireRole()` で 403 にする（[src_auth.md](./src_auth.md)）。守りはそれぞれの画面にある。

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

## 4. `js/menu.js`

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

- メニューの外側（暗くなった部分）を押したら閉じる。
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
| JS の変数を閉じ込める | 即時実行関数と `'use strict'` |

## 関連するファイル

- 読み込む側：[index.md](./index.md)、[login.md](./login.md)、[records_index.md](./records_index.md)、[approvals_index.md](./approvals_index.md)、[admin_accounts.md](./admin_accounts.md)、[admin_departments_settings.md](./admin_departments_settings.md)
- `$currentUser` を作る関数・権限の定数：[src_auth.md](./src_auth.md)（`requireLogin()`・`ROLE_*`・`ADMIN_MENU_ITEMS`）
- `$headerStatus` を作る関数：[src_attendance.md](./src_attendance.md)（`attendanceView()`）
- CSRF トークン：[src_session.md](./src_session.md)（`csrfToken()`）
- URL の関数：[src_functions.md](./src_functions.md)（`h()`・`appUrl()`・`assetUrl()`）
- 状態バッジを書き換える JS：[js_main.md](./js_main.md)（`render()`）
- メニューの動きの確認：`docs/検証スクリプト/pr4.js`（開く・×・Esc・外側で閉じる）
