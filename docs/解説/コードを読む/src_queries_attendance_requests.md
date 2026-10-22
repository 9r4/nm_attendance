# `src/queries/attendance_requests.php` を読む（申請の表の読み書き）

## このファイルの役割

申請の表 `attendance_requests` に対する **SQL をまとめたファイル**。社員が申請を出す・見る・取り消す（PR 5）と、管理者が一覧を見て承認・却下する（PR 6）の両方を受け持つ。

- このファイルには、このシステムで一番いろいろな SQL が出てくる：たくさんの値の INSERT、`NULL` を渡す、`DELETE` と `rowCount()`、`JOIN` と `LEFT JOIN`、同じ表を2回つなぐ、条件によって足す SQL、`LIMIT`、`SELECT … FOR UPDATE`。
- 「申請してよいか」「承認してよいか」は決めない。決めるのは `src/requests.php` と `src/approvals.php`。
- 関数の中には、依頼者が書く前の手順を書いた `TODO` のコメントが残っている。下の引用では、その `TODO` のコメント行を省いている。

## 全体の構成

| 関数 | SQL の種類 | 呼ぶ側 |
| --- | --- | --- |
| `insertCorrectionRequest()` | INSERT（値 11 個） | `src/requests.php` の `submitCorrectionRequest()` |
| `insertOvertimeRequest()` | INSERT | `src/requests.php` の `submitOvertimeRequest()` |
| `fetchMonthlyRequests()` | SELECT（複数行） | `records/index.php`、`src/requests.php` の `pendingStatesOf()`（`index.php`・`api/attendance.php` から） |
| `deletePendingRequest()` | DELETE | `src/requests.php` の `submitCancelRequest()` |
| `fetchPendingRequests()` | SELECT（JOIN・LEFT JOIN） | `approvals/index.php` |
| `fetchProcessedRequests()` | SELECT（同じ表を2回 JOIN・LIMIT） | `approvals/index.php` |
| `fetchRequestForUpdate()` | SELECT … FOR UPDATE | `src/approvals.php` の `reviewRequest()`（トランザクションの中） |
| `updateRequestStatus()` | UPDATE | `reviewRequest()`（トランザクションの中） |

上の4つが社員の操作（PR 5。`deletePendingRequest()` だけは、あとから足した PR 5 の補足）、下の4つが管理者の操作（PR 6）。

---

## 1. ファイルの先頭と、`attendance_requests` 表の形

```php
// 申請（attendance_requests テーブル）を読み書きする関数
// 値は bindValue で渡す（D-060）。社員 ID・日付・日時・理由は、どれも文字列なので、型は PDO::PARAM_STR
// NULL の値（空欄の時刻、理由なし）も、そのまま bindValue(…, PDO::PARAM_STR) で渡せば、DB に NULL が入る
```

表の形（`docs/db/schema.sql` から、必要なところだけ）。

```sql
CREATE TABLE attendance_requests (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id          VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '申請者',
  target_date            DATE       NOT NULL COMMENT '対象日',
  request_type           ENUM('correction','overtime') NOT NULL …,
  requested_check_in_at  DATETIME   NULL …,     -- 申請の値（requested_ で始まる5列）
  …
  original_check_in_at   DATETIME   NULL …,     -- 申請した時点の勤怠の値（original_ で始まる5列）
  …
  reason                 VARCHAR(500) NULL …,
  status                 ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' …,
  reviewer_id          VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL …,
  review_comment         VARCHAR(500) NULL …,
  reviewed_at            DATETIME   NULL …,
  pending_key            TINYINT(1) GENERATED ALWAYS AS (IF(status = 'pending', 1, NULL)) STORED,
  …
  UNIQUE KEY uq_requests_pending (employee_id, target_date, request_type, pending_key),
  KEY idx_requests_status (status, created_at),
  KEY idx_requests_employee_date (employee_id, target_date),
  CONSTRAINT fk_requests_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT fk_requests_reviewer FOREIGN KEY (reviewer_id) REFERENCES employees (employee_id),
  CONSTRAINT chk_requests_not_self_review CHECK (reviewer_id IS NULL OR reviewer_id <> employee_id)
) …
```

この表には、SQL を読むときに効いてくる仕掛けが3つある。

- **`pending_key` と `uq_requests_pending`（BR-002：申請中の同じ申請は1件まで）**。`pending_key` は、`status` が `'pending'` なら `1`、それ以外なら `NULL` になる**生成列**（自分では書き込まず、DB が計算する列）。一意制約は「`NULL` どうしは同じとみなさない」ので、承認・却下された申請（`NULL`）は何件あってもよく、申請中（`1`）だけが「社員×日×種別」で1件に絞られる。
- **CHECK 制約 `chk_requests_not_self_review`**：処理した管理者（`reviewer_id`）が申請者と同じ行は、DB が受け付けない（自分の申請を自分で承認できない）。
- **2つの外部キー**：申請者も、処理した管理者も、`employees` にいる社員でなければならない。

社員 ID が文字列（`PDO::PARAM_STR`）である理由は、[src_queries_attendances.md](./src_queries_attendances.md) 1章と同じ（`2026A01` があるため）。

## 2. `insertCorrectionRequest()`：修正・新規登録の申請を保存する

```php
function insertCorrectionRequest(PDO $pdo, string $employeeId, string $targetDate, array $requested, array $original, ?string $reason): void {
    $sql = 'INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at, requested_check_out_at, requested_break_start_at, requested_break_end_at, original_check_in_at, original_check_out_at, original_break_start_at, original_break_end_at, reason) VALUES (:employee_id, :target_date, \'correction\', :requested_check_in_at, :requested_check_out_at, :requested_break_start_at, :requested_break_end_at, :original_check_in_at, :original_check_out_at, :original_break_start_at, :original_break_end_at, :reason)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
    $stmt->bindValue(':requested_check_in_at', $requested['check_in_at'], PDO::PARAM_STR);
    …
    $stmt->bindValue(':original_break_end_at', $original['break_end_at'], PDO::PARAM_STR);
    $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 列は 12 個、プレースホルダは 11 個。`request_type` だけは **SQL に `'correction'` と直接書いている**。利用者の入力ではなく、この関数で決まっている値なので、穴にしなくてよい（直接書いても SQL インジェクションの心配がない）。
- PHP の文字列を `'…'` で囲んでいるので、中の `'` は `\'` と書く（エスケープ）。MySQL に届く SQL は `'correction'` になる。
- `$requested` と `$original` は、どちらも `['check_in_at' => …, 'check_out_at' => …, 'break_start_at' => …, 'break_end_at' => …]` の形。値は日時か `null`。**`null` を `PDO::PARAM_STR` で渡すと `NULL` が入る**ので、「なし」の時刻も、理由の無い申請（`$reason` が `null`。D-041）も、同じ書き方で済む。
- 書かない列は DB が決める：`status` は既定値の `'pending'`、`pending_key` は生成列なので `1`、`created_at`（申請日時）は今の日時。
- **申請中の同じ申請（同じ社員・同じ日・修正）がすでにあると、`uq_requests_pending` の違反（1062）になる**。受け止めるのは呼ぶ側の `submitCorrectionRequest()`（`isDuplicateError()` で 1062 かを見て、「この日には、申請中の申請があります。…」の文にする）。
- トランザクションは使っていない（自動コミット）。

> **ここが重要**：`original_*`（申請した時点の勤怠の値）を、申請の行に**写して残している**。承認すると勤怠は申請の値で上書きされるので、あとから「変更前はいくつだったか」は勤怠の表からは分からなくなる。申請の行に控えておけば、承認・却下のあとも「変更前 → 申請値」を出せる。

## 3. `insertOvertimeRequest()`：簡単残業申請を保存する

```php
function insertOvertimeRequest(PDO $pdo, string $employeeId, string $targetDate, string $overtimeEndAt, ?string $originalOvertimeEndAt): void {
    $sql = 'INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_overtime_end_at, original_overtime_end_at) VALUES (:employee_id, :target_date, \'overtime\', :requested_overtime_end_at, :original_overtime_end_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
    $stmt->bindValue(':requested_overtime_end_at', $overtimeEndAt, PDO::PARAM_STR);
    $stmt->bindValue(':original_overtime_end_at', $originalOvertimeEndAt, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 2章と同じ形。`request_type` は `'overtime'`。修正の4つの時刻の列と `reason` は書かないので `NULL` になる（簡単残業申請は理由なし。D-041）。
- 一意制約には `request_type` が入っているので、**同じ日に「修正の申請中」と「残業の申請中」は1件ずつ並べられる**。残業の申請中が2件目になると 1062 で、`submitOvertimeRequest()` が受け止める。

## 4. `fetchMonthlyRequests()`：期間の申請を全部読む

```php
function fetchMonthlyRequests(PDO $pdo, string $employeeId, string $fromDate, string $toDate): array {
    $sql = 'SELECT * FROM attendance_requests WHERE employee_id = :employee_id AND target_date BETWEEN :from_date AND :to_date ORDER BY target_date, created_at, id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

- 形は `fetchMonthlyAttendances()` と同じ（`BETWEEN` は両端を含む、`fetchAll()` は行が無ければ `[]`）。条件の `employee_id` と `target_date` は、索引 `idx_requests_employee_date` の並びと同じなので、この索引で探せる。
- **`ORDER BY target_date, created_at, id`**：日付の順、同じ日なら申請した順。`created_at` は秒までしか持たないので、同じ秒に2件あると順番が決まらない。最後に `id`（必ず違う値）を足して、**順番を必ず1通りに決めている**。呼ぶ側の `requestBadgeStatus()` は「最後の行＝最新の申請」として使うので、順番が大事。
- 呼ぶ側は2つ。記録表示（`records/index.php`）は月の初日〜末日を渡す。`pendingStatesOf()` は `$date, $date`（初日と末日に同じ日）を渡して、**1日分の申請**を読む。読んだ1回分の行から、今日の修正が申請中か（メイン画面の打刻を止める。D-063）と、今日の残業申請が申請中か（簡単残業申請のボタンを止める。D-069）の両方を調べる。

> **注意（決まりと違うところ）**：ここだけ **`SELECT *`** になっている。`TODO` の手順では、使う 17 列を書く形だった。`*` だと、画面で使わない `employee_id`・`reviewer_id`・`reviewed_at`・`pending_key`・`updated_at` も返る。いまは害はない（呼ぶ側は使う列だけを読む）が、表に列が増えると戻り値も黙って増える。ほかの関数と同じく、列を書く形にそろえるとよい。

## 5. `deletePendingRequest()`：自分の申請中の申請を取り消す

```php
// 自分の申請中の申請を、取り消す（削除する）。削除できたら true（本人の申請中のものが無ければ false）
// D-063：この関数は、依頼者の了承を得て Claude が書いた
function deletePendingRequest(PDO $pdo, int $requestId, string $employeeId): bool {
    // 本人（employee_id）と申請中（status = 'pending'）を条件に付けて、ほかの人の申請や、承認・却下された申請を消さない
    $sql = "DELETE FROM attendance_requests WHERE id = :id AND employee_id = :employee_id AND status = 'pending'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $requestId, PDO::PARAM_INT);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount() === 1;
}
```

- **この関数は、`src/queries/` の中で例外的に Claude が書いた**（D-063。申請中の打刻の制限と申請の取り消しの件は、DB の関数も含めて Claude が実装することを、依頼者が了承した）。そのため `TODO` のコメントが無い。
- 申請の `id` は数（`BIGINT`）なので **`PDO::PARAM_INT`**。社員 ID とは違い、こちらは本当に数。呼ぶ側の `submitCancelRequest()` が、画面から来た文字列を正規表現 `/\A[1-9]\d{0,18}\z/` で確かめてから `(int)` で数にして渡す。
- 条件が3つある。`id` だけで消すと、**画面の `request_id` を書き換えて、ほかの人の申請や、処理済みの申請を消せてしまう**。本人（`employee_id`）と申請中（`status = 'pending'`）を SQL の条件に入れて、DB の側で絞っている。
- PHP の文字列を `"…"` で囲んでいるので、中の `'pending'` はそのまま書ける（2章の `\'` が要らない）。
- **`rowCount()`** は、DELETE・UPDATE が**実際に変えた行の数**を返す。条件に合う行が無ければ `0`。「消えたか」を `true`／`false` で返し、`false` なら呼ぶ側が「すでに承認・却下されたか、取り消されています」と伝える。

> **ここが重要**：管理者が承認している最中に、本人が取り消すとどうなるか。承認のトランザクションは申請の行を `FOR UPDATE` でロックしている（8章）ので、この DELETE は**ロックが外れるまで待たされる**。承認が確定したあとに DELETE が動くと、もう `status` が `'pending'` ではないので、0行（`false`）になる。逆に取り消しが先なら、承認の側が「見つかりません」になる。**条件に `status = 'pending'` を入れておくだけで、どちらの順でも正しく終わる**。

## 6. `fetchPendingRequests()`：承認範囲の、申請中の申請を読む

ここからは管理者の関数（PR 6）。区切りのコメントがある。

```php
// ---- PR 6（承認）：管理者が使う関数 ----
// 申請の ID・件数は数値なので PDO::PARAM_INT、それ以外は PDO::PARAM_STR
```

```php
function fetchPendingRequests(PDO $pdo, string $approverId, ?int $departmentId): array {
    $sql = 'SELECT r.id, r.employee_id, r.target_date, r.request_type, r.requested_check_in_at, … r.reason, r.created_at, e.name, d.name AS department_name, a.check_out_at AS current_check_out_at FROM attendance_requests r JOIN employees e ON e.employee_id = r.employee_id JOIN departments d ON d.id = e.department_id LEFT JOIN attendances a ON a.employee_id = r.employee_id AND a.work_date = r.target_date WHERE r.status = \'pending\' AND r.employee_id <> :approver_id';
    if ($departmentId !== null) {
        $sql .= ' AND e.department_id = :department_id';
    }
    $sql .= ' ORDER BY r.created_at, r.id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':approver_id', $approverId, PDO::PARAM_STR);
    if ($departmentId !== null) {
        $stmt->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

SQL は1行で書かれていて長いので、形を整えて読む。

```sql
SELECT r.id, …, e.name, d.name AS department_name, a.check_out_at AS current_check_out_at
FROM attendance_requests r
JOIN employees e        ON e.employee_id = r.employee_id
JOIN departments d      ON d.id = e.department_id
LEFT JOIN attendances a ON a.employee_id = r.employee_id AND a.work_date = r.target_date
WHERE r.status = 'pending' AND r.employee_id <> :approver_id
  [AND e.department_id = :department_id]   ← 承認範囲が「部署」のときだけ
ORDER BY r.created_at, r.id
```

- **`r`・`e`・`d`・`a` は表の別名**。列を `r.id` のように「どの表の列か」まで書く。`employees` にも `departments` にも `name` があるので、部署名は `d.name AS department_name` と別の名前を付けて、配列のキーがぶつからないようにしている。
- **`JOIN`（内部結合）**：両方の表に相手がいる行だけを残す。申請には必ず申請者がいて（外部キー）、社員には必ず部署がある（`department_id` は `NOT NULL` で外部キー）ので、ここでは `JOIN` で行が消えることはない。
- **`LEFT JOIN`（左外部結合）**：左の表（申請）の行は、右（勤怠）に相手が無くても残す。相手が無いときは、右の列が `NULL` になる。**新規登録の申請は、その日の勤怠が無い**ので、`JOIN` にすると一覧から消えてしまう。だから勤怠だけ `LEFT JOIN`。
- `LEFT JOIN` の `ON` には、条件を2つ（社員と日付）書いている。勤怠は「1人1日1行」なので、相手は0行か1行。申請の行が増えることはない。
- `current_check_out_at`（その日の今の退勤時刻）は、残業の申請を判断する材料として、承認画面に出す。
- **`r.employee_id <> :approver_id`**：自分の申請は一覧に出さない。
- 部署の条件は、**申請者の今の部署**（`e.department_id`）で見ている。申請したあとに部署が変わった社員は、新しい部署の管理者の一覧に出る。

### 条件によって SQL を足す

- `$departmentId` が `null`（承認範囲が「全社」）なら、部署の条件を付けない。`null` でなければ `.=` で SQL の後ろに足す。
- **足すのは決まった文字列だけ**で、値（部署 ID）は必ずプレースホルダで渡している。`$sql .= ' AND e.department_id = ' . $departmentId;` のように値を文字列でつなぐと、SQL インジェクションの入り口になる。
- `bindValue` も、SQL に足したときだけ呼ぶ。**SQL に無いプレースホルダを `bindValue` すると、その `bindValue()` の時点で「そのパラメータは定義されていない」（SQLSTATE HY093）のエラーになる**（この環境の設定：`ATTR_EMULATE_PREPARES => false`）。だから `if` が2か所に必要。
- 部署 ID は `INT` の列なので **`PDO::PARAM_INT`**。

## 7. `fetchProcessedRequests()`：承認範囲の、処理済みの申請を読む

```php
function fetchProcessedRequests(PDO $pdo, string $approverId, ?int $departmentId, int $limit): array {
    $sql = 'SELECT r.id, r.employee_id, r.target_date, … r.reason, r.created_at, e.name, d.name AS department_name, r.status, r.review_comment, r.reviewed_at, rv.name AS reviewer_name FROM attendance_requests r JOIN employees e ON e.employee_id = r.employee_id JOIN departments d ON d.id = e.department_id LEFT JOIN employees rv ON rv.employee_id = r.reviewer_id WHERE r.status <> \'pending\' AND r.employee_id <> :approver_id';
    if ($departmentId !== null) {
        $sql .= ' AND e.department_id = :department_id';
    }
    $sql .= ' ORDER BY r.reviewed_at DESC, r.id DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':approver_id', $approverId, PDO::PARAM_STR);
    if ($departmentId !== null) {
        $stmt->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

形は6章と同じ。違うところだけ見る。

- **同じ表 `employees` を2回つないでいる**。`e` は申請者、`rv` は処理した管理者。別名を変えれば、同じ表を何回でもつなげる。`rv.name AS reviewer_name` で、管理者の氏名に別の名前を付ける。
- 処理した管理者は `LEFT JOIN`。`reviewer_id` は `NULL` を許す列なので、`JOIN` にすると、もし `NULL` の行があれば消えてしまう。処理済みの申請には `updateRequestStatus()` が必ず `reviewer_id` を入れるので、実際には `JOIN` でも同じ結果になるはずだが、**`NULL` になりうる列でつなぐときは `LEFT JOIN` にしておく**と、行が黙って消える心配がない。
- 勤怠（`a`）はつながない。処理済みの一覧では、今の退勤時刻を出さないため。
- **`WHERE r.status <> 'pending'`**：承認済みと却下の両方。
- **`ORDER BY r.reviewed_at DESC, r.id DESC`**：処理した日時の新しい順（`DESC` は大きい順）。同じ秒に処理したものは `id` で順番を決める。
- **`LIMIT :limit`**：先頭から `$limit` 件だけ返す。呼ぶ側は `APPROVAL_PROCESSED_LIMIT`（50）を渡す（D-064）。

> **ここが重要**：`LIMIT` の値は **`PDO::PARAM_INT`** で渡す。この環境は `ATTR_EMULATE_PREPARES => false` なのでたまたま `PARAM_STR` でも動くが、エミュレーション（PDO が値を SQL の文字に埋め込む方式）が有効な環境では、`PARAM_STR` だと `LIMIT '50'` になって SQL の文法エラーになる。数として使う値は、型も数で渡す。

## 8. `fetchRequestForUpdate()`：申請を1件読んで、行をロックする

```php
function fetchRequestForUpdate(PDO $pdo, int $requestId): ?array {
    $sql = 'SELECT r.id, r.employee_id, r.target_date, r.request_type, r.requested_check_in_at, r.requested_check_out_at, r.requested_break_start_at, r.requested_break_end_at, r.requested_overtime_end_at, r.status, e.name, e.department_id FROM attendance_requests r JOIN employees e ON e.employee_id = r.employee_id WHERE r.id = :id FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $requestId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
```

- 末尾の **`FOR UPDATE`** が、この関数の中心。読んだ行に**排他ロック**をかける。ロックはトランザクションが終わる（`commit()` か `rollBack()`）まで続き、その間、ほかの接続はこの行を変えたり、`FOR UPDATE` で読んだりできずに**待たされる**。
- だから、この関数は**トランザクションの中で呼ばないと意味がない**。自動コミットのままだと、SELECT が終わった瞬間にロックが外れる。トランザクションを始めるのは `reviewRequest()`（[src_queries_attendances.md](./src_queries_attendances.md) 6章の流れ）。
- 主キー `id` で探すので、結果は0行か1行。見つからなければ `null`（取り消された申請など）。
- `e.department_id` を読むのは、呼ぶ側の `canReview()` が「この申請者は、管理者の承認範囲の部署か」を確かめるため。

### 2人の管理者が、同時に同じ申請を承認したら

```text
管理者A：beginTransaction → FOR UPDATE で読む（status = pending）→ 反映 → 承認済みに → commit
管理者B：beginTransaction → FOR UPDATE で読む … A の commit まで待つ → 読めたときは status = approved
         → reviewRequest() が「すでに処理されています」で rollBack
```

- B は A が終わるまで待たされ、待ったあとで読むのは **A が確定させた最新の値**（`FOR UPDATE` のような「ロックをかける読み方」は、常に最新の確定した値を読む）。だから B は「申請中」と思い込んで二重に反映することがない。
- 細かい話：`JOIN` しているので、ロックは**申請の行だけでなく、つないだ `employees` の行にもかかる**（MySQL は、`FOR UPDATE` のときに読んだ全部の表の行をロックする）。承認している短い間、その社員の行の更新（アカウントの無効化など）が待たされる。申請の行だけをロックしたいときは、MySQL 8 では `FOR UPDATE OF r` と書ける。いまは困ることは無いので、そのままでよい。

> **ここが重要**：「読んでから、その結果で判断して、書く」処理を、ほかの人と同時に行うと、判断のもとになった値が、書く前に変わってしまうことがある。**読む時点でロックしておけば、書き終わるまで誰も変えられない**。これが `SELECT … FOR UPDATE` を使う理由。

## 9. `updateRequestStatus()`：申請を承認済み・却下にする

```php
function updateRequestStatus(PDO $pdo, int $requestId, string $status, string $reviewerId, ?string $comment): void {
    $sql = 'UPDATE attendance_requests SET status = :status, reviewer_id = :reviewer_id, review_comment = :review_comment, reviewed_at = NOW() WHERE id = :id AND status = \'pending\'';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':reviewer_id', $reviewerId, PDO::PARAM_STR);
    $stmt->bindValue(':review_comment', $comment, PDO::PARAM_STR);
    $stmt->bindValue(':id', $requestId, PDO::PARAM_INT);
    $stmt->execute();
}
```

- `reviewed_at = NOW()`：処理した日時は、PHP ではなく **DB の今の日時**で入れる。接続のときに `SET time_zone = '+09:00'` をしているので、日本時間になる。
- `$status` には `'approved'` か `'rejected'` が来る（呼ぶ側の `reviewRequest()` が決めた値）。`status` は `ENUM` の列なので、それ以外の文字列を入れようとすると、MySQL 8.4 の既定の厳格モードではエラーになる。
- `$comment` は却下コメントか `null`（承認のときは `null`）。`null` はそのまま `NULL` で入る。
- **`AND status = 'pending'`**：申請中のものだけを更新する。直前に `FOR UPDATE` で確かめているので、本当はここで当たらないことは無いが、**二重に処理しないための念押し**として付けている。
- `status` が変わると、生成列 `pending_key` が `NULL` になる。これで一意制約 `uq_requests_pending` の「申請中は1件」の枠が空き、社員は同じ日に新しい申請を出せるようになる。
- DB の制約も、最後の守りになっている。`reviewer_id` が申請者と同じなら CHECK 制約 `chk_requests_not_self_review` の違反（エラー番号 3819）、`employees` にいない ID なら外部キー `fk_requests_reviewer` の違反になる。アプリでも `canReview()` で確かめているので、ふつうは起きない。
- 呼ばれるのは `reviewRequest()` のトランザクションの中。承認のときは勤怠への反映（`upsertAttendanceTimes()` か `upsertOvertimeEnd()`）のあと、却下のときはこの UPDATE だけ。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 決まった値は SQL に直接書く | `request_type` の `'correction'`・`'overtime'` |
| `NULL` もそのまま渡す | 空欄の時刻、理由なし、却下コメントなし |
| 変更前の値を控えておく | `original_*` の列に、申請した時点の勤怠を写す |
| 一意制約と生成列で「申請中は1件」 | `pending_key` と `uq_requests_pending`。違反（1062）は呼ぶ側が受け止める |
| 並び順を1通りに決める | `ORDER BY …, created_at, id` |
| DELETE・UPDATE の結果を `rowCount()` で見る | `deletePendingRequest()` の `true`／`false` |
| 条件で、利用者が触れない範囲に絞る | 取り消しは「本人の」「申請中の」申請だけ |
| 相手が無くても残すなら `LEFT JOIN` | 新規登録の申請（勤怠が無い）、処理した管理者 |
| 同じ表を別名で2回つなぐ | 申請者 `e` と、処理した管理者 `rv` |
| SQL を足すときも、値はプレースホルダ | 部署の条件と、`bindValue` を同じ `if` で足す |
| 数は `PDO::PARAM_INT` | 申請の `id`、部署 ID、`LIMIT` |
| 読んでから書くならロック | `SELECT … FOR UPDATE` と、呼ぶ側のトランザクション |

決まりと違うところ：`fetchMonthlyRequests()` は `SELECT *`（4章）。`deletePendingRequest()` は Claude が書いた（D-063。5章）。

## 関連するファイル

- 呼ぶ側：`src/requests.php`（[src_requests.md](./src_requests.md)）、`src/approvals.php`（[src_approvals.md](./src_approvals.md)）、`records/index.php`（[src_records.md](./src_records.md)）、`approvals/index.php`
- 承認のトランザクションで一緒に使う SQL：`src/queries/attendances.php`（[src_queries_attendances.md](./src_queries_attendances.md)）
- 申請者・管理者の表：`src/queries/employees.php`（[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md)）
- 表の定義：`docs/db/schema.sql`、[DB設計書.md](../../DB設計書.md)
- 仕組みの説明：[04_DBとSQL.md](../04_DBとSQL.md)（JOIN と LEFT JOIN、FOR UPDATE、生成列 `pending_key`）
- テスト：`tests/ApprovalsDbTest.php`（一覧の範囲、承認・却下、処理済み・権限なしの扱い）
