# `admin/departments.php`・`admin/settings.php` を読む（部署と勤務設定の画面）

## このファイルの役割

システム管理者が使う、残り2つの画面。どちらも、アカウント管理（`admin/accounts.php`）と**同じ形**で作ってある。

- `admin/departments.php`：部署を追加し、一覧から名前の変更・無効化・有効に戻すを行う。
- `admin/settings.php`：全社共通の勤務時間（標準始業・終業時刻と、昼休憩）を変える。

同じところ（先に [admin_accounts.md](./admin_accounts.md) を読んでおくとよい）：

- 最初に `requireLogin()`・`requireRole($currentUser, [ROLE_SYSTEM_ADMIN])` で、システム管理者だけに絞る。
- POST のときは、まず `requireValidCsrfToken()`。処理は `src/admin.php` の関数に任せ、`['ok' => …]` を受け取るだけ。
- 成功したら `setFlash()` → `redirect()` で同じ画面に戻る（PRG パターン）。失敗したらリダイレクトせず、同じリクエストで画面を作り直す。
- 出力は `<?= h(…) ?>` でエスケープし、`js/admin.js` を `defer` で読み込む。

この文書では、**アカウント管理と違うところ**を中心に読む。

## 全体の構成

| | アカウント管理 | 部署 | 勤務設定 |
| --- | --- | --- | --- |
| 操作の数 | 4つ（作成・無効化・有効に戻す・再設定） | 4つ（追加・名前の変更・無効化・有効に戻す） | 1つ（保存） |
| `action` と `match` | あり | あり | なし |
| 入力の誤り | `$errors`（項目ごと） | `$error`（文1つ） | `$errors`（項目ごと） |
| 入力欄の初期値 | 空（入社日は今日） | 空 | DB の今の値 |
| ウィンドウ | 3つ | 3つ | なし |
| 開き直しの印 | `data-reopen-employee` | `data-reopen-department` | なし |

---

## 第1部　`admin/departments.php`（部署）

## 1. 変数の準備：誤りは文1つ

```php
$name = '';
$error = null;
// ウィンドウからの送信で誤りがあったときに、開き直すウィンドウ（['id' => …, 'departmentId' => …, 'error' => …]）
$reopen = null;
```

- 追加のフォームは、部署名の欄が1つだけ。だから、アカウント管理の `$form`（配列）と `$errors`（配列）の代わりに、`$name`（文字列）と `$error`（文か `null`）で足りる。
- `src/admin.php` の `validateDepartmentName()` も、誤りを「文か `null`」で返す。画面と処理で、誤りの形がそろっている。

## 2. POST の受付

```php
    $action = inputString($_POST, 'action');
    $departmentId = inputString($_POST, 'department_id');
    $result = match ($action) {
        'add' => addDepartment($pdo, inputString($_POST, 'name')),
        'rename' => renameDepartment($pdo, $departmentId, inputString($_POST, 'name')),
        'deactivate' => setDepartmentActive($pdo, $departmentId, false),
        'reactivate' => setDepartmentActive($pdo, $departmentId, true),
        default => abort(400, '不正なリクエストです。画面を開き直して、もう一度お試しください。'),
    };
```

- 無効化と有効に戻すは、同じ関数 `setDepartmentActive()` を、3つ目の引数（`false`／`true`）だけ変えて呼ぶ。
- 部署の ID（`department_id`）は、文字列のまま関数に渡す。1 以上の整数か、本当にある部署かは、関数の中で確かめる（[src_admin.md](./src_admin.md) の 13章）。

```php
    if ($action === 'add') {
        $name = inputString($_POST, 'name');
        $error = $result['error'];
    } else {
        $dialogIds = ['rename' => 'rename-dialog', 'deactivate' => 'department-deactivate-dialog', 'reactivate' => 'department-reactivate-dialog'];
        $reopen = ['id' => $dialogIds[$action], 'departmentId' => $departmentId, 'error' => $result['error']];
    }
```

- アカウント作成は、確かめた値（`$result['values']`）を返してくれたが、`addDepartment()` は値を返さない。だから、入力欄に戻す部署名は、`$_POST` から取り出し直している。
- ウィンドウの `id` は `department-deactivate-dialog` のように、アカウント管理（`deactivate-dialog`）と違う名前にしている。どちらの画面も同じ `js/admin.js` を使うので、名前を分けておくと、どの画面のウィンドウかが分かりやすい。

## 3. 一覧を読む：無効の部署も出す

```php
$departments = fetchDepartments($pdo);
```

- アカウント管理では `activeDepartments(fetchDepartments($pdo))` と、有効な部署だけに絞った。部署の画面では、**無効の部署も含めて全部**を出す（無効の部署を「有効に戻す」ためには、一覧に出ている必要がある）。
- `fetchDepartments()` は、部署ごとに**在籍している社員の数**（`active_count`）も数えて返す（`LEFT JOIN` と `GROUP BY`・`COUNT`。[src_queries_departments_sequences_work_settings.md](./src_queries_departments_sequences_work_settings.md)）。退職した社員は数えない。

## 4. 追加のフォーム

```php
                <input class="form-field__input" type="text" id="department_name" name="name" value="<?= h($name) ?>" maxlength="<?= h(ADMIN_DEPARTMENT_NAME_MAX_LENGTH) ?>" autocomplete="off" required<?= $error !== null ? ' aria-invalid="true"' : '' ?>>
```

- 欄が1つなので、アカウント管理のアロー関数 `$invalid` は使わず、その場で三項演算子を書いている。
- `maxlength` は、サーバーの確認と同じ定数 `ADMIN_DEPARTMENT_NAME_MAX_LENGTH`（50）。

```php
            <p class="card__note">無効にした部署は、アカウント作成の選択欄に出なくなります。所属している社員は、そのままです。部署は削除できません。</p>
```

- 部署は削除しない。社員の行が、外部キー（`fk_employees_department`）で部署を参照しているため。使わなくなった部署は、在籍している社員がいても無効にできる（D-067）。

## 5. 一覧の表

```php
                            <?php foreach ($departments as $department): ?>
                                <?php $active = (int) $department['is_active'] === 1; ?>
                                <tr class="<?= $active ? '' : 'admin-table__inactive' ?>" data-department="<?= h($department['id']) ?>">
                                    <td class="admin-table__name"><?= h($department['name']) ?></td>
                                    <td class="admin-table__number"><?= h($department['active_count']) ?> 人</td>
                                    <td><?= $active ? '有効' : '無効' ?></td>
```

- アカウント管理では、表示用の形を `accountView()` で作ってから HTML に渡した。部署は列が少ないので、DB の行（`$department`）をそのまま使い、`$active` だけをループの中で作っている。
- `<?= $active ? '有効' : '無効' ?>` には `h()` が無い。出すのが、プログラムに書いた決まった文字だけなので、エスケープしなくても安全。**DB や利用者から来た値（部署名など）は、必ず `h()` を通す**。

### ボタンに持たせる値

```php
                                            <button class="button button--outline button--small" type="button" data-open="rename-dialog" data-department-id="<?= h($department['id']) ?>" data-target="<?= h($department['name']) ?>" data-name="<?= h($department['name']) ?>">名前の変更</button>
                                            <?php if ($active): ?>
                                                <button class="button button--reject button--small" type="button" data-open="department-deactivate-dialog" data-department-id="<?= h($department['id']) ?>" data-target="<?= h($department['name']) ?>" data-note="<?= (int) $department['active_count'] > 0 ? h('在籍している社員 ' . $department['active_count'] . ' 人は、この部署のままです。') : '' ?>">無効化</button>
```

アカウント管理と同じく、ウィンドウに入れる値をボタンの `data-*` に持たせる。部署の画面で増えたのは、次の2つ。

- `data-name`：名前の変更のウィンドウで、**新しい部署名の欄に、今の名前を最初から入れておく**ための値。ウィンドウの欄には `data-value="name"` が付いていて、`js/admin.js` の `openDialog()` が、この値を欄に入れる。利用者は、今の名前を少し直すだけで済む。
- `data-note`：無効化のウィンドウに添える注意の文。在籍している社員がいるときだけ「在籍している社員 3 人は、この部署のままです。」を入れ、いないときは空にする。文を作るのは PHP で、`h()` でエスケープしてから属性に書く。

## 6. ウィンドウ

```php
            <p class="dialog__text">アカウント作成の部署の選択欄に出なくなります。<span data-text="note"></span></p>
```

- 無効化のウィンドウの説明の文の後ろに、`data-text="note"` の `<span>` を置いている。`openDialog()` が、押したボタンの `data-note` の値を、`textContent` で入れる。在籍者がいない部署なら空のままで、何も出ない。
- このように、**同じ `openDialog()` のまま、HTML に印を足すだけで、ウィンドウごとの違いを出せる**（[js_admin.md](./js_admin.md) の 4章）。

```php
            <input type="hidden" name="department_id" data-value="departmentId">
```

- 対象の部署の ID は、ボタンの `data-department-id` から、この隠し項目に入る。開き直しのときは、`<main>` の `data-reopen-department` と、ボタンの `data-department-id` を比べて、同じ部署のボタンを探す。

```php
<main class="admin" id="admin"<?php if ($reopen !== null): ?> data-reopen="<?= h($reopen['id']) ?>" data-reopen-department="<?= h($reopen['departmentId']) ?>" data-reopen-error="<?= h($reopen['error']) ?>"<?php endif; ?>>
```

- アカウント管理の `data-reopen-employee` の代わりに、`data-reopen-department` を付ける。`js/admin.js` は、どちらが付いているかで、アカウントの画面か部署の画面かを見分ける（[js_admin.md](./js_admin.md) の 7章）。

> **ここが重要**：部署の名前の変更や無効化は、部署を参照しているほかの画面（ヘッダーの部署名、承認画面、アカウント管理の一覧と選択欄）にも、すぐに反映される。社員の行に部署名を書き写さず、**部署の ID だけを持ち、表示のたびに `JOIN` で名前を引いている**ため。名前を1か所で変えれば、全部が変わる。

---

## 第2部　`admin/settings.php`（勤務設定）

## 7. 入力欄の初期値は、DB の今の値

```php
$settings = fetchWorkSettings($pdo);
$form = [];
foreach (array_keys(WORK_SETTING_FIELDS) as $field) {
    $form[$field] = formatTime($settings[$field]);
}
$errors = [];
```

- アカウント作成や部署の追加は、新しく作るフォームなので、入力欄は空から始まった。勤務設定は**今の値を変える**フォームなので、DB の今の値を入れておく。
- `array_keys(WORK_SETTING_FIELDS)` で、4つの項目の名前（`'standard_start'` など）を取り出し、`foreach` で同じ処理をする。
- DB の `TIME` の列は `'09:00:00'`（秒つき）。`formatTime()` で `'09:00'` にする。`<input type="time">` の値は、`'HH:MM'` の形で渡すため。

## 8. POST の受付：操作は1つだけ

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $result = saveWorkSettings($pdo, $_POST);
    if ($result['ok']) {
        setFlash($result['message']);
        redirect('admin/settings.php');
    }
    $errors = $result['errors'];
    foreach (array_keys(WORK_SETTING_FIELDS) as $field) {
        $form[$field] = inputString($_POST, $field);
    }
}
```

- 操作は「保存」の1つだけ。だから、隠し項目 `action` も、`match` も無い。
- 誤りのときは、入力欄の値を、送られてきた値で上書きする。DB の値に戻してしまうと、利用者が入れた値が消え、どこを直せばよいか分からなくなる。
- 送られてきた値は、`saveWorkSettings()` の戻り値からではなく、`$_POST` から取り出し直している。確かめる関数 `validateWorkSettings()` が返す値は「形が正しい項目だけ」なので、形の誤った値（画面に戻したい値）は入っていないため。

## 9. 表示の準備

```php
$flash = takeFlash();
$firstError = $errors === [] ? null : reset($errors);
$updatedAt = isset($settings['updated_at']) ? date('Y/m/d H:i', strtotime($settings['updated_at'])) : null;
```

- `reset($errors)` で、最初の誤りを1つだけ出す（アカウント管理と同じ）。形の誤りは、`WORK_SETTING_FIELDS` の順（始業 → 昼休憩の開始 → 昼休憩の終了 → 終業）に見つかる。
- `updated_at`（最終更新の日時）は、DB が自動で入れる列（`ON UPDATE CURRENT_TIMESTAMP`）。アプリは書き込まず、読んで `'2026/10/22 10:00'` の形にして出すだけ。
- `isset()` で、値があるときだけ作る。無いときは `null` にして、画面に「最終更新」の行を出さない。

> MySQL の `ON UPDATE CURRENT_TIMESTAMP` は、**値が実際に変わったとき**だけ日時を更新する。同じ時刻のまま「保存」を押すと、通知は出るが、最終更新の日時は変わらない。

## 10. フォーム

```php
        <form class="admin__fields" method="post" action="<?= h(appUrl('admin/settings.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="form-grid">
                <?php foreach (['standard_start', 'standard_end', 'lunch_start', 'lunch_end'] as $field): ?>
                    <div class="form-field">
                        <label class="form-field__label" for="<?= h($field) ?>"><?= h(WORK_SETTING_FIELDS[$field]) ?></label>
                        <input class="form-field__input" type="time" id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h($form[$field]) ?>" step="60" required<?= isset($errors[$field]) ? ' aria-invalid="true"' : '' ?>>
                    </div>
                <?php endforeach; ?>
            </div>
```

- 4つの欄を、`foreach` で同じ HTML から作る。ラベルの文字は `WORK_SETTING_FIELDS[$field]` から引くので、サーバーの誤りの文（「標準始業時刻を入力してください。」）と、画面のラベルの名前がそろう。
- **並べる順は、`WORK_SETTING_FIELDS` の順とは違う**。`form-grid` は2列の並びなので、「始業・終業」を1行目、「昼休憩の開始・終了」を2行目に並べるため、ここでは順番を直接書いている（スマホの幅では1列になる）。確かめる順（`WORK_SETTING_FIELDS`）は、時刻の前後の順のまま。
- `type="time"`：時刻を選ぶ欄。送られる値は `'HH:MM'`。`step="60"` は「60 秒きざみ」で、秒の入力を出さない。
- `novalidate` で、ブラウザの確認を止め、誤りはすべてサーバーの文で出す（アカウント作成と同じ）。

```php
            <p class="alert alert--info">変更は、すぐに全社員に反映されます。過去の日も含めて、記録表示の労働時間は新しい昼休憩で計算し直されます。簡単残業申請・承認画面の標準終了時刻も、新しい値になります。</p>
```

- 勤務設定は全社共通の1行で、変えた日の履歴を持たない（D-019、D-067）。記録表示などは、表示のたびに今の設定を読んで計算するので、過去の日の労働時間も変わる。そのことを、保存する前に知らせている。

## 11. ウィンドウが無い画面でも `js/admin.js` を読む

```php
<main class="admin admin--narrow" id="admin">
…
<script src="<?= h(assetUrl('js/admin.js')) ?>" defer></script>
```

- 勤務設定にはウィンドウも一覧も無いが、`js/admin.js` を読み込む。保存したあとの通知を5秒で消すことと、二重送信の防止を、ほかの画面と同じように動かすため。
- `js/admin.js` は「要素が無ければ何もしない」形で書かれているので、ウィンドウや権限の欄が無くても、エラーにならない（[js_admin.md](./js_admin.md)）。
- `<main>` に `data-reopen` は付かない（開き直すウィンドウが無い）。`admin--narrow` は、カードが1つだけなので、幅を狭くする CSS のクラス。

---

## まとめ：この2つのファイルから学べること

| 書き方 | この2つのファイルでの例 |
| --- | --- |
| 同じ形で画面を作る | 権限・CSRF・PRG・誤りの出し方を、3つの画面でそろえる |
| 入力の数に合わせて、誤りの形を選ぶ | 部署は `$error`（文1つ）、勤務設定は `$errors`（項目ごと） |
| 変えるフォームは、今の値から始める | 勤務設定の初期値は DB の値（`formatTime()` で `'HH:MM'` に） |
| 誤りのときは、入力した値を戻す | 部署名・勤務設定の値を `$_POST` から取り出し直す |
| 決まった文字以外は必ずエスケープ | `'有効'`・`'無効'` はそのまま、部署名・注意の文は `h()` |
| HTML の印を足して、JS を直さずに違いを出す | `data-name` → `data-value="name"`、`data-note` → `data-text="note"` |
| 名前は1か所に持ち、表示のたびに引く | 部署名の変更が、ヘッダー・承認画面にもすぐ反映される |
| 項目の名前を定数から引く | 勤務設定のラベルと誤りの文が `WORK_SETTING_FIELDS` でそろう |

## 関連するファイル

- 同じ形の画面：`admin/accounts.php`（[admin_accounts.md](./admin_accounts.md)）
- 処理と確認：`src/admin.php`（[src_admin.md](./src_admin.md) の 13章・14章）
- 画面の動き：`js/admin.js`（[js_admin.md](./js_admin.md)）
- SQL：`src/queries/departments.php`・`src/queries/work_settings.php`（[src_queries_departments_sequences_work_settings.md](./src_queries_departments_sequences_work_settings.md)）
- `formatTime()`・`h()`・`redirect()`：`src/functions.php`（[src_functions.md](./src_functions.md)）
- 勤務設定を使う側：記録表示（`records/index.php`・`src/records.php`）、メイン画面（`index.php`・`api/attendance.php`）、申請（`api/requests.php`）、承認画面（`approvals/index.php`）
- 決まりごと：[決定事項.md](../../決定事項.md)（D-019、D-067）
