# `src/queries/attendances.php` を読む（勤怠の表の読み書き）

## このファイルの役割

勤怠の表 `attendances` に対する **SQL をまとめたファイル**。出勤・退勤・休憩の記録、記録表示のための月の読み出し、承認したときの反映を受け持つ。

- 「押せるか」「どの関数を呼ぶか」は決めない。決めるのは呼ぶ側（`src/attendance.php`・`src/approvals.php` など）で、このファイルは**言われたとおりに読む・書くだけ**。
- どの関数も「`prepare` → `bindValue` → `execute` →（読むときは）`fetch`／`fetchAll`」の同じ形をしている。1つ目の `fetchAttendance()` を理解すれば、残りは違うところだけ読めばよい。
- 関数の中には、依頼者が書く前の手順を書いた `TODO` のコメントが残っている。下の引用では、その `TODO` のコメント行を省いている（手順はコードそのものなので、コードで読む）。

## 全体の構成

| 関数 | SQL の種類 | 呼ぶ側 |
| --- | --- | --- |
| `fetchAttendance()` | SELECT（1行） | `src/attendance.php` の `performAttendanceAction()`、`src/requests.php` の `submitCorrectionRequest()`・`submitOvertimeRequest()`、`index.php` |
| `insertCheckIn()` | INSERT | `performAttendanceAction()`（出勤・その日の行が無いとき） |
| `updateCheckIn()`・`updateCheckOut()`・`updateBreakStart()`・`updateBreakEnd()` | UPDATE | `performAttendanceAction()` |
| `fetchMonthlyAttendances()` | SELECT（複数行） | `records/index.php`（記録表示） |
| `upsertAttendanceTimes()` | INSERT … ON DUPLICATE KEY UPDATE | `src/approvals.php` の `reviewRequest()`（トランザクションの中） |
| `upsertOvertimeEnd()` | INSERT … ON DUPLICATE KEY UPDATE | `reviewRequest()`（トランザクションの中） |

上の6つ（`fetchAttendance()` と、出勤・退勤・休憩の記録）は PR 3（メイン画面）、`fetchMonthlyAttendances()` は PR 4（記録表示）、下の2つ（承認の反映）は PR 6 で書いた。`fetchAttendance()` には、PR 5 で列を1つ足した。下へ行くほど、SQL が少しずつ難しくなる。

---

## 1. ファイルの先頭と、`attendances` 表の形

```php
// 勤怠（attendances テーブル）を読み書きする関数
// 引数の形：$workDate は '2026-10-15'、時刻（$checkInAt など）は '2026-10-15 09:01:00'（どちらも呼び出し側が作って渡す）
// 値は bindValue で渡す（D-060）。社員 ID・日付・日時は、どれも文字列なので、型は PDO::PARAM_STR
```

SQL を読む前に、相手の表の形を見ておく（`docs/db/schema.sql` から、必要なところだけ）。

```sql
CREATE TABLE attendances (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '社員コード',
  work_date      DATE         NOT NULL COMMENT '勤務日',
  check_in_at    DATETIME     NULL COMMENT '出勤時刻',
  …
  overtime_end_at DATETIME    NULL COMMENT '承認済みの残業終了時刻',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id)
) …
```

- **`UNIQUE KEY uq_attendance_employee_date (employee_id, work_date)`** が、このファイルで一番大事な行。「1人1日1行」（BR-005）を DB が守る。このファイルのほとんどの SQL が、この2つの列を条件にしている。一意制約は**索引（インデックス）も兼ねる**ので、この2列で探す SQL は速い。
- **社員 ID（`employee_id`）は `VARCHAR(7)` の文字列**。社員コードは `2026001` のほかに `2026A01` のような形もある（D-013）ので、数ではない。だから `bindValue` の型は `PDO::PARAM_STR` にする。見た目が数字だからといって `PARAM_INT` にすると、`2026A01` を正しく渡せない。
- `COLLATE ascii_bin` は「文字コードの値そのもので比べる」という意味。大文字・小文字も区別し、完全に同じ文字列だけが一致する。
- 日付（`DATE`）・日時（`DATETIME`）も、PHP からは `'2026-10-15'`・`'2026-10-15 09:01:00'` の**文字列で渡し、文字列で返ってくる**。MySQL がこの形の文字列を日付として読み取ってくれる。

`src/dbconnect.php` の接続の設定も、このファイルの書き方に関係する。

| 設定 | 意味 | このファイルへの影響 |
| --- | --- | --- |
| `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` | SQL が失敗したら例外を投げる | `execute()` の戻り値を調べなくてよい。失敗すれば例外で止まる |
| `PDO::ATTR_EMULATE_PREPARES => false` | SQL と値を MySQL 側で分けて扱う（本物のプリペアドステートメント） | 値は SQL の文字として埋め込まれない。数の列は PHP の `int` で返ってくる |
| `SET time_zone = '+09:00'` | 日本時間で動かす | `NOW()` や `CURRENT_TIMESTAMP`（`created_at` など）が日本時間になる |

## 2. `fetchAttendance()`：ある日の勤怠を1行読む

```php
function fetchAttendance(PDO $pdo, string $employeeId, string $workDate): ?array {
    $sql = 'SELECT check_in_at, check_out_at, break_start_at, break_end_at, overtime_end_at FROM attendances WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
```

4つの手順を、1つずつ見る。

1. **`prepare()`**：SQL の「形」だけを MySQL に渡して準備させる。`:employee_id` のような `:名前` は**プレースホルダ**（あとで値を入れる穴）。
2. **`bindValue()`**：穴に値を入れる。3つ目の引数で型を伝える。値は SQL の文字列に混ぜられないので、利用者の入力に `'` などが入っていても、SQL の意味は変わらない（SQL インジェクション対策）。
3. **`execute()`**：実行する。
4. **`fetch(PDO::FETCH_ASSOC)`**：結果を1行、`['check_in_at' => …, …]` の連想配列で取り出す。行が無ければ **`false`** を返す。

- 最後の行で `false` を `null` に変えている。戻り値の型が `?array`（配列か `null`）なので、`false` のまま返すと型のエラーになる。また、呼ぶ側の `isOnBreak()` などは「行が無い＝`null`」で書かれているので、ここで形をそろえる。
- 条件の2列は一意制約の2列なので、**結果は0行か1行**。だから `fetchAll()` ではなく `fetch()` で足りる。
- `PDO::FETCH_ASSOC` は `dbconnect.php` で既定にしてあるので、本当は省いても同じ。ここでは明示している（害はない）。
- `overtime_end_at` は PR 5 で足した列。簡単残業申請で「申請した時点の残業終了時刻」を控えるために読む（`submitOvertimeRequest()` が `insertOvertimeRequest()` に渡す）。

> **ここが重要**：`SELECT` のあとに**必要な列だけ**を書いている（`SELECT *` にしない）。`id`・`created_at` などは画面で使わないので読まない。列を書いておくと、「この関数が何を返すか」が SQL を見るだけで分かり、表に列が増えても戻り値が変わらない。

## 3. `insertCheckIn()`：その日の行を作って、出勤を記録する

```php
function insertCheckIn(PDO $pdo, string $employeeId, string $workDate, string $checkInAt): void {
    $sql = 'INSERT INTO attendances (employee_id, work_date, check_in_at) VALUES (:employee_id, :work_date, :check_in_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 書いた列は3つだけ。残りの列は DB が決める：`id` は `AUTO_INCREMENT` で連番、`created_at`・`updated_at` は `DEFAULT CURRENT_TIMESTAMP` で今の日時、退勤・休憩・残業の列は `NULL`。
- 戻り値は `void`（何も返さない）。INSERT は結果の行が無いので、`fetch()` もしない。
- **同じ日の行がすでにあると、一意制約 `uq_attendance_employee_date` の違反で例外（エラー番号 1062）になる**。この関数では受け止めず、呼ぶ側の `performAttendanceAction()` が 1062 のときだけ受け止めて「別の画面で、すでに出勤が記録されています」にする（[src_attendance.md](./src_attendance.md) 7章）。
- 外部キー `fk_attendance_employee` があるので、`employees` に無い社員 ID では行を作れない（ログイン中の社員なので、ふつうは起きない）。

> **ここが重要**：SQL の関数では `try catch` を書かない。エラーを「どんな文で利用者に伝えるか」は画面ごとに違うので、決めるのは呼ぶ側（D-036）。SQL の関数は、失敗したら例外をそのまま上へ流す。

`performAttendanceAction()` はトランザクションを使っていない。PDO は何もしなければ**自動コミット**（1つの SQL ごとに、すぐ確定する）なので、この INSERT も実行した時点で確定する。

## 4. 4つの UPDATE：出勤・退勤・休憩開始・休憩終了

```php
function updateCheckIn(PDO $pdo, string $employeeId, string $workDate, string $checkInAt): void {
    $sql = 'UPDATE attendances SET check_in_at = :check_in_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);
    $stmt->execute();
}
```

残りの3つは、**列の名前とプレースホルダの名前だけが違う**。SQL だけ並べる。

```php
    $sql = 'UPDATE attendances SET check_out_at = :check_out_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $sql = 'UPDATE attendances SET break_start_at = :break_start_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $sql = 'UPDATE attendances SET break_end_at = :break_end_at WHERE employee_id = :employee_id AND work_date = :work_date';
```

- `WHERE` の2つの条件で、一意制約の1行だけを狙っている。**`WHERE` を忘れると、全員・全日の行が書き換わる**。UPDATE と DELETE では、`WHERE` を最初に確かめる癖をつける。
- `bindValue` を呼ぶ順番は、SQL の中の順番（`:check_in_at` が先）と違ってよい。名前付きのプレースホルダは、名前で対応するため。
- `updated_at` は書いていないが、`ON UPDATE CURRENT_TIMESTAMP` で DB が今の日時にしてくれる。ただし、**値が実際に変わったときだけ**。同じ時刻で上書きしたとき（1分以内に2回押した など）は、行が変わらないので `updated_at` もそのまま。
- **当てはまる行が無い UPDATE は、エラーにならない**（0行を更新して、黙って終わる）。この関数は結果を調べないので、「行がある」ことは呼ぶ側が保証している。`actionMode()` は、出勤していない日（行が無い日）には退勤・休憩を押せなくする。
- UPDATE の条件に「まだ退勤していない」（`check_out_at IS NULL`）などは付けていない。**押せるかの判断は `actionMode()` の1か所だけ**、という作りに合わせている（[src_attendance.md](./src_attendance.md) 4章）。2つのタブでほぼ同時に押すと、後に実行された UPDATE の時刻が残るが、行が2つになったり壊れたりはしない。

> **ここが重要**：出勤だけは「INSERT」と「UPDATE」の2つがある。その日の最初の操作は必ず出勤なので、行を作るのは出勤だけでよい。退勤・休憩は、出勤で作った行を UPDATE で埋めていく。1日の勤怠が「1行を少しずつ埋める」形になっているのは、表が「1人1日1行」だから。

## 5. `fetchMonthlyAttendances()`：期間の勤怠を、日付の順に全部読む

```php
function fetchMonthlyAttendances(PDO $pdo, string $employeeId, string $fromDate, string $toDate): array {
    $sql = 'SELECT work_date, check_in_at, check_out_at, break_start_at, break_end_at FROM attendances WHERE employee_id = :employee_id AND work_date BETWEEN :from_date AND :to_date ORDER BY work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

- **`BETWEEN A AND B` は両端を含む**（`A <= work_date AND work_date <= B` と同じ）。`records/index.php` は月の初日と末日（`'2026-10-01'` と `'2026-10-31'`）を渡すので、その月の全部の日が入る。
- `work_date` は `DATE`（時刻を持たない）なので、末日をそのまま渡せばよい。もし `DATETIME` の列に `BETWEEN '…-01' AND '…-31'` を使うと、31日の 0時0分より後が入らない。列の型によって書き方が変わるので注意。
- **`fetchAll()`** は全部の行を配列の配列で返す。行が無ければ **空の配列 `[]`**（`false` ではない）。だから `fetch()` のときのような `null` への変換は要らない。
- **`ORDER BY work_date`** を必ず付ける。付けないと、MySQL が返す順番は保証されない（たまたま日付順に見えても、当てにしてはいけない）。
- 条件の `employee_id` と `work_date` は、一意制約の索引の並び（`employee_id` → `work_date`）と同じ。MySQL はこの索引で「この社員の、この期間」をまとめて取り出せる。

`work_date` を返すのは、呼ぶ側（`src/records.php`）が日付をキーにしてカレンダーに並べるため。

## 6. `upsertAttendanceTimes()`：承認した申請の4つの時刻を反映する

ここからは PR 6（承認）の関数。ファイルの中にも区切りのコメントがある。

```php
// ---- PR 6（承認）：承認で勤怠に反映する関数（トランザクションの中で呼ぶ。トランザクションは呼び出し側が行う） ----
```

```php
function upsertAttendanceTimes(PDO $pdo, string $employeeId, string $workDate, array $times): void {
    $sql = 'INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at)
            VALUES (:employee_id, :work_date, :check_in_at, :check_out_at, :break_start_at, :break_end_at) AS new
            ON DUPLICATE KEY UPDATE check_in_at = new.check_in_at, check_out_at = new.check_out_at,
            break_start_at = new.break_start_at, break_end_at = new.break_end_at';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_in_at', $times['check_in_at'], PDO::PARAM_STR);
    $stmt->bindValue(':check_out_at', $times['check_out_at'], PDO::PARAM_STR);
    $stmt->bindValue(':break_start_at', $times['break_start_at'], PDO::PARAM_STR);
    $stmt->bindValue(':break_end_at', $times['break_end_at'], PDO::PARAM_STR);
    $stmt->execute();
}
```

修正の申請はその日に行がある、新規登録の申請はその日に行が無い。どちらでも1つの SQL で済ませるのが **`INSERT … ON DUPLICATE KEY UPDATE`**（「無ければ追加、あれば更新」。upsert とも呼ぶ）。

MySQL の中では、次のように動く。

1. まず INSERT しようとする。
2. **一意制約に当たらなければ**、そのまま行が追加される（新規登録）。
3. **一意制約に当たったら**、追加はやめて、すでにある行に `ON DUPLICATE KEY UPDATE` の後ろを実行する（修正）。

- 当たるのは `uq_attendance_employee_date (employee_id, work_date)`。もう1つの一意なもの（主キー `id`）は、値を渡していない（`AUTO_INCREMENT` で毎回新しい番号）ので当たらない。**「どの一意制約に当たるか」で動きが決まる SQL** なので、表の制約を必ず確かめる。
- **`AS new`** は、追加しようとした行に付けた別名。`new.check_in_at` は「今回 INSERT しようとした `check_in_at` の値」、つまり申請の値。MySQL 8.0.19 から使える書き方で、昔の `VALUES(check_in_at)` という書き方は非推奨になっている。
- UPDATE する列に `overtime_end_at` を書いていない。だから、承認済みの残業終了時刻は**そのまま残る**。新しく行を作るときは `NULL`。
- `$times` の値は、日時か `null`。**`null` を `PDO::PARAM_STR` で渡すと、DB には `NULL` が入る**（空の文字列 `''` にはならない）。申請で「なし」にした休憩などは、`NULL` で上書きされる。申請は「その日の4つの時刻の全体」を持つ（DB設計書の P-4）ので、4つを全部そろえて書き込めばよい。
- 細かい話：InnoDB では、UPDATE になったときも `AUTO_INCREMENT` の番号が1つ使われることがあり、`id` に欠番ができる。`id` に意味は持たせていないので、問題はない。

### トランザクションは呼ぶ側が持つ

この関数は `src/approvals.php` の `reviewRequest()` から、次の順に呼ばれる。

```text
beginTransaction()
  fetchRequestForUpdate()   … 申請を読んでロック（attendance_requests.php）
  upsertAttendanceTimes()   … 勤怠に反映（ここ）
  updateRequestStatus()     … 申請を「承認済み」に（attendance_requests.php）
commit()                    … 途中で例外が出たら rollBack()
```

> **ここが重要**：「勤怠への反映」と「申請を承認済みにする」は、**両方とも成功するか、両方とも取り消すか**でないと困る（勤怠だけ変わって申請が申請中のまま、などが起きる）。だから1つのトランザクションにまとめる。ただし、トランザクションを始める・終えるのは、手順の全体を知っている `reviewRequest()` の役目。SQL の関数の中で `beginTransaction()` を呼ぶと、ほかの関数と組み合わせられなくなる。

## 7. `upsertOvertimeEnd()`：承認した残業の終了時刻を反映する

```php
function upsertOvertimeEnd(PDO $pdo, string $employeeId, string $workDate, string $overtimeEndAt): void {
    $sql = 'INSERT INTO attendances (employee_id, work_date, overtime_end_at) VALUES (:employee_id, :work_date, :overtime_end_at) AS new
            ON DUPLICATE KEY UPDATE overtime_end_at = new.overtime_end_at';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':overtime_end_at', $overtimeEndAt, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 6章と同じ形で、触る列が `overtime_end_at` だけ。出勤・退勤・休憩の時刻は変えない。
- 簡単残業申請は「退勤が標準終了時刻より後の、今日」にしか出せない（`validateOvertime()`）ので、承認するときには、ふつうはその日の行がある（UPDATE になる）。それでも upsert の形にしておくと、行が無いときもエラーにならない。
- 引数 `$overtimeEndAt` の型は `?string` ではなく `string`。残業の申請は、終了時刻が必ずある。
- 呼ばれるのは `reviewRequest()` のトランザクションの中（6章と同じ）。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| SQL を実行する4つの手順 | `prepare` → `bindValue` → `execute` → `fetch`／`fetchAll` |
| 社員 ID は文字列 | `2026A01` があるので、型は `PDO::PARAM_STR` |
| 1行なら `fetch()`、複数行なら `fetchAll()` | 1行は `false` を `null` に変える。複数行は `[]` のまま返す |
| 必要な列だけを読む | `SELECT *` にせず、列を書く |
| UPDATE には必ず `WHERE` | 一意制約の2列で、1行だけを狙う |
| 当てはまらない UPDATE はエラーにならない | 行があることは、呼ぶ側の `actionMode()` が保証する |
| 「無ければ追加、あれば更新」 | `INSERT … AS new ON DUPLICATE KEY UPDATE`。当たる一意制約を確かめる |
| `null` もそのまま渡す | `bindValue(…, null, PDO::PARAM_STR)` で `NULL` が入る |
| エラーの扱いとトランザクションは呼ぶ側 | 1062 は `performAttendanceAction()`、承認のトランザクションは `reviewRequest()` |

## 関連するファイル

- 呼ぶ側：`src/attendance.php`（[src_attendance.md](./src_attendance.md)）、`src/requests.php`（[src_requests.md](./src_requests.md)）、`src/approvals.php`（[src_approvals.md](./src_approvals.md)）、`records/index.php`（[src_records.md](./src_records.md)）、`index.php`
- 一緒に使う SQL：`src/queries/attendance_requests.php`（[src_queries_attendance_requests.md](./src_queries_attendance_requests.md)。承認のトランザクションの前後）
- 接続の設定：`src/dbconnect.php`（[src_dbconnect.md](./src_dbconnect.md)）
- 表の定義：`docs/db/schema.sql`、[DB設計書.md](../../DB設計書.md)
- 仕組みの説明：[04_DBとSQL.md](../04_DBとSQL.md)（4つの手順、ON DUPLICATE KEY UPDATE、トランザクション）
- テスト：`tests/ApprovalsDbTest.php`（承認で勤怠に反映されるか）
