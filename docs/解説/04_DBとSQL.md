# 4. DB と SQL

主なファイル：`src/queries/*.php`（依頼者が書いた部分）、`docs/db/schema.sql`

## 4-1. 表（テーブル）の一覧

| 表 | 1行が表すもの | 主な列 |
| --- | --- | --- |
| `departments` | 部署 | `id`、`name`（重複不可）、`is_active` |
| `employees` | 社員・管理者 | `employee_id`（社員コード）、`name`、`email`（重複不可）、`department_id`、`joined_on`、`left_on`、`employment_status` |
| `login_accounts` | ログインできるアカウント | `login_id`、`employee_id`（システム管理者は NULL）、`password_hash`、`role`、`approval_scope`、`is_active` |
| `attendances` | 1人の1日の勤怠 | `employee_id`、`work_date`（2つの組で重複不可）、`check_in_at`、`check_out_at`、`break_start_at`、`break_end_at`、`overtime_end_at` |
| `attendance_requests` | 申請 | `request_type`、`requested_*`（申請する値）、`original_*`（申請した時点の値）、`status`、`reviewer_id` |
| `work_settings` | 勤務設定（1行だけ） | `standard_start`、`standard_end`、`lunch_start`、`lunch_end` |
| `employee_id_sequences` | 年ごとの社員コードの通番 | `year`、`last_number` |

表どうしのつながり（外部キー）は [DB設計書.md](../DB設計書.md) の ER 図を参照。例：`employees.department_id` は `departments.id` を指す。存在しない部署の ID を入れようとすると、DB が拒否する。

## 4-2. SQL を実行する4つの手順

`src/queries/` の関数は、どれも同じ形になっている（`fetchAttendance()` の例）。

```php
function fetchAttendance(PDO $pdo, string $employeeId, string $workDate): ?array {
    // 1. SQL を書く（値の場所は :名前 の「プレースホルダ」にしておく）
    $sql = 'SELECT check_in_at, check_out_at, break_start_at, break_end_at, overtime_end_at
            FROM attendances WHERE employee_id = :employee_id AND work_date = :work_date';
    // 2. 準備する
    $stmt = $pdo->prepare($sql);
    // 3. 値を渡す（型も指定する）
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    // 4. 実行して、結果を取り出す
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
```

### なぜプレースホルダを使うのか（SQL インジェクション対策）

値を文字列としてつなげると、入力によって SQL の意味が変わってしまう。

```php
// やってはいけない例
$sql = "SELECT * FROM login_accounts WHERE login_id = '" . $loginId . "'";
// $loginId に  ' OR '1'='1  が入ると、全員分が取れてしまう
```

プレースホルダなら、`:login_id` に入るのは**いつも「値」**として扱われ、SQL の一部にはならない。さらに `dbconnect.php` の `PDO::ATTR_EMULATE_PREPARES => false` で、SQL と値を DB 側で別々に処理させている（PHP の中で文字列をつなげて送るのではなく、本当に別々に送る）。

### `bindValue` の型

| 値 | 型 | 例 |
| --- | --- | --- |
| 社員コード・日付・日時・文字 | `PDO::PARAM_STR` | `'2025001'`、`'2026-10-15'`、`'2026-10-15 09:00:00'` |
| 数値の ID・件数・年・1／0 | `PDO::PARAM_INT` | 部署 ID、申請 ID、`LIMIT` の件数、`is_active` |
| `null`（空欄の時刻など） | `PDO::PARAM_STR` のままでよい | DB には NULL が入る |

- **社員コードは数字だけに見えても文字列**（`2026A01` のように英字が入ることがある。D-013）。必ず `PARAM_STR`。
- `bindParam` ではなく `bindValue` に揃えている（D-060）。`bindParam` は変数を「参照」で結び付けるので、`execute()` の前に変数が変わると、変わった値が使われてしまう。

### 結果の取り出し方

| メソッド | 返すもの | 使う場面 |
| --- | --- | --- |
| `fetch()` | 1行（`['列名' => 値]`）。無ければ `false` | 1件だけ取る（`fetchAttendance()`） |
| `fetchAll()` | すべての行の配列。無ければ `[]` | 一覧（`fetchMonthlyAttendances()`、`fetchAccounts()`） |
| `fetchColumn()` | 1行目の1列目の値 | 値を1つだけ取る（`nextEmployeeNumber()` の通番） |
| `rowCount()` | INSERT・UPDATE・DELETE で変わった行の数 | 「本当に消せたか」を知る（`deletePendingRequest()`） |

`fetch()` が `false` を返すのを、関数の外では `null` に直している（`?array` 型）。「見つからない」を `null` で表す、という決まりに揃えるため。

## 4-3. 表をつなぐ：JOIN と LEFT JOIN

### JOIN（両方にある行だけ）

```sql
SELECT e.employee_id, e.name, d.name AS department_name
FROM employees e
JOIN departments d ON e.department_id = d.id
WHERE e.employee_id = :employee_id
```

- `employees e` の `e` は別名。どの表の列かを `e.name`・`d.name` で区別する。
- 両方に `name` があるので、部署名に `AS department_name` と別名を付けて、結果の配列で区別できるようにする（`fetchEmployeeProfile()`）。

### LEFT JOIN（左の表の行は、右に相手が無くても残す）

承認画面の一覧（`fetchPendingRequests()`）では、申請にその日の勤怠を付けて取る。新規登録の申請は、その日の勤怠がまだ無い。

```sql
FROM attendance_requests r
JOIN employees e ON e.employee_id = r.employee_id
JOIN departments d ON d.id = e.department_id
LEFT JOIN attendances a ON a.employee_id = r.employee_id AND a.work_date = r.target_date
```

`JOIN` だと勤怠が無い申請が消えてしまう。`LEFT JOIN` なら申請の行は残り、勤怠の列が `NULL` になる。

### GROUP BY と COUNT（まとめて数える）

部署の画面の「在籍人数」（`fetchDepartments()`）：

```sql
SELECT d.id, d.name, d.is_active, COUNT(e.employee_id) AS active_count
FROM departments d
LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = 'active'
GROUP BY d.id, d.name, d.is_active
ORDER BY d.id
```

- `GROUP BY`：部署ごとに行を1つにまとめる。`COUNT(列)`：まとめた行のうち、その列が `NULL` でない数。
- 社員がいない部署は、LEFT JOIN で社員の列が `NULL` になるので 0 人になる。
- 在籍の条件を `WHERE` ではなく `ON` に書いているのがポイント。`WHERE` に書くと、`NULL` の行（社員がいない部署）が条件に合わず、部署ごと消えてしまう。

## 4-4. 「無ければ追加、あれば更新」：INSERT … ON DUPLICATE KEY UPDATE

承認で勤怠に反映するとき（`upsertAttendanceTimes()`）、その日の行があるか無いかで、INSERT か UPDATE かが変わる。これを1つの SQL で行う。

```sql
INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at)
VALUES (:employee_id, :work_date, :check_in_at, :check_out_at, :break_start_at, :break_end_at) AS new
ON DUPLICATE KEY UPDATE check_in_at = new.check_in_at, check_out_at = new.check_out_at,
                        break_start_at = new.break_start_at, break_end_at = new.break_end_at
```

- `attendances` には「`employee_id` と `work_date` の組は重複不可」という一意制約がある。追加しようとして重複したら、代わりに `UPDATE` の部分を実行する。
- `AS new` は「追加しようとした値」に付けた別名（MySQL 8.0.19 以降の書き方）。

社員コードの採番（`nextEmployeeNumber()`）も同じ仕組みで、「その年の行が無ければ 1 で作り、あれば +1」を1回で行う（9章）。

## 4-5. トランザクション：まとめて成功か、まとめて取り消し

承認は「勤怠を書き換える」と「申請を承認済みにする」の2つの更新でできている。片方だけ成功すると、データが食い違う。そこで、**トランザクション**でひとまとめにする。

```php
$pdo->beginTransaction();     // ここから
try {
    upsertAttendanceTimes(…);  // 1つ目の更新
    updateRequestStatus(…);    // 2つ目の更新
    $pdo->commit();            // 全部成功したら、確定
} catch (Throwable $e) {
    $pdo->rollBack();          // 途中で失敗したら、全部取り消し
    throw $e;
}
```

- `commit()` するまで、ほかの接続からは変更前の状態に見える。
- トランザクションは `src/` のルールの関数（`reviewRequest()`、`createAccount()` など）が行い、`src/queries/` の関数の中には書かない。1つのトランザクションに、いくつの SQL 関数を入れるかを決めるのは、ルールの側だから。
- `src/admin.php` には、この形を関数にした `runInTransaction($pdo, function () { … })` もある。

### 行のロック：SELECT … FOR UPDATE

2人の管理者が同じ申請を同時に承認すると、2回処理されるおそれがある。`fetchRequestForUpdate()` は、申請を読むときに `FOR UPDATE` を付けて、トランザクションが終わるまで、その行をほかの人が変えられないようにする（ほかの人は待たされる）。待たされた人が読むときには、もう「承認済み」になっているので、「この申請は、すでに処理されています。」と出せる（8章）。

## 4-6. DB のルール（制約）に守らせる

アプリのチェックだけに頼らず、DB にもルールを持たせている。アプリのチェックをすり抜けても（同時に送られたときなど）、DB が最後に止める。

| 制約 | 例 | 守っていること |
| --- | --- | --- |
| 主キー・一意（`UNIQUE`） | `attendances (employee_id, work_date)`、`employees.email`、`departments.name` | 1人1日1行（BR-005）、メール・部署名の重複 |
| 外部キー | `employees.department_id → departments.id` | 存在しない部署・社員を指さない。参照されている行は消せない |
| `CHECK` | 自分の申請を自分で承認できない（`reviewer_id <> employee_id`）、システム管理者だけ社員コードが空 | 権限のルール |
| 生成列＋一意 | `attendance_requests.pending_key` | 同じ日・同じ種類の「申請中」は1件まで（BR-002） |

### 一意制約のエラーを、利用者向けの文に変える

一意制約に当たると、PDO は `PDOException` を投げる。MySQL のエラー番号は `1062`。

```php
try {
    insertCorrectionRequest(…);
} catch (PDOException $e) {
    if (!isDuplicateError($e)) {    // 1062 でなければ、想定外なので投げ直す
        throw $e;
    }
    return ['ok' => false, 'errors' => ['form' => 'この日には、申請中の申請があります。…']];
}
```

「先に SELECT で重複を調べてから INSERT」だと、調べた直後に別の人が INSERT すると重複してしまう。**INSERT してみて、DB に断られたら文を出す**ほうが確実。

### 生成列（`pending_key`）の仕組み

```sql
pending_key TINYINT(1) GENERATED ALWAYS AS (IF(status = 'pending', 1, NULL)) STORED,
UNIQUE KEY uq_requests_pending (employee_id, target_date, request_type, pending_key)
```

- `pending_key` は、申請中なら 1、それ以外（承認済み・却下）なら NULL になる列（DB が自動で計算する）。
- MySQL の一意制約は、NULL どうしは「重複」と見なさない。そのため、申請中は1件まで、承認済み・却下はいくつでも残せる。

## 試してみる

mysql に入って（`mysql -u attendance_app -p`、`USE attendance_training;`）：

1. `SELECT * FROM attendance_requests WHERE employee_id = '2025001';` で、`status` と `pending_key` の関係を見る。
2. 部署の在籍人数の SQL（4-3）を、`ON` の条件を `WHERE` に移して実行し、社員がいない部署が消えることを確かめる。
