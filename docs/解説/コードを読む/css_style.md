# `css/style.css` を読む（画面の見た目のすべて）

## このファイルの役割

このアプリの見た目を、**1つのファイルにすべて**書いている（約1,600行）。Bootstrap などのフレームワークは使わない（技術方針 2-6）。全画面が `templates/header.php` から、`ress`（リセット CSS）のあとに読み込む。

長いので、1行ずつではなく、**読むときの地図と、押さえておくべき書き方**に絞って解説する。

## 全体の構成

ファイルは、コメント（`/* … */`）の見出しで区切られている。上から：

| 区切り | 中身 |
| --- | --- |
| `:root` | 色・角の丸み・フォントの変数 |
| 共通 | `[hidden]`、余白の初期化、`body`、リンク、フォーカスの枠、アイコンの枠 |
| 部品 | ヘッダー、ボタン、メッセージ（`.alert`）、入力欄（`.form-field`）、カード、通知（`.toast`）、確認のウィンドウ（`.dialog`）、メニュー |
| 画面ごと | ログイン、メイン画面、記録表示・カレンダー、申請ウィンドウ、承認画面、システム管理者の画面 |
| 最後 | 幅ごとの切り替え（`@media`） |

**部品（どの画面でも使う）→ 画面ごと → スマホの幅の上書き** の順に並んでいる。CSS は後に書いたものが優先されるので、この順番に意味がある。

---

## 1. 色や形は変数にする

```css
:root {
    /* 文字・背景 */
    --color-bg: #F5F4EF;
    --color-surface: #FFFFFF;
    --color-text: #1E2422;
    …
    /* 主色（緑）と、退勤・警告の色 */
    --color-primary: #0B6B61;
    --color-primary-hover: #084F48;
    --color-danger: #A8481F;
    …
    --radius-s: 10px;
    --radius-m: 12px;
    --radius-l: 16px;
    --font-family: "IBM Plex Sans JP", "Hiragino Sans", "Yu Gothic", sans-serif;
}
```

- `--名前: 値;` で変数（カスタムプロパティ）を作り、`var(--color-primary)` で使う。
- 値は画面モックから取った。色を1つ変えたいときは、ここを直すだけで全画面が変わる。

> **ここが重要**：色を直接 `#0B6B61` と書かず、**意味の名前**（primary、danger、error-bg …）で使う。「どの色を使うか」ではなく「何のための色か」で書くので、あとから読む人にも意図が分かる。

## 2. 全体に効く決まり

```css
/* hidden の要素は、display を指定したクラス（.button など）があっても表示しない */
[hidden] {
    display: none !important;
}
```

- HTML の `hidden` 属性は、本来は「表示しない」だが、`.button { display: inline-flex; }` のようなクラスがあると、そちらが勝って表示されてしまう。`!important` で、`hidden` を必ず優先させる。
- JavaScript は `element.hidden = true / false` で出し消しするので、この1行が全部の画面の前提になっている。

```css
:focus-visible {
    outline: 3px solid var(--color-primary);
    outline-offset: 2px;
}
```

- キーボード（Tab キー）で選んだときだけ、太い枠を出す（マウスで押したときは出ない）。キーボードで操作する人が、今どこにいるか分かるようにするため。

```css
/* Material Symbols のアイコン
   フォントが読み込めない間・読み込めない場合に、アイコン名の文字（schedule など）がはみ出さないよう、1文字分の枠に収める */
.material-symbols-outlined {
    display: inline-block;
    width: 1em;
    height: 1em;
    overflow: hidden;
    …
}
```

- アイコンは「`schedule` という文字を、アイコンのフォントで表示する」仕組み。フォントが届く前は `schedule` の文字がそのまま見えてしまうので、1文字分の枠に閉じ込めて、はみ出しを隠している。

## 3. クラスの名前：`ブロック__部品--種類`

```css
.button { … }              /* ブロック：ボタンの共通の形 */
.button--primary { … }     /* 種類：主ボタン（緑） */
.button--danger { … }      /* 種類：危ない操作（赤） */
.button--block { … }       /* 種類：横幅いっぱい */

.calendar { … }            /* ブロック：カレンダー */
.calendar__day { … }       /* 部品：1日のマス */
.calendar__day--today { … }/* 部品の種類：今日のマス */
```

- BEM という書き方。HTML では `class="button button--primary"` のように、共通の形と種類を**並べて付ける**。
- 名前に場所が入っているので、`.calendar__day` を直しても、ほかの画面に影響しない。CSS が大きくなっても、どこに効くかが分かる。

## 4. 並べ方：Grid と Flexbox

カレンダー（7列のマス目）：

```css
.calendar__head,
.calendar__week {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 8px;
}
```

- `repeat(7, minmax(0, 1fr))`：同じ幅の列を7つ。`1fr` は「残りの幅を等分した1つ分」。
- `minmax(0, 1fr)` の `0` がポイント。ただの `1fr` だと、中身（長い祝日の名前など）が長いときに、その列だけ広がってしまう。最小を 0 にすると、列の幅がそろったままになる。

1日のマスの中（縦に並べる）：

```css
.calendar__day {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    …
}
```

- **Grid**：縦横のマス目に置く（カレンダー、入力欄の2列、承認の一覧の6列、管理の画面の左右）。
- **Flexbox**：1方向に並べる（ヘッダーの左右、ボタンの横並び、マスの中の縦並び）。

## 5. 状態をクラスで表す

```css
.status-badge--not-started { … }   /* 未出勤（灰） */
.status-badge--working { … }       /* 勤務中（緑） */
.status-badge--on-break { … }      /* 休憩中（黄） */
.status-badge--finished { … }      /* 退勤済み（青灰） */
```

- PHP（`templates/header.php`）と JavaScript（`js/main.js` の `render()`）が、状態の名前（`working` など）からクラス名を作って付ける：`'status-badge--' + 状態.replace('_', '-')`。
- 申請のバッジ（`.request-badge--pending` など）も同じ作り。**状態の名前とクラス名をそろえておく**と、表示を変えるコードが1行で済む。

属性で状態を表すものもある：

```css
.menu__link[aria-current="page"] { … }          /* 今いる画面のメニュー */
.form-field__input[aria-invalid="true"] { … }   /* 入力の誤りがある欄 */
```

- `aria-current`・`aria-invalid` は、画面の読み上げ（スクリーンリーダー）にも意味が伝わる属性。見た目のためだけのクラスを別に作らず、**意味の属性にスタイルを付ける**ので、見た目と意味がずれない。

## 6. 確認のウィンドウ（`<dialog>`）

```css
.dialog {
    width: calc(100% - 32px);
    max-width: 460px;
    margin: auto;
    …
}

.dialog::backdrop {
    background: rgba(30, 36, 34, 0.5);
}
```

- `<dialog>` を `showModal()` で開くと、後ろに `::backdrop`（ウィンドウの後ろ全体）ができる。ここを半透明の黒にして、後ろを暗くしている。
- `width: calc(100% - 32px); max-width: 460px;`：狭い画面では左右に 16px ずつ空けた幅、広い画面では 460px まで。

ハンバーガーメニューも `<dialog>` で、左に寄せて高さいっぱいにしている（`.menu { height: 100%; margin: 0; … }`）。

## 7. スマホの幅（`@media`）

```css
@media (max-width: 767px) {
    .approval-list__head {
        display: none;               /* 表の見出しを隠し… */
    }
    .approval-item {
        grid-template-columns: minmax(0, 1fr) auto;   /* 6列 → 2列のカードに */
        …
    }
    /* 却下のウィンドウは、画面の下から出す */
    .dialog--sheet {
        width: 100%;
        max-width: none;
        margin: auto 0 0;
        border-radius: 20px 20px 0 0;
    }
}
```

| 幅 | 主に変わるもの |
| --- | --- |
| 1023px 以下 | 管理の画面：入力のカードと一覧を縦に並べる |
| 767px 以下 | 承認画面：表 → カード、却下のウィンドウを下から |
| 600px 以下 | ヘッダー・メイン画面・カレンダーを詰める、管理の画面の入力欄を1列に、管理のボタンを1列に |
| 480px 以下 | ヘッダーのアプリ名を隠す |

> **ここが重要**：PC 用とスマホ用で HTML を分けず、**同じ HTML を、幅によって CSS で並べ替える**（D-052）。HTML が1つなので、PHP や JavaScript を2つ用意しなくてよい。`@media` のブロックはファイルの最後のほうにあり、上で決めた形を「狭いときだけ」上書きする。

---

## まとめ：このファイルから学べること

| 書き方 | 例 |
| --- | --- |
| 色・形を意味の名前の変数にする | `--color-primary`、`--radius-m` |
| `[hidden]` を必ず効かせる | `display: none !important` |
| BEM で名前の衝突を防ぐ | `.calendar__day--today` |
| 列の幅をそろえる | `minmax(0, 1fr)` |
| 状態はクラス・意味の属性で表す | `.status-badge--working`、`[aria-invalid="true"]` |
| 1つの HTML を幅で切り替える | `@media (max-width: 767px)` |
| キーボードの操作にも配慮する | `:focus-visible` の太い枠 |

## 関連するファイル

- 読み込む場所：`templates/header.php`（`assetUrl('css/style.css')` で、ファイルを変えると URL が変わる）→ [templates.md](./templates.md)
- 状態のクラスを付ける側：`js/main.js`（`render()`）→ [js_main.md](./js_main.md)
