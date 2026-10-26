# `src/queries/employees.php`・`src/queries/login_accounts.php` を読む（社員とログイン情報の読み書き）

## このファイルの役割

社員の表 `employees` と、ログイン情報の表 `login_accounts` に対する **SQL をまとめた2つのファイル**。2つの表は「社員1人に、ログイン情報1つ」で結びついていて、アカウントの作成・無効化では**同じトランザクションの中で一緒に呼ばれる**ので、1つの章で読む。

- `employees.php`：ヘッダーに出す氏名・部署名の読み出し、アカウント管理の一覧、社員の追加、在籍状態の変更、社員の勤怠の一覧（PR 10。**中身は依頼者がこれから書く**。6章）。
- `login_accounts.php`：ログインのときの読み出し、最終ログイン日時、ログイン情報の追加、利用可否・パスワードの変更。
- 「ログインしてよいか」「このアカウントを操作してよいか」は決めない。決めるのは `src/auth.php` と `src/admin.php`。
- 関数の中には、依頼者が書く前の手順を書いた `TODO` のコメントが残っている。下の引用では、その `TODO` のコメント行（関数の上にある「取得する列に … を追加する」の `TODO` も含む）を省いている。ただし、まだ中身を書いていない PR 10 の部分（2章の在籍状態の列と、6章の `fetchMemberAttendances()`）は、`TODO` のコメントそのものを引用して説明する。

## 全体の構成

| ファイル | 関数 | SQL の種類 | 呼ぶ側 |
| --- | --- | --- | --- |
| `employees.php` | `fetchEmployeeProfile()` | SELECT（JOIN・1行） | `src/auth.php` の `currentUser()`、`src/admin.php` の `findManagedAccount()`、`members/detail.php` |
| | `insertEmployee()` | INSERT | `src/admin.php` の `createAccount()`（トランザクションの中） |
| | `fetchAccounts()` | SELECT（3つの表の JOIN） | `admin/accounts.php` |
| | `updateEmploymentStatus()` | UPDATE | `src/admin.php` の `deactivateAccount()`・`reactivateAccount()`（トランザクションの中） |
| | `fetchMemberAttendances()` | SELECT（JOIN・LEFT JOIN、条件によって足す SQL）。**枠だけ** | `members/index.php` |
| `login_accounts.php` | `fetchLoginAccount()` | SELECT（1行） | `src/auth.php` の `attemptLogin()`・`currentUser()`、`src/admin.php` の `findManagedAccount()` |
| | `updateLastLoginAt()` | UPDATE | `attemptLogin()` |
| | `insertLoginAccount()` | INSERT | `createAccount()`（トランザクションの中） |
| | `updateLoginActive()` | UPDATE | `deactivateAccount()`・`reactivateAccount()`（トランザクションの中） |
| | `updatePasswordHash()` | UPDATE | `src/admin.php` の `resetPassword()` |

`fetchEmployeeProfile()`・`fetchLoginAccount()`・`updateLastLoginAt()` は PR 2（認証）で書き、`fetchEmployeeProfile()` には PR 4・PR 6 で列を足した。残りは PR 7（システム管理者のアカウント管理）で書いた。PR 10（社員の勤怠）では、`fetchMemberAttendances()` の枠と、`fetchEmployeeProfile()` に在籍状態の列を足す `TODO` を Claude が置いた。**中身は依頼者が書く**（2026-10-26 時点では、まだ書いていない）。

---

## 1. 2つの表の形と、結びつき

```sql
CREATE TABLE employees (
  employee_id  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '社員コード(例:2026001, 2026A01)',
  name           VARCHAR(100) NOT NULL COMMENT '氏名',
  email          VARCHAR(254) NOT NULL COMMENT 'メールアドレス',
  department_id  INT UNSIGNED NOT NULL COMMENT '部署ID',
  joined_on      DATE         NOT NULL COMMENT '入社日',
  left_on        DATE         NULL     COMMENT '退社日(在籍中はNULL)',
  employment_status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT '在籍状態(active=在籍)',
  …
  PRIMARY KEY (employee_id),
  UNIQUE KEY uq_employees_email (email),
  KEY idx_employees_department (department_id),
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (id)
) …

CREATE TABLE login_accounts (
  login_id       VARCHAR(20)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL …,
  employee_id  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '社員コード(システム管理者はNULL)',
  password_hash  VARCHAR(255) NOT NULL …,
  role           ENUM('employee','manager','system_admin') NOT NULL DEFAULT 'employee' …,
  approval_scope ENUM('department','all') NOT NULL DEFAULT 'department' …,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1 …,
  last_login_at  DATETIME     NULL …,
  …
  PRIMARY KEY (login_id),
  UNIQUE KEY uq_login_accounts_employee (employee_id),
  CONSTRAINT fk_login_accounts_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id),
  CONSTRAINT chk_login_accounts_role CHECK (
    (role = 'system_admin' AND employee_id IS NULL)
    OR (role <> 'system_admin' AND employee_id IS NOT NULL AND employee_id = login_id)
  )
) …
```

- **社員・管理者**：`employees` に1行、`login_accounts` に1行。ログイン ID は社員コードと同じ（D-011）。CHECK 制約 `chk_login_accounts_role` が「社員なら `employee_id = login_id`」を DB で守る。
- **システム管理者**：社員ではないので `employees` に行が無く、`login_accounts` の `employee_id` は `NULL`（D-021）。
- `uq_login_accounts_employee`：1人の社員に、ログイン情報は1つまで。
- 外部キー `fk_login_accounts_employee` があるので、**ログイン情報は、社員の行ができてからでないと作れない**（3章と9章の順番に効く）。

文字の比べ方（照合順序）にも注意する。

| 列 | 照合順序 | 比べ方 |
| --- | --- | --- |
| `employee_id`・`login_id` | `ascii_bin`（明示） | 文字コードの値そのもの。大文字・小文字を区別する。`2026a01` は `2026A01` と一致しない |
| `email`・`name` | 書いていないので、表の既定（`utf8mb4` の既定 `utf8mb4_0900_ai_ci`） | 大文字・小文字を区別しない。全角・半角や、ひらがな・カタカナも同じとみなす |

`email` の一意制約 `uq_employees_email` も、この比べ方で重複を判断する。**`Yamada@example.com` と `yamada@example.com` は「同じメールアドレス」として重複になる**。メールアドレスとしては自然な動きだが、「一意制約の重複＝バイト列がまったく同じ」ではないことは知っておく。

---

## employees.php

## 2. `fetchEmployeeProfile()`：社員の氏名と部署名を読む

```php
function fetchEmployeeProfile(PDO $pdo, string $employeeId): ?array {
    $sql = 'SELECT e.employee_id, e.name, d.name AS department_name, e.joined_on, e.department_id FROM employees e JOIN departments d ON e.department_id = d.id WHERE e.employee_id = :employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
```

- **`JOIN`** で、社員の表に部署の表をつなぐ。つなぐ条件は `ON e.department_id = d.id`（社員の部署 ID ＝ 部署の ID）。`e`・`d` は表の別名。
- 両方の表に `name` がある。そのまま読むと配列のキーがぶつかる（後の列で上書きされる）ので、部署名は **`d.name AS department_name`** と別の名前にする。
- `department_id` は `NOT NULL` で外部キーもあるので、社員には必ず部署がある。`JOIN`（両方にある行だけ残す）でも、社員が消えることはない。
- 社員 ID は文字列なので `PDO::PARAM_STR`。主キーで探すので、結果は0行か1行。
- `e.joined_on`（入社日）は PR 4 で足した列（記録表示で、前の月へ戻れる範囲に使う）。`e.department_id` は PR 6 で足した列（承認範囲が「部署」の管理者の判断に使う）。

### PR 10 で足す列：`e.employment_status`（依頼者が書く）

```php
// 戻り値：['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部', 'joined_on' => '2025-04-01', 'department_id' => 1, 'employment_status' => 'active']。見つからなければ null
// TODO（依頼者・PR 10）：取得する列に、在籍状態 e.employment_status を追加する（社員の勤怠の詳細で、退職した社員を出さないための判断に使う）
```

- 上の SQL の `SELECT` の列に、`e.employment_status` を1つ足す（PR 4・PR 6 と同じ足し方）。戻り値のコメントは、足したあとの形で先に書いてある。
- 使うのは、社員の勤怠の詳細（`members/detail.php`）の `canViewMember()`。`'active'`（在籍）の社員だけを見せる（[src_members.md](./src_members.md) の2章）。
- **足す前は**、戻り値に `employment_status` のキーが無い。`canViewMember()` は「在籍状態が分からないときは見せない」作りなので、その間は、だれの詳細を開いても 404 になる（見えすぎることはない）。テスト `tests/MembersDbTest.php` の `testProfileHasEmploymentStatus()` は、足したあとに通るようになる。

> **ここが重要**：この関数は、`currentUser()` から**画面を開くたびに**呼ばれる（ヘッダーの氏名・部署名と、権限の判断のため）。ログインしたときに覚えた値ではなく、毎回 DB から読むので、システム管理者が部署名を変えたら、次に画面を開いたときにはもう新しい名前が出る。主キーで1行を読むだけなので、毎回読んでも重くない。

## 3. `insertEmployee()`：社員を追加する

```php
function insertEmployee(PDO $pdo, string $employeeId, string $name, string $email, int $departmentId, string $joinedOn): void {
    $sql = 'INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES (:employee_id, :name, :email, :department_id, :joined_on)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
    $stmt->bindValue(':joined_on', $joinedOn, PDO::PARAM_STR);
    $stmt->execute();
}
```

- **`:department_id` だけ `PDO::PARAM_INT`**。部署 ID は `INT` の列で、本当に数だから。社員 ID は数字が並んでいても文字列（`2026A01` がある）なので `PARAM_STR`。**列の型に合わせる**、が決め方。
- 書かない列は DB が決める：`employment_status` は既定値の `'active'`（在籍）、`left_on` は `NULL`。
- 制約で失敗する場合が3つある。
  - **同じメールアドレス**：`uq_employees_email` の違反（1062）。呼ぶ側の `createAccount()` が受け止めて「このメールアドレスは、すでに使われています。」にする。
  - **同じ社員コード**：主キーの違反（これも 1062）。採番（`nextEmployeeNumber()`）がロックで守るので、ふつうは起きない。
  - **無い部署 ID**：外部キー `fk_employees_department` の違反。呼ぶ側の `validateAccount()` が、有効な部署かを先に確かめる。

`createAccount()` は、同じ 1062 でも、メールアドレスの重複だけを利用者向けの文にしている。

```php
        if (isDuplicateError($e) && str_contains($e->getMessage(), 'uq_employees_email')) {
```

MySQL のエラー文は `Duplicate entry '…' for key 'employees.uq_employees_email'` のように**どの制約に当たったか**を含むので、制約の名前で見分けている。主キーの重複など、ほかの 1062 は想定外なので、そのまま例外にする。

### トランザクションの中で呼ばれる

```text
createAccount()：
  password_hash()（トランザクションの外。時間がかかるため）
  beginTransaction()
    nextEmployeeNumber()   … 通番を1つ進める（行をロック）
    insertEmployee()       … 社員（ここ）
    insertLoginAccount()   … ログイン情報（9章）
  commit()                 … 途中で例外が出たら rollBack()
```

> **ここが重要**：社員とログイン情報は、**片方だけできると困る**（ログインできない社員、など）。1つのトランザクションにまとめれば、メールアドレスの重複で失敗したときも、通番を進めたこと・社員を追加したことが全部取り消される。だから通番に欠番もできない。

## 4. `fetchAccounts()`：アカウント管理の一覧

```php
function fetchAccounts(PDO $pdo): array {
    $sql = 'SELECT e.employee_id, e.name, e.email, e.joined_on, e.left_on, e.employment_status,
        d.name AS department_name, l.role, l.approval_scope, l.is_active
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    JOIN login_accounts l ON l.employee_id = e.employee_id
    ORDER BY e.employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

- **3つの表をつなぐ**。`JOIN` を続けて書けば、いくつでもつなげる。社員 → 部署、社員 → ログイン情報、の2本。
- SQL の文字列を複数行で書いている。PHP の `'…'` の中の改行は、そのまま SQL の空白として MySQL に届くので、問題ない。長い SQL は、このほうが読みやすい。
- **プレースホルダが無いので、`bindValue` も無い**。利用者の値を何も使わない SQL なので、`prepare()` → `execute()` だけでよい（`$pdo->query($sql)` でも同じ結果になるが、ほかの関数と形をそろえている）。
- システム管理者は `employees` に行が無いので、`FROM employees` から始めるこの SQL には、そもそも出てこない（関数の上のコメントは「JOIN で自然に除かれる」と書いている）。また `login_accounts` を `JOIN` でつないでいるので、もしログイン情報の無い社員がいたら、その社員は一覧に出ない。社員とログイン情報は3章のトランザクションで必ず一緒に作るので、いまはそういう社員はいない。
- **`ORDER BY e.employee_id`**：社員コードの順。社員コードは文字列で、`ascii_bin` は文字コードの順に並べる。数字（`0`〜`9`）は英大文字（`A`〜`Z`）より文字コードが小さいので、`2026001` … `2026999` → `2026A01` → … と、**作った順に並ぶ**（D-013 の採番の形と合っている）。

## 5. `updateEmploymentStatus()`：在籍状態と退社日を変える

```php
function updateEmploymentStatus(PDO $pdo, string $employeeId, string $status, ?string $leftOn): void {
    $sql = 'UPDATE employees SET employment_status = :status, left_on = :left_on WHERE employee_id = :employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':left_on', $leftOn, PDO::PARAM_STR);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 無効にするとき：`'retired'` と退社日。有効に戻すとき：`'active'` と `null`。**`null` を `PARAM_STR` で渡すと、`left_on` は `NULL` に戻る**。
- `employment_status` は `ENUM` ではなく `VARCHAR(20)` なので、DB はどんな文字列でも受け付ける。正しい値（`EMPLOYMENT_STATUS_RETIRED`・`EMPLOYMENT_STATUS_ACTIVE`）を渡すのは、呼ぶ側の定数が守っている。
- 呼ぶ側の `deactivateAccount()`・`reactivateAccount()` は、`runInTransaction()` で次の2つを1つのトランザクションにまとめる。

```php
    runInTransaction($pdo, function () use ($pdo, $employeeId, $leftOn) {
        updateEmploymentStatus($pdo, $employeeId, EMPLOYMENT_STATUS_RETIRED, $leftOn);
        updateLoginActive($pdo, $employeeId, false);
    });
```

「退職にしたのにログインできる」「ログインできないのに在籍のまま」が起きないように、社員の表とログイン情報の表を一緒に変える（D-015：削除はせず、無効にする）。

---

## 6. `fetchMemberAttendances()`：対象社員と、その日の勤怠（PR 10。枠だけ）

社員の勤怠の一覧（`members/index.php`）で使う。**依頼者がこれから書く関数**で、今は、処理の流れを書いた `TODO` のコメントと、最後の `throw` だけがある（D-054。枠を Claude が置き、中身を依頼者が書く）。

```php
// 対象社員と、その日の勤怠を取得する（社員の勤怠の一覧。D-070）
// 対象：在籍中（employment_status = 'active'）で、自分（$managerId）以外。$departmentId が null でなければ、その部署の社員だけ
// $departmentId：承認範囲が「部署」なら管理者の部署 ID、「全社」なら null（approvalDepartmentId() の値）。$workDate：'2026-10-26'
// 戻り値：[['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部', 'check_in_at' => '2026-10-26 09:01:00', 'check_out_at' => null, 'break_start_at' => null, 'break_end_at' => null], …]
// 打刻していない社員も入る（勤怠の4つの列が null）。対象社員がいなければ []
function fetchMemberAttendances(PDO $pdo, string $managerId, ?int $departmentId, string $workDate): array {
    // TODO（依頼者）：社員・部署を JOIN し、その日の勤怠を LEFT JOIN して取得する
    // 1. SQL を書く：
    //    SELECT e.employee_id, e.name, d.name AS department_name,
    //      a.check_in_at, a.check_out_at, a.break_start_at, a.break_end_at
    //    FROM employees e
    //    JOIN departments d ON d.id = e.department_id
    //    LEFT JOIN attendances a ON a.employee_id = e.employee_id AND a.work_date = :work_date
    //    WHERE e.employment_status = 'active' AND e.employee_id <> :manager_id
    //    ※ 日付の条件（a.work_date = :work_date）は、WHERE ではなく ON に書く
    //      （WHERE に書くと、勤怠の行が無い＝打刻していない社員が、一覧から消える）
    // 2. $departmentId が null でなければ、WHERE の後ろに AND e.department_id = :department_id を付け足す
    //    （fetchPendingRequests() と同じ書き方。値は文字列でつながず、プレースホルダで渡す）
    // 3. 最後に ORDER BY d.id, e.employee_id を付ける（部署の作成順 → 社員コードの順）
    // 4. prepare → bindValue（:work_date と :manager_id は PDO::PARAM_STR、:department_id は付け足したときだけ PDO::PARAM_INT）→ execute
    // 5. fetchAll() の結果を返す
    throw new RuntimeException('fetchMemberAttendances は未実装です');
}
```

- 引数：`$managerId` は見ている管理者の社員 ID（自分を除くため）。`$departmentId` は `?int`（整数か `null`）で、承認の一覧の `fetchPendingRequests()` と同じ意味（`null` なら全社）。`$workDate` は今日の日付。
- 今は `throw new RuntimeException(…)` で止まる。一覧の画面を開くと、この例外が共通のエラー処理（`src/errors.php`）に届き、画面には定型の文が出る。DB のテスト（`tests/MembersDbTest.php`）も、中身を書くまでは通らない。
- `TODO` の手順は、`fetchPendingRequests()`（[src_queries_attendance_requests.md](./src_queries_attendance_requests.md)）とほぼ同じ形。新しく覚えるのは、**`LEFT JOIN` の `ON` に値の条件（日付）を入れる**ところ。

### 3つの表のつなぎ方

| 表 | つなぎ方 | 理由 |
| --- | --- | --- |
| `employees e` | 元の表（`FROM`） | 一覧は「社員」の一覧。社員1人が1行 |
| `departments d` | `JOIN` | 社員には必ず部署がある（`NOT NULL` と外部キー）。部署名と並び順に使う |
| `attendances a` | **`LEFT JOIN`** | 今日打刻していない社員は、勤怠の行が無い。それでも一覧に出したい |

- 勤怠は「1人1日1行」（一意制約 `uq_attendance_employee_date`）なので、その日の勤怠は0行か1行。`LEFT JOIN` しても、社員の行が増えることはない。
- 打刻していない社員は、`a.` の4つの列が `NULL` になる。画面（`memberRowView()`）は、それを「未出勤」「`--:--`」として出す。

### 日付の条件は、`WHERE` ではなく `ON` に書く

この関数で一番大事なところ。例として、今日（10/26）の勤怠が、山田さんにはあり、伊藤さんには無い（10/25 の勤怠だけがある）とする。

**`ON` に書いたとき**（`LEFT JOIN attendances a ON a.employee_id = e.employee_id AND a.work_date = :work_date`）：

| `e.employee_id` | `e.name` | `a.work_date` | `a.check_in_at` |
| --- | --- | --- | --- |
| 2025001 | 山田 太郎 | 2026-10-26 | 2026-10-26 09:01:00 |
| 2025006 | 伊藤 三郎 | `NULL` | `NULL` |

`ON` は「**どの行をつなぐか**」の条件。伊藤さんには「10/26 の勤怠」という相手がいないので、勤怠の列が `NULL` のまま、伊藤さんの行は残る（`LEFT JOIN` だから）。10/25 の勤怠は、条件に合わないので最初からつながらない（ほかの日が混ざらない）。

**`WHERE` に書いたとき**（`LEFT JOIN attendances a ON a.employee_id = e.employee_id` … `WHERE a.work_date = :work_date`）：

まず日付の条件なしでつなぐので、伊藤さんには 10/25 の勤怠がつながる。そのあと `WHERE` が**つないだあとの行**を絞る。

| `e.employee_id` | `a.work_date` | `WHERE a.work_date = '2026-10-26'` |
| --- | --- | --- |
| 2025001 | 2026-10-26 | 残る |
| 2025001 | 2026-10-25（山田さんの前の日） | 消える |
| 2025006 | 2026-10-25 | 消える |
| （勤怠が1日も無い社員） | `NULL` | 消える（`NULL = '2026-10-26'` は「正しい」にならない） |

結果は山田さんの1行だけになり、**今日打刻していない伊藤さんが一覧から消える**。`LEFT JOIN` にした意味が無くなってしまう。

> **ここが重要**：`LEFT JOIN` の**右の表（ここでは勤怠）への条件は `ON` に書く**。`WHERE` は「つないだあとの行」から選ぶので、右の列が `NULL` の行（相手がいなかった行）は、右の表の条件に合わずに消える。部署の画面の在籍人数（`fetchDepartments()` の `e.employment_status = 'active'`）も、同じ理由で `ON` に書いている（[src_queries_departments_sequences_work_settings.md](./src_queries_departments_sequences_work_settings.md) の2章）。一方、`e.employment_status = 'active'` や `e.employee_id <> :manager_id` は**左の表（社員）への条件**なので、`WHERE` に書いてよい（社員そのものを絞りたい）。

### 条件によって足す SQL（`fetchPendingRequests()` と同じ形）

手順の2〜4は、承認の一覧ですでに書いた形と同じ。参考に、`fetchPendingRequests()` の該当の部分を示す（[src_queries_attendance_requests.md](./src_queries_attendance_requests.md)）。

```php
    if ($departmentId !== null) {
        $sql .= ' AND e.department_id = :department_id';
    }
```

```php
    if ($departmentId !== null) {
        $stmt->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
    }
```

- `$sql .= …`：文字列の後ろに付け足す。部署の条件は、承認範囲が「部署」の管理者のときだけ付ける。
- 値（部署 ID）は SQL の文字列につながず、プレースホルダ `:department_id` で渡す。`bindValue` も、**SQL に足したときだけ**呼ぶ（SQL に無いプレースホルダに値を渡すと、エラーになる）。
- `ORDER BY` は、条件を全部足したあと、最後に付ける（SQL では `WHERE` の後ろに `ORDER BY` が来る）。
- 並び順 `ORDER BY d.id, e.employee_id`：部署の作成順 → 同じ部署の中は社員コードの順（4章と同じく、作った順になる）。
- `:work_date` と `:manager_id` は、どちらも文字列なので `PDO::PARAM_STR`。プレースホルダの名前は、SQL の中で1回ずつしか使わない（9章の HY093 の話）。

### テストで確かめること（`tests/MembersDbTest.php`）

依頼者が書いたあと、Claude が DB のテストで次を確かめる（[tests.md](./tests.md) の6章）。

- 承認範囲：部署の管理者は自分の部署だけ、全社の管理者は全社員。どちらも自分と退職した社員は出ない。
- **打刻していない社員も出て、勤怠の列が `null`**。ほかの日の勤怠が混ざらない（`ON` に書いた効き目）。
- 並び順（部署の作成順 → 社員コードの順）と、画面で使う7つの列（キーの名前と順番）。
- 対象社員がいないときは `[]`。

---

## login_accounts.php

## 7. `fetchLoginAccount()`：ログイン ID で、ログイン情報を読む

```php
function fetchLoginAccount(PDO $pdo, string $loginId): ?array {
    $sql = 'SELECT login_id, employee_id, password_hash, role, approval_scope, is_active FROM login_accounts WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
```

（この関数と次の `updateLastLoginAt()` には、最後の行のあとに、コメントにした `throw new RuntimeException(…)` の行が残っている。動きには関係ないので、引用では省いた。）

- 主キー `login_id` で探すので、結果は0行か1行。`login_id` は `ascii_bin` なので、**大文字・小文字も含めて完全に同じ ID だけ**が見つかる。
- 呼ぶ側は3つ。
  - `attemptLogin()`：ログインのとき。`password_hash` を読み、PHP の `password_verify()` で入力されたパスワードと照らし合わせる。
  - `currentUser()`：**画面を開くたびに**。ログイン中に利用不可（`is_active = 0`）にされた人を、すぐ締め出すため。
  - `findManagedAccount()`：アカウント管理で、操作する相手を確かめるため。
- `is_active` は `TINYINT(1)` の数。この環境（`ATTR_EMULATE_PREPARES => false`）では PHP の `int` で返ってくるが、呼ぶ側は `(int) $account['is_active'] !== 1` と、念のため数にしてから比べている。

> **ここが重要**：パスワードの照合を **SQL の `WHERE` でしていない**。`password_hash()` で作ったハッシュは、作るたびに違う値になる（ソルトという、毎回違うおまけが混ぜられる）ので、`WHERE password_hash = …` では探せない。正しいやり方は、**ID で行を読んで、PHP の `password_verify()` で照らし合わせる**。この関数がハッシュを返すのは、そのため。

## 8. `updateLastLoginAt()`：最終ログイン日時を今にする

```php
function updateLastLoginAt(PDO $pdo, string $loginId): void {
    $sql = 'UPDATE login_accounts SET last_login_at = NOW() WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
}
```

- **`NOW()`** は MySQL の関数で、今の日時を返す。PHP で日時を作って渡すのではなく、DB に作らせている。接続のときに `SET time_zone = '+09:00'` をしているので、日本時間になる。
- `attemptLogin()` が、パスワードが合って、利用可のときだけ呼ぶ。トランザクションは使っていない（1つの UPDATE だけなので、要らない）。

## 9. `insertLoginAccount()`：社員・管理者のログイン情報を追加する

```php
function insertLoginAccount(PDO $pdo, string $employeeId, string $passwordHash, string $role, string $approvalScope): void {
    $sql = 'INSERT INTO login_accounts (login_id, employee_id, password_hash, role, approval_scope) VALUES (:login_id, :employee_id, :password_hash, :role, :approval_scope)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    $stmt->bindValue(':approval_scope', $approvalScope, PDO::PARAM_STR);
    $stmt->execute();
}
```

- `:login_id` と `:employee_id` に、**同じ `$employeeId` を2回渡している**。ログイン ID ＝ 社員コード（D-011）だから。
- なぜ1つのプレースホルダ（`VALUES (:id, :id, …)`）にしないのか。この環境は `ATTR_EMULATE_PREPARES => false`（MySQL 側で値を扱う）で、この設定では**同じ名前のプレースホルダを、1つの SQL の中で2回使えない**（使うと `execute()` で SQLSTATE HY093 のエラーになる）。だから名前を分けて、同じ値を2回渡す。
- もし2つの値がずれても、CHECK 制約 `chk_login_accounts_role`（社員なら `employee_id = login_id`）の違反（エラー番号 3819）になり、DB が止めてくれる。
- `role`・`approval_scope` は `ENUM` の列。呼ぶ側の `validateAccount()` が、決まった値（社員か管理者、部署か全社）かを先に確かめる。システム管理者のアカウントは画面では作らない（D-066）。
- 書かない列：`is_active` は既定値の `1`（ログインできる）、`last_login_at` は `NULL`。
- `$passwordHash` は、呼ぶ側が `password_hash()` で作ったハッシュ。**平文のパスワードは、この関数にも DB にも渡らない**。
- 外部キー `fk_login_accounts_employee` があるので、`createAccount()` は `insertEmployee()` の**あとで**この関数を呼ぶ（3章の流れ）。順番が逆だと、外部キーの違反になる。

## 10. `updateLoginActive()`：ログインの利用可否を変える

```php
function updateLoginActive(PDO $pdo, string $loginId, bool $isActive): void {
    $sql = 'UPDATE login_accounts SET is_active = :is_active WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
}
```

- PHP では `true`／`false`（`bool`）で受け取り、DB の `TINYINT(1)` には `1`／`0` の数で渡す。`$isActive ? 1 : 0`（三項演算子）で変換し、型は `PDO::PARAM_INT`。関数の外からは「有効か」だけを考えればよく、「DB では 1／0」という事情は、この関数の中に閉じ込めている。
- 呼ばれるのは、5章の `runInTransaction()` の中（`updateEmploymentStatus()` と一緒）。
- 無効にすると、その人が今ログイン中でも、次に画面を開いたときに `currentUser()` の `fetchLoginAccount()` が `is_active` を見て、ログアウトさせる（7章）。

## 11. `updatePasswordHash()`：パスワードを変える

```php
function updatePasswordHash(PDO $pdo, string $loginId, string $passwordHash): void {
    $sql = 'UPDATE login_accounts SET password_hash = :password_hash WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
}
```

- システム管理者がパスワードを再設定するとき（D-066）に、`resetPassword()` から呼ばれる。ハッシュは呼ぶ側が `password_hash($password, PASSWORD_DEFAULT)` で作る。
- 1つの UPDATE だけなので、トランザクションは使っていない。
- 当てはまる行が無くてもエラーにならない（UPDATE の性質）。呼ぶ側の `findManagedAccount()` が、相手がいることを先に確かめている。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 型は列に合わせる | 社員 ID は `PARAM_STR`、部署 ID と `is_active` は `PARAM_INT` |
| 同じ名前の列には別名 | `d.name AS department_name` |
| 3つの表も `JOIN` を続けてつなぐ | `fetchAccounts()` |
| 照合順序で比べ方が変わる | 社員 ID は `ascii_bin`（区別する）、メールアドレスは `_ai_ci`（区別しない） |
| 制約の名前でエラーを見分ける | `uq_employees_email` を含む 1062 だけを、利用者向けの文にする |
| 一緒に変える表は1つのトランザクション | アカウントの作成、無効化・有効に戻す |
| 外部キーがあると、追加の順番が決まる | 社員 → ログイン情報 |
| 同じプレースホルダは2回使えない | `:login_id` と `:employee_id` に同じ値を渡す |
| パスワードは SQL で比べない | 行を読んで `password_verify()` |
| 日時は DB に作らせる | `NOW()` |
| `bool` は 1／0 に変えて渡す | `$isActive ? 1 : 0` |
| 相手が無くても残すなら `LEFT JOIN` | 今日打刻していない社員（`fetchMemberAttendances()`） |
| `LEFT JOIN` の右の表の条件は `ON` に | 日付の条件を `WHERE` に書くと、打刻していない社員が消える |

## 関連するファイル

- 呼ぶ側：`src/auth.php`（[src_auth.md](./src_auth.md)）、`src/admin.php`（[src_admin.md](./src_admin.md)）、`admin/accounts.php`、`members/index.php`・`members/detail.php`（[members.md](./members.md)）、`src/members.php`（[src_members.md](./src_members.md)）
- 一緒に使う SQL：`src/queries/employee_id_sequences.php`・`src/queries/departments.php`（[src_queries_departments_sequences_work_settings.md](./src_queries_departments_sequences_work_settings.md)）
- 接続の設定：`src/dbconnect.php`（[src_dbconnect.md](./src_dbconnect.md)）
- 表の定義：`docs/db/schema.sql`、[DB設計書.md](../../DB設計書.md)
- 仕組みの説明：[04_DBとSQL.md](../04_DBとSQL.md)、[03_ログインと権限.md](../03_ログインと権限.md)、[13_社員の勤怠.md](../13_社員の勤怠.md)（LEFT JOIN の ON と WHERE）
- テスト：`tests/AdminDbTest.php`（アカウントの作成、メールアドレスの重複、一覧）、`tests/MembersDbTest.php`（社員の勤怠の一覧、在籍状態の列）
