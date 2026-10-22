# `src/requests.php` を読む（申請の入力の確認・保存・表示）

## このファイルの役割

申請（修正・新規登録、簡単残業申請）について、**「入力が正しいかを確かめる」「保存する」「画面に出す形にする」**をまとめたファイル。

- 呼ぶ側は、API（`api/requests.php`・`api/attendance.php`）、画面（`index.php`）、記録表示のまとめ役（`src/records.php`）、承認のまとめ役（`src/approvals.php`）。
- HTML は出さない。SQL も書かない（SQL は `src/queries/attendance_requests.php` の関数を呼ぶ）。
- 先頭のコメントにあるとおり、**入力のルールは、すべてサーバー（このファイル）で確かめる**。記録表示や残業申請のウィンドウでの確認は、使いやすさのためだけ。
- 入力の確認をする関数（`validate…()`）は、DB を使わず「配列を受け取って、誤りの一覧を返すだけ」になっている。そのため DB が無くてもテストできる（`tests/RequestsTest.php`）。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭 | 読み込みと定数（時刻の項目、状態の名前、理由の長さ、重複のときの文） |
| 小さな関数 | `inputString()`・`parseRequestTime()`・`isValidDate()`・`currentRequestTimes()` |
| 入力を確かめる関数 | `validateCorrection()`（修正・新規登録）・`validateOvertime()`（簡単残業申請） |
| 保存する関数 | `isDuplicateError()`・`submitCorrectionRequest()`・`submitOvertimeRequest()` |
| 表示を作る関数 | `requestTypeLabel()`・`requestChanges()`・`requestView()` |
| 申請中かを調べる関数 | `requestBadgeStatus()`・`hasPendingCorrection()`・`hasPendingOvertime()`・`pendingStatesOf()` |
| 取り消す関数 | `submitCancelRequest()` |

[src_attendance.md](./src_attendance.md) と同じく、**下の関数ほど上の関数を組み合わせて使う**。上から読むと、部品 → 組み立て の順に理解できる。

---

## 1. 読み込みと定数

```php
include_once(__DIR__ . '/queries/attendance_requests.php');
include_once(__DIR__ . '/attendance.php');
```

- `attendance.php` を読むのは、`fetchAttendance()`（その日の勤怠）、`formatClock()`（`'H:i'` にする）、`workDateOf()`（今日の日付）、`overtimeAvailable()`（残業申請を押せるか）を使うため。出退勤の側で作った部品を、申請の側でも使い回している。

```php
// 修正・新規登録で申請する時刻の項目と、画面に出す名前（順番は、時刻の前後の順）
const REQUEST_TIME_FIELDS = [
    'check_in_at' => '出勤',
    'break_start_at' => '休憩開始',
    'break_end_at' => '休憩終了',
    'check_out_at' => '退勤',
];
```

- キーは DB の列の名前、値は画面に出す名前。
- **並び順に意味がある**。「出勤 ＜ 休憩開始 ＜ 休憩終了 ＜ 退勤」という時刻の前後の順に並べてある。後で出てくる「変更がない」の比べ方（4章の最後）は、この順番がそろっていることを前提にしている。

```php
const REQUEST_STATUS_LABELS = [
    'pending' => '申請中',
    'approved' => '承認済み',
    'rejected' => '却下',
];

const REQUEST_REASON_MAX_LENGTH = 500;
```

- `REQUEST_REASON_MAX_LENGTH` は、理由の最大の文字数。DB の `reason` 列（`VARCHAR(500)`）と同じ長さ。記録表示の `<textarea>` の `maxlength` にも、この定数を使っている（`records/index.php`）。数字を1か所で決めて、画面とサーバーで同じ値を使う。

```php
// BR-002（同じ申請の重複）の文
const REQUEST_DUPLICATE_CORRECTION = 'この日には、申請中の申請があります。…';
const REQUEST_DUPLICATE_OVERTIME = '本日分の残業申請は、すでに申請中です。';
```

- 同じ申請が二重に出されたとき（6章）に返す文。

## 2. `inputString()`：送られた値を、文字列として取り出す

```php
function inputString(array $input, string $key): string {
    $value = $input[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
```

- `$input` は `$_POST` そのもの（`api/requests.php` がまるごと渡す）。
- 値が無い・文字列でない（`check_in[]=09:00` のように配列で送られた）ときは、`''`（空欄）として扱う。空欄なら、後の確認で「入力してください」になるので、おかしな値はそこで止まる。
- `trim()` で前後の空白を取る。理由の `'  打刻漏れ  '` は `'打刻漏れ'` で保存される。

> **ここが重要**：`$_POST` の値を直接使わず、**必ずこの関数を通して取り出す**。「文字列でないかもしれない」という心配を、この1か所で片付けておけば、後ろの関数はすべて「文字列が来る」と考えて書ける（引数の型を `string` にできる）。

## 3. 時刻と日付の小さな関数

```php
// 'H:i' の時刻を、対象日の日時（'Y-m-d H:i:00'）にする。空欄は null。形が正しくなければ false
function parseRequestTime(string $value, string $date): string|false|null {
    if ($value === '') {
        return null;
    }
    if (!preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value)) {
        return false;
    }
    return $date . ' ' . $value . ':00';
}
```

- 戻り値の型 `string|false|null` は**ユニオン型**（PHP 8.0 以降）。「日時の文字列」「`false`（形の誤り）」「`null`（空欄）」の3通りを返す。呼ぶ側は `=== false`・`=== null` で、どれかを見分ける。
- 正規表現 `([01]\d|2[0-3]):[0-5]\d` は、`00:00`〜`23:59` だけを通す。`9:00`（1桁）や `24:00` は通らない。`<input type="time">` が送る形は `'HH:MM'` なので、画面から送ればこの形になる。
- `\A` と `\z` は「文字列の先頭」と「文字列の最後」。`^` と `$` でも似たことができるが、`$` は最後の改行の手前でも一致してしまう。`\A…\z` なら、余計な文字が1つでもあれば通らない。
- 時刻に**対象日を付けて**、DB の列（`DATETIME`）と同じ `'2026-10-14 09:00:00'` の形にする。

```php
// 'Y-m-d' の正しい日付か
function isValidDate(string $value): bool {
    return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}
```

- 正規表現で形（`2026-10-14`）を確かめ、`$m` に年・月・日を取り出す（`$m[1]` が年、`$m[2]` が月、`$m[3]` が日）。
- 形だけでは `2026-02-30` も通ってしまうので、`checkdate()` で「本当にある日か」を確かめる。`checkdate()` の引数は**月・日・年**の順なので、`$m[2], $m[3], $m[1]` と並べ替えて渡している。

```php
// 勤怠の行から、申請する4つの時刻だけを取り出す（行が無ければ、すべて null）
function currentRequestTimes(?array $attendance): array {
    $times = [];
    foreach (array_keys(REQUEST_TIME_FIELDS) as $field) {
        $times[$field] = $attendance[$field] ?? null;
    }
    return $times;
}
```

- 勤怠の行には `overtime_end_at` なども入っているので、申請に関係する4つだけを、`REQUEST_TIME_FIELDS` の順に取り出す。
- `$attendance` が `null`（その日の記録が無い）でも、`??` のおかげで、4つとも `null` の配列になる。

## 4. `validateCorrection()`：修正・新規登録の入力を確かめる（このファイルの中心）

```php
function validateCorrection(array $input, ?array $current, string $now, string $joinedOn): array {
```

- 引数：送られた値（`$input`）、対象日の今の勤怠（`$current`。無ければ `null`）、今の日時（`'Y-m-d H:i:s'`）、入社日。
- 戻り値：`['errors' => [項目 => 文], 'targetDate' => …, 'requested' => 4つの時刻, 'reason' => 理由か null]`。`errors` が空の配列なら、入力は正しい。
- 今の日時を引数で受け取るので、テストでは `'2026-10-15 14:30:20'` のように好きな日時で確かめられる。

確かめる順番は、次のとおり。

| 順番 | 確かめること | 誤りのときの項目 |
| --- | --- | --- |
| 1 | 対象日が正しい日付で、入社日から今日まで | `target_date`（ここで終わる） |
| 2 | 4つの時刻の形が `HH:MM` か空欄 | その時刻の欄 |
| 3 | 出勤が入っている | `check_in` |
| 4 | 休憩は開始・終了の両方か、両方なし | 空いているほうの欄 |
| 5 | 出勤 ＜ 休憩開始 ＜ 休憩終了 ＜ 退勤 | 前後が逆になった欄 |
| 6 | 対象日が今日なら、まだ来ていない時刻ではない | その時刻の欄 |
| 7 | 理由が 500 文字まで | `reason` |
| 8 | 今の記録から変わっている | `form` |

### 対象日

```php
    $errors = [];
    $today = substr($now, 0, 10);
    $targetDate = inputString($input, 'target_date');
    if (!isValidDate($targetDate) || $targetDate < $joinedOn || $targetDate > $today) {
        $errors['target_date'] = '対象日は、入社日から今日までの日を指定してください。';
        return ['errors' => $errors, 'targetDate' => $targetDate, 'requested' => [], 'reason' => null];
    }
```

- `$targetDate < $joinedOn` は、**日付を文字列のまま比べている**。`'Y-m-d'` のように桁数がそろった形なら、文字列の大小と日付の前後が一致する（[src_attendance.md](./src_attendance.md) 5章の時刻の比べ方と同じ考え方）。
- 対象日が正しくないと、時刻に付ける日付が決まらないので、ここで**すぐに `return`** する（後ろの確認はしない）。

### 時刻の形

```php
    $inputNames = ['check_in_at' => 'check_in', 'break_start_at' => 'break_start', 'break_end_at' => 'break_end', 'check_out_at' => 'check_out'];
    $requested = [];
    foreach ($inputNames as $field => $name) {
        $time = parseRequestTime(inputString($input, $name), $targetDate);
        if ($time === false) {
            $errors[$name] = '時刻の形が正しくありません。';
            $time = null;
        }
        $requested[$field] = $time;
    }
```

- `$inputNames` は「DB の列の名前 → 入力欄の名前」の対応表。`$requested` は DB の列の名前をキーにして作り、`$errors` は入力欄の名前をキーにして作る（画面がどの欄の下に文を出すかを決められるように）。
- 形が誤っていた時刻は、`null` にしておく。こうしておくと、後ろの確認で `false` が混ざらない。

### 出勤は必須、休憩は両方か両方なし

```php
    if (!isset($errors['check_in']) && $requested['check_in_at'] === null) {
        $errors['check_in'] = '出勤時刻を入力してください。';
    }
    if (!isset($errors['break_start']) && !isset($errors['break_end']) && ($requested['break_start_at'] === null) !== ($requested['break_end_at'] === null)) {
        $errors[$requested['break_start_at'] === null ? 'break_start' : 'break_end'] = '休憩は、開始と終了の両方を入力してください。';
    }
```

- `!isset($errors['check_in'])`：形の誤りがすでに出ている欄に、別の文を上書きしないための条件。
- `(A === null) !== (B === null)` は、「片方だけが空」という意味。両方空（休憩なし）や両方入力は `false` になる。`true`／`false` どうしを `!==` で比べると「どちらか一方だけ」（排他的論理和）を表せる。
- 誤りの文は、**空いているほうの欄**に出す（開始だけ入れたなら、終了の欄に「両方を入力してください」）。

### 時刻の前後

```php
    // 順番：出勤 ＜ 休憩開始 ＜ 休憩終了 ＜ 退勤（入っているものどうし）
    if ($errors === []) {
        $previousField = null;
        foreach ($inputNames as $field => $name) {
            if ($requested[$field] === null) {
                continue;
            }
            if ($previousField !== null && $requested[$field] <= $requested[$previousField]) {
                $errors[$name] = REQUEST_TIME_FIELDS[$field] . '時刻は、' . REQUEST_TIME_FIELDS[$previousField] . '時刻より後にしてください。';
                break;
            }
            $previousField = $field;
        }
    }
```

- ここまでに誤りが無いときだけ調べる（形が誤った時刻と比べても意味がないため）。
- 時刻を前から順に見て、**「1つ前の入っている時刻」より後か**を確かめる。空欄は `continue` で飛ばすので、休憩なしなら「退勤 ＞ 出勤」を、退勤が空欄なら残りどうしを比べる。
- `<=` なので、同じ時刻も誤り（出勤 09:00・退勤 09:00 は不可）。
- 誤りが1つ見つかったら `break` で止める。前後が1つ崩れると、後ろも連鎖して崩れて見えるので、最初の1つだけを伝える。
- 文は `REQUEST_TIME_FIELDS` の名前から組み立てる（例：「退勤時刻は、出勤時刻より後にしてください。」）。
- 4つの時刻はすべて同じ対象日の日付が付いているので、**日をまたぐ入力（出勤 22:00・退勤 01:00）は、ここで「前後が逆」として止まる**（D-062）。

### 今日の、まだ来ていない時刻

```php
    if ($errors === [] && $targetDate === $today) {
        $nowMinute = substr($now, 0, 16) . ':00';
        foreach ($inputNames as $field => $name) {
            if ($requested[$field] !== null && $requested[$field] > $nowMinute) {
                $errors[$name] = 'まだ来ていない時刻は申請できません。';
            }
        }
    }
```

- `$now` の秒を切り捨てて（`'2026-10-15 14:30:20'` → `'2026-10-15 14:30:00'`）比べる。申請する時刻は分単位なので、今の「分」までは申請できる（14:30 は可、14:31 は不可）。

### 理由と、変更がないか

```php
    $reason = inputString($input, 'reason');
    if (mb_strlen($reason) > REQUEST_REASON_MAX_LENGTH) {
        $errors['reason'] = '理由は ' . REQUEST_REASON_MAX_LENGTH . ' 文字までにしてください。';
    }

    if ($errors === [] && $requested === currentRequestTimes($current)) {
        $errors['form'] = '今の記録から変更がありません。';
    }

    return ['errors' => $errors, 'targetDate' => $targetDate, 'requested' => $requested, 'reason' => $reason === '' ? null : $reason];
}
```

- `mb_strlen()` は**文字の数**を数える。`strlen()` はバイト数を数えるので、日本語1文字が3バイトと数えられてしまう。DB の `VARCHAR(500)` も文字数なので、`mb_strlen()` で合わせる。
- 理由は任意（D-041）。空欄なら `null` にして、DB に NULL を入れる。
- `$requested === currentRequestTimes($current)`：配列どうしを `===` で比べると、**キーと値の組が同じで、順番も型も同じ**ときだけ `true` になる。`$requested` は `$inputNames` の順、`currentRequestTimes()` は `REQUEST_TIME_FIELDS` の順で作るが、どちらも「出勤・休憩開始・休憩終了・退勤」の順なので、そのまま比べられる。勤怠は分単位（秒は `:00`）で記録しているので、DB の値と申請の値の形もそろう。

> **ここが重要**：誤りは**1つ見つけても止めずに、まとめて集める**（対象日の誤りと、前後の誤りだけは例外）。利用者は、1回の送信で直すべき欄をすべて知ることができる。一方で、前の確認が通っていないと意味のない確認（前後・未来・変更なし）は、`$errors === []` を条件にして飛ばしている。「まとめて伝える」と「意味のない文を出さない」を両立させる書き方。

## 5. `validateOvertime()`：簡単残業申請の入力を確かめる

```php
function validateOvertime(array $input, ?array $attendance, string $today, string $standardEnd): array {
    if (!overtimeAvailable($attendance, $standardEnd)) {
        return ['errors' => ['form' => '退勤済みで、退勤が標準終了時刻より後の場合に申請できます。'], 'overtimeEndAt' => null];
    }
    $value = inputString($input, 'overtime_end');
    $end = parseRequestTime($value, $today);
    if ($end === null) {
        return ['errors' => ['overtime_end' => '残業の終了時刻を入力してください。'], 'overtimeEndAt' => null];
    }
    if ($end === false) {
        return ['errors' => ['overtime_end' => '時刻の形が正しくありません。'], 'overtimeEndAt' => null];
    }
    if ($end <= $today . ' ' . $standardEnd) {
        return ['errors' => ['overtime_end' => '標準終了時刻（' . substr($standardEnd, 0, 5) . '）より後の時刻を指定してください。'], 'overtimeEndAt' => null];
    }
    if ($end > $attendance['check_out_at']) {
        return ['errors' => ['overtime_end' => '退勤時刻（' . formatClock($attendance['check_out_at']) . '）を超える時刻は指定できません。'], 'overtimeEndAt' => null];
    }
    return ['errors' => [], 'overtimeEndAt' => $end];
}
```

- 入力は1つ（残業の終了時刻）だけなので、こちらは誤りを集めず、**見つけたらすぐに `return`**（早期リターン）する。
- 最初の `overtimeAvailable()` は、メイン画面のボタンを押せるかの判断と**同じ関数**（[src_attendance.md](./src_attendance.md) 5章）。ボタンの判断と申請の受付で、ルールが食い違わない。
- この最初の確認を通れば、`$attendance` は `null` ではなく、`check_out_at` も入っている。だから最後の `$attendance['check_out_at']` を安心して読める。**確認の順番が、後ろのコードの安全を支えている**。
- 受け付ける範囲は「標準終了時刻より後（`<=` は不可）、退勤時刻まで（`>` だけ不可なので、退勤時刻ちょうどは可）」。
- 対象日は利用者が送るのではなく、`$today` を呼ぶ側が決める（次の章の `submitOvertimeRequest()` が `workDateOf($now)` を渡す）。簡単残業申請は本日分だけ（D-062）なので、日付を送らせないことで、ほかの日の申請はそもそもできない。

## 6. 保存する：`submitCorrectionRequest()`・`submitOvertimeRequest()`

```php
// 一意制約の違反（BR-002：申請中の同じ申請がある）か
function isDuplicateError(PDOException $e): bool {
    return ($e->errorInfo[1] ?? null) === 1062;
}
```

- `1062` は MySQL の「一意制約の違反」のエラー番号。`errorInfo[1]` に入っている。

```php
function submitCorrectionRequest(PDO $pdo, array $user, array $input, int $now): array {
    $targetDate = inputString($input, 'target_date');
    $current = isValidDate($targetDate) ? fetchAttendance($pdo, $user['employee_id'], $targetDate) : null;
    $checked = validateCorrection($input, $current, date('Y-m-d H:i:s', $now), $user['joined_on']);
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'errors' => $checked['errors']];
    }
    try {
        insertCorrectionRequest($pdo, $user['employee_id'], $checked['targetDate'], $checked['requested'], currentRequestTimes($current), $checked['reason']);
    } catch (PDOException $e) {
        if (!isDuplicateError($e)) {
            throw $e;
        }
        return ['ok' => false, 'errors' => ['form' => REQUEST_DUPLICATE_CORRECTION]];
    }
    return ['ok' => true, 'message' => '申請を受け付けました。承認されると勤怠に反映されます。'];
}
```

流れは「DB から読む → 確かめる（DB を使わない）→ 保存する」の3段。

- **読む**：「変更がないか」を確かめるには今の勤怠が要るので、確かめる前に `fetchAttendance()` で読む。ただし、対象日が正しい日付のときだけ。おかしな文字列を DB の検索に渡さないため。
- **確かめる**：`validateCorrection()` は DB を使わないので、ここに来る値（今の勤怠・今の日時）を全部引数で渡している。
- **保存する**：`insertCorrectionRequest()` に、申請の値（`requested`）と一緒に、**申請した時点の勤怠の値**（`currentRequestTimes($current)`）も渡す。DB の `original_…` の列に入り、承認で勤怠が上書きされたあとでも「変更前 → 申請値」を出せる（7章の `requestChanges()`）。
- 戻り値は `['ok' => …]` の形。`api/requests.php` は、これをそのまま JSON にして返す（[api_requests.md](./api_requests.md)）。

```php
function submitOvertimeRequest(PDO $pdo, array $user, array $input, int $now, string $standardEnd): array {
    $today = workDateOf($now);
    $attendance = fetchAttendance($pdo, $user['employee_id'], $today);
    $checked = validateOvertime($input, $attendance, $today, $standardEnd);
    …
        insertOvertimeRequest($pdo, $user['employee_id'], $today, $checked['overtimeEndAt'], $attendance['overtime_end_at'] ?? null);
    …
    return ['ok' => true, 'message' => '残業申請を受け付けました（〜' . formatClock($checked['overtimeEndAt']) . '・申請中）'];
}
```

- 形は `submitCorrectionRequest()` と同じ。申請した時点の残業終了時刻（`overtime_end_at`）を、変更前の値として保存する。
- 受け付けたときの文に、申請した時刻を入れる（例：「残業申請を受け付けました（〜19:00・申請中）」）。メイン画面は、この文をそのまま通知に出す。

> **ここが重要**：「申請中の同じ申請は1件まで」（BR-002）は、**先に SELECT で調べず、DB の一意制約で守っている**。調べてから INSERT するやり方では、2つの画面で同時に送ったときに防げない（[src_attendance.md](./src_attendance.md) 7章と同じ考え方）。`attendance_requests` には、申請中のときだけ `1`、それ以外は `NULL` になる列（`pending_key`）があり、「社員・対象日・種別・`pending_key`」に一意制約が付いている。一意制約は `NULL` どうしの重複を許すので、承認・却下された申請はいくつあってもよく、申請中だけが1件に制限される（くわしくは [src_queries_attendance_requests.md](./src_queries_attendance_requests.md)）。アプリは 1062 のときだけ受け止めて分かりやすい文に変え、それ以外のエラーは `throw $e;` で投げ直して隠さない。PR 9 から、メイン画面の簡単残業申請のボタンは、今日の残業申請が申請中なら押せなくなる（8章の `pendingStatesOf()`。D-069）。ただしそれは使いやすさのためで、最後の守りはこの一意制約のまま。

## 7. 表示を作る：`requestTypeLabel()`・`requestChanges()`・`requestView()`

DB から読んだ申請の行を、画面に出す形にする関数。記録表示（`src/records.php`）と承認の画面（`src/approvals.php`）の両方で使う。

```php
function requestTypeLabel(array $request): string {
    if ($request['request_type'] === 'overtime') {
        return '残業';
    }
    foreach (array_keys(REQUEST_TIME_FIELDS) as $field) {
        if ($request['original_' . $field] !== null) {
            return '修正';
        }
    }
    return '新規登録';
}
```

- DB の `request_type` は `correction`（修正・新規登録）と `overtime` の2種類だけ。「修正」と「新規登録」は、**申請した時点の値（`original_…`）が1つでもあったか**で見分ける。全部 `null` なら、申請した時点で記録が無かった＝新規登録。
- `'original_' . $field` のように、文字列をつないで列の名前を作っている。`REQUEST_TIME_FIELDS` のキーが列の名前の後ろ半分になっているので、こう書ける。

```php
function requestChanges(array $request): array {
    $fields = $request['request_type'] === 'overtime' ? ['overtime_end_at' => '残業終了'] : REQUEST_TIME_FIELDS;
    $changes = [];
    foreach ($fields as $field => $label) {
        $before = $request['original_' . $field];
        $after = $request['requested_' . $field];
        if ($before !== $after) {
            $changes[] = [
                'label' => $label,
                'before' => $before === null ? 'なし' : formatClock($before),
                'after' => $after === null ? 'なし' : formatClock($after),
            ];
        }
    }
    return $changes;
}
```

- **変わった項目だけ**を「変更前 → 申請値」の組にする（D-062）。例：`[['label' => '退勤', 'before' => '17:30', 'after' => '18:45']]`。
- 値が無いときは `'なし'`。間違って記録された休憩を消す申請なら「休憩開始 15:00 → なし」のように出る。
- `$changes[] = …` は、配列の最後に1つ足す書き方。

```php
function requestView(array $request): array {
    return [
        'id' => (int) $request['id'],
        'type' => requestTypeLabel($request),
        'status' => $request['status'],
        'statusLabel' => REQUEST_STATUS_LABELS[$request['status']],
        'createdAt' => date('Y/n/j H:i', strtotime($request['created_at'])),
        'changes' => requestChanges($request),
        'reason' => $request['reason'] ?? '',
        'reviewComment' => $request['review_comment'] ?? '',
        'canCancel' => $request['status'] === 'pending',
    ];
}
```

- 記録表示で、日付を押したときに出る「その日の申請の一覧」の1件分。`src/records.php` が `array_map('requestView', $dayRequests)` で全件に使い、JSON にして画面に渡す。
- `(int)` で `id` を整数にそろえる。DB の設定によっては、数値の列も文字列で返ってくることがあるため。
- `strtotime()` で `'2026-10-14 20:00:00'` を秒数にし、`date('Y/n/j H:i', …)` で `'2026/10/14 20:00'` にする。`n`・`j` は先頭に 0 を付けない月・日。
- `?? ''`：理由・却下コメントが `null` なら空の文字列にする。JavaScript 側で `null` を気にしなくてよい。
- `canCancel`：申請中だけ「取り消す」ボタンを出す（D-063）。ただし、これは表示のため。本当に取り消せるかは、9章の `submitCancelRequest()` が DB で確かめる。

> **ここが重要**：`attendanceView()`（[src_attendance.md](./src_attendance.md) 6章）と同じく、**表示の形を PHP の1か所で作り、JavaScript は受け取って並べるだけ**にしている。「修正か新規登録か」「どの項目が変わったか」のような判断を JavaScript に書かないので、記録表示と承認の画面で表示が食い違わない。

## 8. 申請中かを調べる

```php
// カレンダーに出すバッジの状態：申請中があれば申請中、無ければ最新の申請の状態。申請が無ければ null
// $requests：その日の申請（古い順）
function requestBadgeStatus(array $requests): ?string {
    if ($requests === []) {
        return null;
    }
    foreach ($requests as $request) {
        if ($request['status'] === 'pending') {
            return 'pending';
        }
    }
    return $requests[count($requests) - 1]['status'];
}
```

- 記録表示のカレンダーで、日付に付けるバッジの状態を決める。申請中が1件でもあれば「申請中」を優先し、無ければ最新の申請の状態。
- `$requests` は古い順に並んでいる（`fetchMonthlyRequests()` が `ORDER BY target_date, created_at, id` で読む）。だから、最後の要素 `$requests[count($requests) - 1]` が最新。

```php
// その日に、申請中の修正・新規登録があるか
function hasPendingCorrection(array $requests): bool {
    foreach ($requests as $request) {
        if ($request['request_type'] === 'correction' && $request['status'] === 'pending') {
            return true;
        }
    }
    return false;
}

// その日の申請に、申請中の残業申請があるか
function hasPendingOvertime(array $requests): bool {
    foreach ($requests as $request) {
        if ($request['request_type'] === 'overtime' && $request['status'] === 'pending') {
            return true;
        }
    }
    return false;
}

// メイン画面で使う、今日の申請中の状態（修正・新規登録 → 打刻を止める。D-063。残業 → 簡単残業申請を止める。D-069）
// SQL は1回だけ実行する。戻り値：['correction' => bool, 'overtime' => bool]
function pendingStatesOf(PDO $pdo, string $employeeId, string $date): array {
    $requests = fetchMonthlyRequests($pdo, $employeeId, $date, $date);
    return ['correction' => hasPendingCorrection($requests), 'overtime' => hasPendingOvertime($requests)];
}
```

- `hasPendingCorrection()` は**残業の申請中は数えない**。打刻を止めるのは、修正・新規登録が申請中のときだけ（承認で勤怠の4つの時刻が申請の値に上書きされ、押した打刻が消えてしまうため。D-063）。
- `hasPendingOvertime()` は、その逆で、残業の申請中だけを数える。修正の申請中や、却下された残業申請は数えない。
- どちらも配列を受け取るだけ（DB を使わない）。`hasPendingCorrection()` は、記録表示でも、月の申請をまとめて読んだあと日ごとに使う（`src/records.php`）。
- `pendingStatesOf()` は、DB から今日の申請を1回だけ読んで、2つの関数に渡す。メイン画面（`index.php`）と打刻の API（`api/attendance.php`）が使い、`'correction'` を打刻の停止（`$locked`）に、`'overtime'` を簡単残業申請のボタンの停止（`$overtimePending`）に渡す（[src_attendance.md](./src_attendance.md) 6章）。
- 1日分を読むのに、新しい SQL を作らず、期間を読む `fetchMonthlyRequests()` に**初日と末日に同じ日**を渡して使い回している。

> **ここが重要**：前は、修正の申請中だけを調べる `isCorrectionPending()` があった。PR 9 で残業の申請中も調べることになったとき、関数を2つ並べると、同じ日の申請を DB から2回読むことになる。そこで `pendingStatesOf()` に置き換え、**SQL は1回、判断は DB を使わない関数で2つ**、という形にした（D-069）。判断の関数は配列を渡すだけなので、`tests/RequestsTest.php` で DB なしに確かめられる。

## 9. `submitCancelRequest()`：申請を取り消す

```php
function submitCancelRequest(PDO $pdo, array $user, array $input): array {
    $requestId = inputString($input, 'request_id');
    if (!preg_match('/\A[1-9]\d{0,18}\z/', $requestId)) {
        return ['ok' => false, 'errors' => ['form' => '取り消す申請が正しくありません。画面を読み込み直してください。']];
    }
    if (!deletePendingRequest($pdo, (int) $requestId, $user['employee_id'])) {
        return ['ok' => false, 'errors' => ['form' => 'この申請は、すでに承認・却下されたか、取り消されています。画面を読み込み直してください。']];
    }
    return ['ok' => true, 'message' => '申請を取り消しました。'];
}
```

- 正規表現 `[1-9]\d{0,18}` で、「先頭が 0 でない、19桁までの数字」だけを通す。`abc` や `-1`、`0` は、DB に聞く前に断る。
- `deletePendingRequest()` の SQL は、`id` だけでなく、**本人（`employee_id`）と申請中（`status = 'pending'`）も条件に付けて**削除する。消せた行が1行なら `true` を返す。

```php
    $sql = "DELETE FROM attendance_requests WHERE id = :id AND employee_id = :employee_id AND status = 'pending'";
```

> **ここが重要**：送られてくる `request_id` は、ブラウザで書き換えられる。`id` だけを条件にすると、**ほかの人の申請の番号を送れば、その申請を消せてしまう**。本人の社員コードはセッション（`$user`）から取り、利用者が送った値は使わない。さらに「申請中だけ」も条件に入れているので、取り消しのボタンを押す直前に管理者が承認していた場合も、承認済みの申請は消えず、`false` が返って「すでに承認・却下されたか…」と伝えられる。**権限の確認と、同時の操作への備えを、SQL の `WHERE` の1行でまとめて行っている**。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 入力のルールはサーバーで確かめる | 画面の確認は使いやすさのためだけ |
| 送られた値は1か所で取り出す | `inputString()` が「無い・文字列でない」を片付ける |
| 戻り値で3通りを伝える | `parseRequestTime()` の `string\|false\|null` |
| 形と中身を分けて確かめる | `isValidDate()` の正規表現と `checkdate()` |
| 誤りはまとめて集める | `validateCorrection()` の `$errors` |
| 前の確認が後ろの安全を支える | `validateOvertime()` の `overtimeAvailable()` が最初 |
| 同じ判断の関数を使い回す | ボタンの判断と申請の受付で `overtimeAvailable()` |
| 重複は DB の一意制約で守る | BR-002 と 1062 の受け止め |
| 申請した時点の値を残す | `original_…` の列と `requestChanges()` |
| 権限は SQL の条件でも守る | 取り消しは本人・申請中を `WHERE` に入れる |
| DB を使わない関数に分ける | `validate…()`・`hasPendingCorrection()`・`hasPendingOvertime()` はテストしやすい |

## 関連するファイル

- 呼ぶ側：`api/requests.php`（[api_requests.md](./api_requests.md)）、`api/attendance.php`（[api_attendance.md](./api_attendance.md)）、`index.php`（[index.md](./index.md)）、`src/records.php`（[src_records.md](./src_records.md)）、`src/approvals.php`（[src_approvals.md](./src_approvals.md)）
- 呼ばれる側：`src/queries/attendance_requests.php`（`insertCorrectionRequest()`・`insertOvertimeRequest()`・`fetchMonthlyRequests()`・`deletePendingRequest()`。[src_queries_attendance_requests.md](./src_queries_attendance_requests.md)）、`src/attendance.php`（[src_attendance.md](./src_attendance.md)）
- 画面の動き：`js/records.js`（[js_records.md](./js_records.md)）、`js/main.js`（[js_main.md](./js_main.md)）
- テスト：`tests/RequestsTest.php`（入力のルール、変更前 → 申請値、バッジ）
- 決めごと：[決定事項.md](../../決定事項.md)（D-041・D-062・D-063・D-069）
