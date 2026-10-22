# `src/records.php` を読む（月の決め方・労働時間の計算・カレンダー）

## このファイルの役割

記録表示の画面（`records/index.php`）のために、**「どの月を出すか」「1日に何分働いたか」「カレンダーのセルに何を出すか」を計算する**ファイル。

- HTML は出さない。SQL も書かない。DB から読んだ行（配列）と、今日の日付・入社日などを受け取って、答えを返すだけ。
- そのため、DB が無くてもテストできる（`tests/RecordsTest.php`）。月の決め方、労働時間の計算、カレンダーの組み立てを、いろいろな値で確かめている。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭 | 使うファイルの読み込み（祝日・出退勤・申請） |
| `resolveMonth()` | 表示する月を決める（範囲の外なら今月） |
| `dailyWorkMinutes()` | 1日の労働時間（分）を計算する |
| `formatMinutes()` | 分を「○時間○分」にする |
| `buildCalendar()` | 月カレンダーのセル、出勤日数、総労働時間を作る |

上の3つが部品で、最後の `buildCalendar()` が、部品とほかのファイルの関数を組み合わせて、画面に出す値を全部作る。

---

## 1. 使うファイルの読み込み

```php
include_once(__DIR__ . '/holidays.php');
include_once(__DIR__ . '/attendance.php');
include_once(__DIR__ . '/requests.php');
```

- `holidays.php`：祝日の計算（`japaneseHolidays()`）と、計算できる最初の年（`HOLIDAYS_MIN_YEAR`）。
- `attendance.php`：メイン画面と同じ表示の作り方（`attendanceView()`・`formatClock()`）。
- `requests.php`：申請の表示（`requestView()`・`requestBadgeStatus()`・`hasPendingCorrection()`・`REQUEST_STATUS_LABELS`）。

表示の作り方を、メイン画面や申請と**共有**している。同じ「09:00」「--:--」の形が、どの画面でも同じ関数から出てくる。

## 2. `resolveMonth()`：表示する月を決める

```php
function resolveMonth(?string $requested, string $today, string $joinedOn): array {
    $current = substr($today, 0, 7);
    // 入社日が未来の場合や、祝日を計算できない年より前の場合も、表示できる範囲に収める
    $earliest = max(min(substr($joinedOn, 0, 7), $current), sprintf('%04d-01', HOLIDAYS_MIN_YEAR));
```

- 引数：`$requested` は URL の `month`（例：`'2026-09'`。無ければ `null`）、`$today` は今日（`'2026-10-15'`）、`$joinedOn` は入社日（`'2025-04-01'`）。
- `substr($today, 0, 7)`：先頭の7文字 → `'2026-10'`（今月）。
- `$earliest`（戻れる一番前の月）は、3段階で決めている。
  1. `substr($joinedOn, 0, 7)`：入社した月。前の月は入社した月まで（D-061）。
  2. `min(…, $current)`：入社日が未来（入社前にアカウントを作った場合）だと、入社した月が今月より後になってしまう。今月より後にならないように抑える。
  3. `max(…, '2000-01')`：祝日を計算できるのは 2000 年から（`HOLIDAYS_MIN_YEAR`）。それより前の月は出さない。`sprintf('%04d-01', 2000)` で `'2000-01'` を作っている。
- `'YYYY-MM'` の形は桁数がそろっているので、**文字列のまま `min()`・`max()`・`<`・`>` で比べられる**（文字列の大小と、月の前後が一致する）。

```php
    $month = $current;
    if ($requested !== null && preg_match('/\A(\d{4})-(\d{2})\z/', $requested, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12
        && $requested >= $earliest && $requested <= $current) {
        $month = $requested;
    }
```

- まず「今月」にしておき、指定が**すべての条件を満たしたときだけ**、指定の月に変える。
- `preg_match('/\A(\d{4})-(\d{2})\z/', …)`：数字4桁・`-`・数字2桁の形か。`\A` は文字列の先頭、`\z` は末尾。`^`・`$` と違って、末尾の改行も通さない。
- `( )` で囲んだ部分は、3つ目の引数 `$m` に入る。`$m[1]` が年、`$m[2]` が月。`(int)` で数にして、1〜12 かを確かめる（`'2026-13'` や `'2026-00'` を通さない）。
- 最後に、範囲（`$earliest`〜今月）に入っているか。
- `&&` は左から順に調べ、`false` が出たらそこで止まる。形を確かめてから中身を見るので、`$m` が無いまま `$m[2]` を読むことはない。

```php
    $first = $month . '-01';
    $prev = date('Y-m', strtotime($first . ' -1 month'));
    $next = date('Y-m', strtotime($first . ' +1 month'));
    return [
        'month' => $month,
        'label' => (int) substr($month, 0, 4) . '年' . (int) substr($month, 5, 2) . '月',
        'first' => $first,
        'last' => date('Y-m-t', strtotime($first)),
        'prev' => $prev >= $earliest ? $prev : null,
        'next' => $next <= $current ? $next : null,
    ];
}
```

- `strtotime('2026-09-01 -1 month')`：文字列で書いた日時の計算をして、タイムスタンプ（1970年からの秒数）を返す。それを `date('Y-m', …)` で `'2026-08'` にする。
- `'label'`：`(int)` で `'09'` → `9` にして、`'2026年9月'` を作る（先頭の 0 を消す）。
- `date('Y-m-t', …)`：書式の `t` は「その月の日数」。`'2026-09-30'`、うるう年の2月なら `'2024-02-29'` になる。末日を自分で計算しなくてよい。
- 前後の月が範囲の外なら `null`。画面は `null` のとき、矢印を押せない見た目にする。

> **ここが重要**：前後の月の計算は、**必ず月の1日（`$first`）から**行っている。月末の日から `-1 month` すると、PHP は「日」をそのままにして計算するので、`strtotime('2026-03-31 -1 month')` は「2月31日」＝ **3月3日** になってしまう。1日ならどの月にもあるので、この問題が起きない。

結果の例（今日が 2026-10-15、入社日が 2025-04-01）。`tests/RecordsTest.php` で確かめている例。

| URL の month | 表示する月 | prev | next | 理由 |
| --- | --- | --- | --- | --- |
| なし | 2026-10 | 2026-09 | null | 指定なし → 今月。次は無い |
| `2026-09` | 2026-09 | 2026-08 | 2026-10 | 範囲の中 |
| `2025-04` | 2025-04 | null | 2025-05 | 入社した月。前へは行けない |
| `2025-03` | 2026-10 | 2026-09 | null | 入社より前 → 今月 |
| `2026-11` | 2026-10 | 2026-09 | null | 未来 → 今月 |
| `2026-9` | 2026-10 | 2026-09 | null | 形が違う → 今月 |
| `<script>` | 2026-10 | 2026-09 | null | 形が違う → 今月 |

> **ここが重要**：おかしな値を受け取ったとき、エラーの画面にせず**今月を出す**。URL を手で書き換えた人にも、画面は普通に動く。そのうえで、受け取ってよい値の条件（形・月の数・範囲）を全部書いて、それ以外は捨てている（ホワイトリスト）。`<script>` のような値も、ここで捨てられるので、画面に出ることはない。

## 3. `dailyWorkMinutes()`：1日の労働時間（このファイルの中心）

ルール（D-049・D-061）：

- 労働時間 ＝ 退勤 − 出勤 − 昼休憩 − 休憩。
- 出勤か退勤が無い日は計算しない（`null`）。
- 昼休憩は、**勤務の時間と重なる部分だけ**引く（11:30 に退勤した日は、昼休憩を引かない）。
- 休憩は、開始と終了の**両方があるときだけ**引く。
- 昼休憩と休憩が重なる部分は、**1回だけ**引く。

```php
function dailyWorkMinutes(array $attendance, string $workDate, array $settings): ?int {
    if ($attendance['check_in_at'] === null || $attendance['check_out_at'] === null) {
        return null;
    }
    $start = strtotime($attendance['check_in_at']);
    $end = strtotime($attendance['check_out_at']);
    if ($end <= $start) {
        return 0;
    }
```

- `?int`：戻り値は「整数か `null`」。`null` は「計算できない日」、`0` は「0分働いた」で、意味が違う。画面では `null` を `--` と出す。
- 時刻の文字列（`'2026-10-15 09:00:00'`）を `strtotime()` でタイムスタンプ（秒）にする。秒の数にすれば、引き算で長さが出せる。
- 退勤が出勤より前（ふつうは起きない値）なら `0` にする。マイナスの時間が合計に入らないようにしている。

```php
    // 引く時間帯（勤務の時間帯と重なる部分だけ）
    $rests = [[strtotime($workDate . ' ' . $settings['lunch_start']), strtotime($workDate . ' ' . $settings['lunch_end'])]];
    if ($attendance['break_start_at'] !== null && $attendance['break_end_at'] !== null) {
        $rests[] = [strtotime($attendance['break_start_at']), strtotime($attendance['break_end_at'])];
    }
```

- 「引く時間帯」を `[開始, 終了]` の組の配列にする。まず昼休憩。勤務設定の `lunch_start`（`'12:00:00'`）は時刻だけなので、その日の日付 `$workDate` を前に付けて、日時にしてから `strtotime()` する。
- 休憩は、開始と終了の両方があるときだけ足す。

```php
    $clipped = [];
    foreach ($rests as [$restStart, $restEnd]) {
        $restStart = max($restStart, $start);
        $restEnd = min($restEnd, $end);
        if ($restStart < $restEnd) {
            $clipped[] = [$restStart, $restEnd];
        }
    }
```

- `foreach ($rests as [$restStart, $restEnd])`：配列の分割代入。組の1つ目を `$restStart`、2つ目を `$restEnd` に入れる。
- **勤務の時間帯からはみ出した部分を切り落とす**。始まりは「出勤より前にはしない」（`max`）、終わりは「退勤より後にはしない」（`min`）。
- 切り落とした結果、長さが無くなった（始まり ≧ 終わり）ものは捨てる。例えば 11:30 に退勤した日の昼休憩は、`max(12:00, 9:00)` = 12:00、`min(13:00, 11:30)` = 11:30 で、12:00 ≧ 11:30 なので捨てられる。

```php
    // 重なりをまとめてから合計する（二重に引かない）
    usort($clipped, fn ($a, $b) => $a[0] <=> $b[0]);
    $restSeconds = 0;
    $coveredUntil = $start;
    foreach ($clipped as [$restStart, $restEnd]) {
        $restStart = max($restStart, $coveredUntil);
        if ($restStart < $restEnd) {
            $restSeconds += $restEnd - $restStart;
            $coveredUntil = $restEnd;
        }
    }

    return intdiv($end - $start - $restSeconds, 60);
}
```

- `usort()`：配列を、自分で決めた順に並べ替える。2つ目の引数が「比べ方」の関数。
  - `fn ($a, $b) => …` は**アロー関数**（PHP 7.4 以降）。`function ($a, $b) { return …; }` の短い書き方。
  - `<=>`（宇宙船演算子）：左が小さければ `-1`、同じなら `0`、大きければ `1` を返す。`usort()` が欲しい答えの形そのもの。ここでは開始時刻（`[0]`）の早い順に並べる。
- `$coveredUntil`：「ここまでは、もう引いた」という時刻。次の時間帯の始まりがそれより前なら、`max()` で後ろにずらして、**引いた部分を二度引かない**。
- 最後に「勤務の長さ − 引く長さ」を秒で求め、`intdiv(…, 60)` で分にする。`intdiv()` は整数の割り算（小数を切り捨てる）。打刻は分単位なので、ちょうど割り切れる。

計算の例：出勤 09:00、退勤 18:00、休憩 12:30〜13:30、昼休憩 12:00〜13:00。

| 手順 | 中身 |
| --- | --- |
| 勤務の長さ | 09:00〜18:00 ＝ 540分 |
| 切り落とし | 昼休憩 12:00〜13:00、休憩 12:30〜13:30（どちらも勤務の中なので、そのまま） |
| 並べ替え | 12:00〜13:00 → 12:30〜13:30 の順 |
| 1つ目 | 始まり `max(12:00, 09:00)` ＝ 12:00。12:00〜13:00 の60分を引く。「ここまで引いた」＝ 13:00 |
| 2つ目 | 始まり `max(12:30, 13:00)` ＝ 13:00。13:00〜13:30 の30分を引く（12:30〜13:00 は引き済み） |
| 結果 | 540 − 60 − 30 ＝ **450分**（7時間30分） |

ほかの例（どれも勤務設定の昼休憩は 12:00〜13:00。`tests/RecordsTest.php` で確かめている）：

| 出勤 | 退勤 | 休憩 | 結果 | 説明 |
| --- | --- | --- | --- | --- |
| 09:00 | 18:00 | なし | 480分 | 540 − 昼休憩 60 |
| 09:00 | 18:00 | 15:00〜15:15 | 465分 | 540 − 60 − 15 |
| 09:00 | 11:30 | なし | 150分 | 昼休憩の前に退勤。昼休憩は引かない |
| 09:00 | 12:30 | なし | 180分 | 210 − 昼休憩のうち 12:00〜12:30 の30 |
| 12:30 | 18:00 | なし | 300分 | 330 − 昼休憩のうち 12:30〜13:00 の30 |
| 09:00 | 18:00 | 15:00〜（終了なし） | 480分 | 休憩終了が無いので、休憩は引かない |
| 09:00 | 18:00 | 12:10〜12:40 | 480分 | 休憩が昼休憩の中に全部入る。1回だけ引く |
| 09:00 | 18:00 | 17:30〜18:30 | 450分 | 休憩のうち、退勤後の 18:00〜18:30 は切り落とす |
| 09:00 | なし | なし | `null` | 退勤が無い日は計算しない |

> **ここが重要**：「昼休憩と休憩を、それぞれ引く」だけだと、重なったときに二重に引いてしまう。このコードは、**① 勤務の外を切り落とす → ② 開始の順に並べる → ③ 引いた所まで覚えながら足す**、という手順で、重なりを1回だけ数えている。時間帯が2つより増えても、そのまま正しく動く書き方になっている。

> **注意**：昼休憩の時刻は、**今の**勤務設定の値を使う。勤務設定は履歴を持たないので、システム管理者が昼休憩を変えると、過去の日の労働時間も新しい昼休憩で計算し直される（D-067）。

## 4. `formatMinutes()`：分を「○時間○分」にする

```php
function formatMinutes(int $minutes): string {
    return intdiv($minutes, 60) . '時間' . sprintf('%02d', $minutes % 60) . '分';
}
```

- `intdiv($minutes, 60)` が時間、`$minutes % 60`（割った余り）が分。
- `sprintf('%02d', …)`：2桁に満たなければ、前を 0 で埋める。`5` → `'05'`。
- 例：`485` → `'8時間05分'`、`9630` → `'160時間30分'`、`0` → `'0時間00分'`。時間は 24 を超えてもそのまま数える（月の合計に使うため）。

## 5. `buildCalendar()`：月カレンダーを組み立てる

```php
function buildCalendar(array $month, array $attendances, array $settings, string $today, array $requests, string $joinedOn): array {
```

- 引数：`$month` は `resolveMonth()` の戻り値、`$attendances` はその月の勤怠の行、`$settings` は勤務設定、`$requests` はその月の申請の行（古い順）、`$joinedOn` は入社日。
- 戻り値：`['weeks' => 週ごとに7つのセル, 'workDays' => 出勤日数, 'totalMinutes' => 総労働時間（分）]`。

### 探しやすい形に並べ替える

```php
    $byDate = [];
    foreach ($attendances as $row) {
        $byDate[$row['work_date']] = $row;
    }
    $requestsByDate = [];
    foreach ($requests as $request) {
        $requestsByDate[$request['target_date']][] = $request;
    }
    $holidays = japaneseHolidays((int) substr($month['first'], 0, 4));
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
```

- DB から読んだ行は「記録がある日だけ」の、ただの並び。これを**日付をキーにした配列**にしておくと、あとで `$byDate['2026-09-01']` のように、日付からすぐに取り出せる。毎日、配列の頭から探さなくてよい。
- 申請は1日に何件もありうるので、`$requestsByDate[日付][] = …` で、日付ごとの配列に足していく（`[]` は「末尾に足す」）。キーがまだ無くても、PHP が自動で配列を作る。
- 祝日は、表示する月の年の分を計算する。1か月が2つの年にまたがることは無いので、1年分で足りる。結果は `['2026-09-21' => '敬老の日', …]` の形。

### 1日の前の空き

```php
    $cells = array_fill(0, (int) date('w', strtotime($month['first'])), null);
```

- `date('w', …)`：曜日の番号（日曜 0 〜 土曜 6）。
- `array_fill(0, 個数, null)`：`null` を個数分並べた配列を作る。カレンダーは日曜から始まるので、1日が火曜（2）なら、前に `null` を2つ置く。日曜なら0個（空の配列）。

### 1日ずつ進む

```php
    for ($date = $month['first']; $date <= $month['last']; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
        $weekday = (int) date('w', strtotime($date));
        $row = $byDate[$date] ?? null;
        $minutes = $row !== null ? dailyWorkMinutes($row, $date, $settings) : null;
        if ($row !== null && $row['check_in_at'] !== null) {
            $workDays++;
        }
        $totalMinutes += $minutes ?? 0;
```

- `for` の3つの部分を、日付の文字列で書いている。初日から始め、末日まで、1日ずつ進む。`'Y-m-d'` は文字列のまま `<=` で比べられる。
- `$byDate[$date] ?? null`：その日の行。記録が無い日はキーが無いので `null`。
- **出勤日数**は「出勤の記録がある日」を数える。**総労働時間**は `dailyWorkMinutes()` の合計で、`null`（退勤が無い日など）は `?? 0` で 0 として足す。そのため、退勤を忘れた日は、出勤日数には入るが、総労働時間には入らない（D-049・D-061）。

```php
        $view = attendanceView($row);
        // 過去の日で休憩終了が無いときは「休憩中」ではなく、終了を空けて表示する
        $break = $view['break'];
        if ($date !== $today && $row !== null && $row['break_start_at'] !== null && $row['break_end_at'] === null) {
            $break = formatClock($row['break_start_at']) . '–';
        }
```

- 出勤・退勤・休憩の表示は、メイン画面と同じ `attendanceView()` で作る。引数1つだけなので、ボタンの判断に使う標準終了時刻などは省略している（既定値が使われる）。
- ただし `attendanceView()` は「今日の画面」のための関数で、休憩開始があって休憩終了も退勤も無いと「12:30–休憩中」と出す。過去の日にそれは変なので、**今日以外**は `'12:30–'`（終了を空ける）に置き換える。

```php
        $dayRequests = $requestsByDate[$date] ?? [];
        $badge = requestBadgeStatus($dayRequests);
        $pendingCorrection = hasPendingCorrection($dayRequests);
        // 申請ウィンドウに入れておく、今の値（'H:i'。無ければ空欄）
        $values = [];
        foreach (['check_in' => 'check_in_at', 'check_out' => 'check_out_at', 'break_start' => 'break_start_at', 'break_end' => 'break_end_at'] as $name => $field) {
            $values[$name] = ($row[$field] ?? null) === null ? '' : formatClock($row[$field]);
        }
```

- `requestBadgeStatus()`：カレンダーのバッジの状態。申請中が1件でもあれば `pending`、無ければ最新の申請の状態、申請が無ければ `null`（`src/requests.php`）。
- `hasPendingCorrection()`：その日に、申請中の修正・新規登録があるか。あれば新しい申請はできない。
- `$values`：申請ウィンドウの4つの欄に、最初から入れておく今の値（D-062）。記録が無い時刻は `''`（空欄）にする。表示用の `--:--` ではなく空欄にするのは、時刻の入力欄（`type="time"`）に入れる値は `'09:00'` の形か空欄でなければならず、空欄は「なし」として申請されるため。
- `$row[$field] ?? null`：`$row` が `null`（記録が無い日）でも、`??` ならエラーにならずに `null` になる。

```php
        $cells[] = [
            'date' => $date,
            'day' => (int) substr($date, 8, 2),
            'label' => (int) substr($date, 5, 2) . '月' . (int) substr($date, 8, 2) . '日（' . $weekdays[$weekday] . '）',
            'weekday' => $weekday,
            'holiday' => $holidays[$date] ?? '',
            'isRestDay' => $weekday === 0 || $weekday === 6 || isset($holidays[$date]),
            'isToday' => $date === $today,
            'hasRecord' => $row !== null && ($row['check_in_at'] !== null || $row['check_out_at'] !== null),
            'checkIn' => $view['checkIn'],
            'checkOut' => $view['checkOut'],
            'break' => $break,
            'workTime' => $minutes === null ? '--' : formatMinutes($minutes),
            'values' => $values,
            'badge' => $badge,
            'badgeLabel' => $badge === null ? '' : REQUEST_STATUS_LABELS[$badge],
            'requests' => array_map('requestView', $dayRequests),
            'isFuture' => $date > $today,
            'pendingCorrection' => $pendingCorrection,
            'canRequest' => $date >= $joinedOn && $date <= $today && !$pendingCorrection,
        ];
    }
```

1日分のセル。どれも「画面にそのまま出せる形」にしてある。

| キー | 例 | 使う所 |
| --- | --- | --- |
| `date` | `'2026-09-21'` | ボタンの `data-date` |
| `day`・`label`・`weekday` | `21`・`'9月21日（月）'`・`1` | 日付の数字、ウィンドウの見出し、色 |
| `holiday`・`isRestDay` | `'敬老の日'`・`true` | 祝日名、休日の背景 |
| `isToday` | `false` | 今日の枠 |
| `hasRecord` | `true` | 時刻を出すか、「記録はありません。」か |
| `checkIn`・`checkOut`・`break`・`workTime` | `'09:00'`・`'18:00'`・`'--:--'`・`'8時間00分'` | 時刻と労働時間の表示 |
| `values` | `['check_in' => '09:00', …]` | 申請ウィンドウの最初の値 |
| `badge`・`badgeLabel` | `'approved'`・`'承認済み'` | バッジ |
| `requests` | `requestView()` の配列 | その日の申請の一覧 |
| `pendingCorrection`・`canRequest` | `false`・`true` | 申請中の案内、申請のボタンを出すか |
| `isFuture` | `false` | 未来の日か（JavaScript に渡しているが、今の `js/records.js` は使っていない。未来の日は `canRequest` が `false` になるので、それで足りている） |

- `(int) substr($date, 8, 2)`：`'2026-09-01'` の8文字目から2文字 → `'01'` → `1`。
- `isset($holidays[$date])`：その日が祝日の配列にあるか。
- `array_map('requestView', $dayRequests)`：配列の全部に関数を当てて、結果の配列を作る。関数は**名前の文字列**で渡せる。
- `canRequest`：申請できるのは「入社日から今日まで」で、「申請中の修正・新規登録が無い」日。

> **ここが重要**：`canRequest` や `workTime` のような**判断と計算は、ここ（PHP）で済ませておく**。HTML のテンプレートも JavaScript も、受け取った値を出すだけで、ルールを持たない。ルールが PHP の1か所にあるので、テストもここだけ書けばよく、画面とずれることもない。ただし、`canRequest` はボタンを出すかどうかに使うだけで、申請を受け付けるときは、サーバー（`validateCorrection()`）がもう一度確かめる。

### 末尾の空きと、週に分ける

```php
    while (count($cells) % 7 !== 0) {
        $cells[] = null;
    }

    return [
        'weeks' => array_chunk($cells, 7),
        'workDays' => $workDays,
        'totalMinutes' => $totalMinutes,
    ];
}
```

- セルの数が7の倍数になるまで、末尾に `null` を足す（最後の週の、月末より後の日）。
- `array_chunk($cells, 7)`：配列を7個ずつに分ける。これで「週の配列」になる。

例：2026年9月は、1日が火曜日。前の空きが2つ、日が30個で32個。7の倍数にするために3つ足して35個、`array_chunk` で**5週**になる。1週目は `[null, null, 9/1, 9/2, 9/3, 9/4, 9/5]`、5週目は `[9/27, 9/28, 9/29, 9/30, null, null, null]`。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| おかしな入力は、決めた既定値にする | `resolveMonth()` は範囲外・形の違いを今月にする |
| 月の計算は1日から | `strtotime($first . ' -1 month')` |
| 桁のそろった日付は文字列で比べられる | `'2026-09' >= '2025-04'`、`$date <= $month['last']` |
| 時間の計算は秒の数で | `strtotime()` してから引き算、`intdiv(…, 60)` で分に |
| 重なりは「切り落とす → 並べる → まとめる」 | 昼休憩と休憩を二重に引かない |
| 「無い」と「0」を分ける | `dailyWorkMinutes()` は `null` と `0` を返し分ける |
| 探す前に、キーで引ける形にする | `$byDate`・`$requestsByDate` |
| 表示の作り方は共有する | `attendanceView()`・`formatClock()`・`requestView()` を使い回す |
| 判断は PHP で済ませる | `canRequest`・`workTime` をセルに入れて渡す |
| DB を使わない関数に分ける | 配列を受け取るだけなので、`tests/RecordsTest.php` でテストできる |

## 関連するファイル

- 呼ぶ側：`records/index.php`（記録表示の画面） → [records_index.md](./records_index.md)
- 呼ばれる側：
  - `src/holidays.php`（`japaneseHolidays()`・`HOLIDAYS_MIN_YEAR`） → [src_holidays.md](./src_holidays.md)
  - `src/attendance.php`（`attendanceView()`・`formatClock()`） → [src_attendance.md](./src_attendance.md)
  - `src/requests.php`（`requestView()`・`requestBadgeStatus()`・`hasPendingCorrection()`） → [src_requests.md](./src_requests.md)
- セルの値を使う JavaScript：`js/records.js` → [js_records.md](./js_records.md)
- 労働時間のルール：[決定事項.md](../../決定事項.md) の D-049・D-061・D-067
- テスト：`tests/RecordsTest.php`（月の決め方、労働時間の例、2026年9月のカレンダー）
