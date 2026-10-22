# 10. 画面の見た目（CSS）と JavaScript

主なファイル：`css/style.css`、`js/*.js`、`templates/header.php`

## 10-1. 読み込んでいるもの

`templates/header.php` の `<head>` で、次の順に読み込む。

| 順 | もの | 役割 |
| --- | --- | --- |
| 1 | `ress`（unpkg） | リセット CSS。ブラウザごとの見た目の違い（余白など）をそろえる |
| 2 | IBM Plex Sans JP（Google Fonts） | 文字のフォント（画面モックの指定） |
| 3 | Material Symbols（Google Fonts） | アイコン（メニュー・閉じる・矢印など） |
| 4 | `css/style.css` | このアプリの見た目のすべて |

Bootstrap などのフレームワークは使わず、`style.css` 1つに全部書いている（技術方針 2-6）。

## 10-2. 色や形は「変数」にまとめる

```css
:root {
    --color-primary: #0B6B61;      /* 主色（緑） */
    --color-danger: #A8481F;       /* 退勤・警告の色 */
    --color-error-bg: #F6DDD3;
    --radius-m: 12px;
    …
}
.button--primary {
    background: var(--color-primary);
}
```

- `:root` に `--名前: 値;` で変数を作り、`var(--名前)` で使う（CSS カスタムプロパティ）。
- 色を変えたいときは、変数を1か所直すだけで、使っているところが全部変わる。値は画面モックから取った。

## 10-3. クラスの名前の付け方

`ブロック__部品--種類` の形にそろえている（BEM という書き方）。

| 例 | 意味 |
| --- | --- |
| `.app-header` | ブロック（画面上部のヘッダー） |
| `.app-header__name` | ヘッダーの中の部品（アプリ名） |
| `.button--primary` | ボタンの種類（主ボタン） |
| `.status-badge--working` | 状態バッジの種類（勤務中） |

名前を見れば、どこの何かが分かる。ほかの場所のスタイルを、うっかり変えてしまうことも減る。

## 10-4. スマホの幅に合わせる（メディアクエリ）

```css
@media (max-width: 767px) {       /* 画面の幅が 767px 以下のときだけ */
    .approval-list__head { display: none; }    /* 表の見出しを隠し… */
    .approval-item { grid-template-columns: minmax(0, 1fr) auto; … }  /* カードの形にする */
}
```

| 幅 | 主に変わること |
| --- | --- |
| 1023px 以下 | 管理の画面：入力のカードと一覧を、横並びから縦並びに |
| 767px 以下 | 承認画面：表 → カード、却下のウィンドウは画面の下から出す |
| 600px 以下 | ヘッダーを詰める、メイン画面の文字を小さく、カレンダーを詰める、管理の画面の入力欄を1列に |
| 480px 以下 | ヘッダーのアプリ名を隠す |

PC 用とスマホ用で HTML を分けず、**同じ HTML を CSS だけで切り替える**（D-052）。

## 10-5. 並べ方：Flexbox と Grid

- **Flexbox**（`display: flex`）：1方向に並べる。ヘッダーの左右、ボタンの横並びなど。
- **Grid**（`display: grid`）：縦横のマス目に並べる。カレンダー（7列）、申請ウィンドウの入力欄（2列）、承認の一覧（6列）、管理の画面（左に入力・右に一覧）など。

```css
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));   /* 同じ幅の2列 */
    gap: 16px;
}
```

`minmax(0, 1fr)` は「0 から、残りの幅を等分」。中身が長くても列がはみ出さない。

## 10-6. 表示・非表示は `hidden` 属性で

```css
[hidden] { display: none !important; }
```

JavaScript では、`element.hidden = true / false` で出したり消したりする。クラスを付け外しするより分かりやすい。`!important` を付けているのは、`display: flex` などを指定したクラスより優先させるため。

## 10-7. JavaScript の書き方の決まり

どの JS ファイルも、次の形になっている。

```js
(() => {
    'use strict';
    const root = document.getElementById('approvals');
    if (!root) {
        return;          // この画面でなければ、何もしない
    }
    …
})();
```

| 書き方 | 理由 |
| --- | --- |
| `(() => { … })();` で全体を囲む | 変数をこの中だけのものにして、ほかの JS とぶつからないようにする |
| `'use strict';` | 書き間違い（宣言していない変数など）をエラーにして、気付きやすくする |
| `<script … defer>` で読み込む | HTML を読み終わってから実行される。`getElementById` で要素が見つかる |
| PHP からの値は `data-*` 属性で受け取る | `element.dataset.csrfToken` のように読める（5-1） |
| 文字は `textContent` で入れる | 中身をいつも文字として扱う（XSS 対策。`innerHTML` に文字をつなげない） |

### サーバーとのやり取り：`fetch()`

```js
const body = new FormData();
body.append('csrf_token', root.dataset.csrfToken);
body.append('action', 'check_in');
const response = await fetch(root.dataset.apiUrl, {
    method: 'POST',
    body,
    credentials: 'same-origin',              // Cookie（セッション ID）を付ける
    headers: { Accept: 'application/json' },
});
if (response.status === 401) { window.location.href = root.dataset.loginUrl; return; }   // ログインが切れた
const data = await response.json();
```

- `await` は「結果が返るまで待つ」。`async` を付けた関数の中で使える。
- 通信できなかったとき（`try … catch`）、サーバーのエラー（`!response.ok`）、ログインが切れたとき（401）を、それぞれ分けて扱う。
- 送信中はボタンを押せなくして、二重に送られないようにする。

## 10-8. ウィンドウ：`<dialog>` 要素

確認のウィンドウや申請のウィンドウは、HTML の `<dialog>` 要素を使っている。

```js
dialog.showModal();    // 開く（後ろを暗くし、ほかの部分を押せなくする）
dialog.close();        // 閉じる
```

- Esc キーで閉じる、開いている間は後ろを押せない、などをブラウザがやってくれる。
- `<form method="dialog">` の中のボタンを押すと、ウィンドウが閉じて、押したボタンの `value` が `dialog.returnValue` に入る（メイン画面の上書きの確認は、これで「上書きする」か「キャンセル」かを知る）。
- 外側（暗い部分）を押したら閉じる処理は、各 JS で `event.target === dialog` を見て行っている。

## 10-9. キャッシュ対策（`assetUrl()`）

CSS・JS の URL に、ファイルを変えた日時を付けている（`style.css?v=1761100000`）。ファイルを変えると URL が変わるので、ブラウザが新しいファイルを読み込む（2-1、D-068）。

## 試してみる

1. 開発者ツールの左上の「スマホの形」のボタン（デバイスツールバー）で、幅を 390px にして、承認画面がカードの形になることを見る。
2. 開発者ツールの「要素」で `:root` を選び、`--color-primary` の値を変えてみる → 画面の緑が全部変わる（読み込み直すと元に戻る）。
