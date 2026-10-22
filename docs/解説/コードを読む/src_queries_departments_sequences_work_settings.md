# `src/queries/departments.php`・`employee_id_sequences.php`・`work_settings.php` を読む（部署・採番・勤務設定の読み書き）

## このファイルの役割

システム管理者の画面（PR 7）で使う、**小さな表の SQL をまとめた3つのファイル**。どれも短いが、それぞれに MySQL らしい書き方が1つずつ入っている。

| ファイル | 表 | このファイルで学べる書き方 |
| --- | --- | --- |
| `departments.php` | `departments`（部署マスタ） | `LEFT JOIN` と `GROUP BY`・`COUNT`、条件を `ON` に書くか `WHERE` に書くか |
| `employee_id_sequences.php` | `employee_id_sequences`（社員コードの採番） | `INSERT … ON DUPLICATE KEY UPDATE` で数を進める、行のロックとトランザクション、`fetchColumn()` |
| `work_settings.php` | `work_settings`（勤務設定。1行だけ） | `bindParam` と `bindValue` の違い、`ON UPDATE CURRENT_TIMESTAMP` |

- どれも「入力が正しいか」「操作してよいか」は決めない。決めるのは `src/admin.php`。
- 関数の中には、依頼者が書く前の手順を書いた `TODO` のコメントが残っている。下の引用では、その `TODO` のコメント行（コメントにした `throw new RuntimeException(…)` の行も含む）を省いている。

## 全体の構成

| ファイル | 関数 | SQL の種類 | 呼ぶ側 |
| --- | --- | --- | --- |
| `departments.php` | `fetchDepartments()` | SELECT（LEFT JOIN・GROUP BY） | `admin/departments.php`、`admin/accounts.php`、`src/admin.php` の `createAccount()`・`renameDepartment()`・`setDepartmentActive()` |
| | `insertDepartment()` | INSERT | `src/admin.php` の `addDepartment()` |
| | `updateDepartmentName()` | UPDATE | `renameDepartment()` |
| | `updateDepartmentActive()` | UPDATE | `setDepartmentActive()` |
| `employee_id_sequences.php` | `nextEmployeeNumber()` | INSERT … ON DUPLICATE KEY UPDATE ＋ SELECT | `createAccount()`（トランザクションの中） |
| `work_settings.php` | `fetchWorkSettings()` | SELECT（1行） | `index.php`、`api/attendance.php`、`api/requests.php`、`records/index.php`、`approvals/index.php`、`admin/settings.php` |
| | `updateWorkSettings()` | UPDATE | `src/admin.php` の `saveWorkSettings()` |

`fetchWorkSettings()` だけは PR 1 で書いた、このシステムで一番古い SQL の関数。残りは PR 7 で書いた。

---

## departments.php

## 1. ファイルの先頭と、`departments` 表の形

```php
// 部署マスタ（departments テーブル）を読み書きする関数
// 値は bindValue で渡す（D-060）。部署 ID と利用可否（1／0）は数値なので PDO::PARAM_INT、部署名は PDO::PARAM_STR
```

```sql
CREATE TABLE departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '部署ID',
  name        VARCHAR(50)  NOT NULL COMMENT '部署名',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '利用可否(1=有効,0=無効)',
  …
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) …
```

- 部署 ID は `INT` の本当の数なので `PDO::PARAM_INT`（社員 ID が文字列なのとは違う）。
- `uq_departments_name`：同じ名前の部署は作れない。`name` は照合順序を書いていないので `utf8mb4` の既定（`utf8mb4_0900_ai_ci`）で比べる。3章で詳しく見る。
- 社員の表 `employees` が外部キー `fk_employees_department` でこの表の `id` を指している。だから**社員がいる部署の行は消せない**。このシステムは部署を消さずに「無効」にする（5章）。

## 2. `fetchDepartments()`：部署ごとに、在籍している社員の数を数える

```php
function fetchDepartments(PDO $pdo): array {
    $sql = 'SELECT d.id, d.name, d.is_active, COUNT(e.employee_id) AS active_count
        FROM departments d
        LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = \'active\'
        GROUP BY d.id, d.name, d.is_active
        ORDER BY d.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

この SQL は、MySQL の中で次の順に組み立てられると考えると分かりやすい。

1. **`FROM … LEFT JOIN … ON …`**：部署の1行ごとに、`ON` の条件に合う社員の行をつなぐ。社員が3人いる部署は3行に増え、合う社員がいない部署は、社員の列が全部 `NULL` の1行になる（`LEFT JOIN` なので、部署の行は消えない）。
2. **`GROUP BY d.id, d.name, d.is_active`**：部署ごとに、増えた行を1行にまとめる。
3. **`COUNT(e.employee_id)`**：まとめた行のうち、`e.employee_id` が `NULL` でない数を数える。
4. **`ORDER BY d.id`**：登録した順に並べる。

例えば、営業部に在籍2人・退職1人、総務部に誰もいないとき：

| 1. つないだ直後 | `d.name` | `e.employee_id` |
| --- | --- | --- |
| | 営業部 | 2025001 |
| | 営業部 | 2025002 |
| | 総務部 | `NULL` |

退職の1人は `ON` の条件（`employment_status = 'active'`）に合わないので、最初からつながらない。まとめると、営業部が `2`、総務部が `0` になる。

> **ここが重要（`ON` と `WHERE` の違い）**：在籍の条件を `WHERE e.employment_status = 'active'` に書くと、結果が変わる。`WHERE` は**つないだあとの行**を絞るので、総務部の行（`e.employment_status` が `NULL`）も「`'active'` ではない」として消えてしまい、**社員のいない部署が一覧から消える**。`LEFT JOIN` の右の表への条件は `ON` に書く。`ON` は「どの行をつなぐか」の条件で、合わなくても左の行（部署）は残る。

> **ここが重要（`COUNT(列)` と `COUNT(*)` の違い）**：`COUNT(*)` は行の数をそのまま数えるので、総務部の「社員の列が `NULL` の1行」も数えて `1` になってしまう。`COUNT(e.employee_id)` は `NULL` を数えないので、正しく `0` になる。

- `GROUP BY` に `d.id, d.name, d.is_active` の3つを書いている。MySQL の既定（`ONLY_FULL_GROUP_BY`）では、`SELECT` に書いた列は「`GROUP BY` に書く」か「`COUNT` などでまとめる」必要がある。`d.id` は主キーなので、MySQL は `d.id` だけでも `d.name` などが1つに決まると分かってくれるが、3つとも書いておけば、ほかのデータベースでも通る、はっきりした書き方になる。
- `active_count` は、部署の画面の「在籍人数」の列に「2 人」のように出す。無効の部署も含めて全部を返すので、アカウント作成の選択欄では、呼ぶ側の `activeDepartments()` が有効な部署だけを残す。
- プレースホルダが無いので `bindValue` も無い。
- この関数は、一覧の表示だけでなく、`createAccount()`（選んだ部署が有効か）や `renameDepartment()`・`setDepartmentActive()`（部署があるか）の確かめにも使われる。どれもトランザクションの外で呼ぶ。

## 3. `insertDepartment()`：部署を追加する

```php
function insertDepartment(PDO $pdo, string $name): void {
    $sql = 'INSERT INTO departments (name) VALUES (:name)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
}
```

- 列は `name` だけ。`id` は `AUTO_INCREMENT`、`is_active` は既定値の `1`（有効）。
- **同じ名前の部署があると、`uq_departments_name` の違反（1062）**。この関数では確かめず、呼ぶ側の `addDepartment()` が受け止めて「同じ名前の部署があります。別の名前にしてください。」にする。

> **ここが重要**：「同じ名前か」の判断は、照合順序 `utf8mb4_0900_ai_ci` で行われる。この比べ方では、**大文字・小文字、全角・半角、ひらがな・カタカナを同じとみなす**。だから `Sales` と `sales`、`えいぎょうぶ` と `エイギョウブ` は、同じ名前として重複になる（MySQL で確かめた）。部署名としては自然な動きだが、「PHP の `===` で違う文字列でも、DB は重複と言うことがある」ことは知っておく。先に PHP で同じ名前を探すより、DB の一意制約に任せたほうが、判断がずれない。

## 4. `updateDepartmentName()`：部署の名前を変える

```php
function updateDepartmentName(PDO $pdo, int $id, string $name): void {
    $sql = 'UPDATE departments SET name = :name WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
```

- ほかの部署と同じ名前にすると、3章と同じく 1062。`renameDepartment()` が受け止める。
- 自分と同じ名前のまま保存しても、重複にはならない（一意制約は「ほかの行」との重複を見る）。
- 無い `id` の UPDATE は、0行を更新して黙って終わる。`renameDepartment()` が `findDepartment(fetchDepartments($pdo), …)` で、部署があることを先に確かめている。
- 社員は部署を `id` で指しているので、名前を変えても、社員の表は何も変えなくてよい。次に画面を開けば、新しい名前で出る（`fetchEmployeeProfile()` が毎回 JOIN で読むため）。

## 5. `updateDepartmentActive()`：部署を有効・無効にする

```php
function updateDepartmentActive(PDO $pdo, int $id, bool $isActive): void {
    $sql = 'UPDATE departments SET is_active = :is_active WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
```

- `bool` を `1`／`0` に変えて `PDO::PARAM_INT` で渡す（`updateLoginActive()` と同じ形）。
- 無効にしても、所属している社員はそのまま（D-067）。外部キーは `id` を指しているだけなので、`is_active` が変わっても関係ない。無効の部署は、アカウント作成の選択欄から消えるだけ。

---

## employee_id_sequences.php

## 6. `nextEmployeeNumber()`：その年の通番を1つ進めて、読み直す

```php
// その年の通番を1つ進めて、進めたあとの通番を返す（その年の最初の1人なら 1）。社員コードの採番に使う（D-013、D-067）
// 呼び出し側（src/admin.php）がトランザクションの中で呼ぶ。行は、トランザクションが終わるまでロックされるので、同時に作っても重複しない
// 戻り値：通番（例：1、2、…）
function nextEmployeeNumber(PDO $pdo, int $year): int {
    $sql = 'INSERT INTO employee_id_sequences (year, last_number) VALUES (:year, 1) AS new
        ON DUPLICATE KEY UPDATE last_number = employee_id_sequences.last_number + 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':year', $year, PDO::PARAM_INT);
    $stmt->execute();

    $sql2 = 'SELECT last_number FROM employee_id_sequences WHERE year = :year';
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->bindValue(':year', $year, PDO::PARAM_INT);
    $stmt2->execute();
    $value = $stmt2->fetchColumn();

    return (int) $value;
}
```

表は、年ごとに「最後に出した通番」を1行持つだけ。

```sql
CREATE TABLE employee_id_sequences (
  year         SMALLINT UNSIGNED NOT NULL COMMENT '採番年(yyyy)',
  last_number  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'その年に発行した最後の通番(1〜)',
  PRIMARY KEY (year)
) …
```

### 1つ目の SQL：無ければ 1 で作り、あれば +1

- `year` は主キー。**その年の行が無ければ** INSERT が通り、`last_number = 1` の行ができる（その年の最初の1人）。
- **その年の行があれば**主キーの重複になり、代わりに `ON DUPLICATE KEY UPDATE` の後ろが動いて、`last_number` が1つ増える。
- `employee_id_sequences.last_number` は「**今、表にある値**」。`new.last_number`（今回 INSERT しようとした値＝いつも `1`）ではないことに注意。ここでは `new` を使っていないので、`AS new` は無くても同じに動く（`TODO` の手順に合わせて書いてある）。
- 年は数なので `PDO::PARAM_INT`。

### 2つ目の SQL：進めたあとの値を読む

- **`fetchColumn()`** は、次の行の**1つ目の列の値だけ**を返す。1列1行の結果を読むときに、`fetch()` して `$row['last_number']` と取り出す手間が省ける。行が無ければ `false`。
- `(int)` で数にして返す。この環境（`ATTR_EMULATE_PREPARES => false`）では、数の列はもともと PHP の `int` で返ってくるが、戻り値の型 `int` を確実に守るために変換している。行は1つ目の SQL で必ずあるので、`false`（→ `0`）になることは無い。
- 2つの SQL で、プレースホルダの名前が同じ `:year` でも問題ない。別々の `prepare()` なので、それぞれの SQL の中で1回ずつ使っているだけ。

### なぜトランザクションの中で呼ぶのか

1つ目の SQL は、更新した行（または作った行）に**排他ロック**をかける。ロックは**トランザクションが終わるまで**続く。

```text
createAccount()：
  beginTransaction()
    nextEmployeeNumber()   … 2026年の行をロック。通番 5 を得る
    insertEmployee()       … 2026005 で社員を追加
    insertLoginAccount()
  commit()                 … ここでロックが外れる
```

- 同じ時に別の人がアカウントを作ると、その人の `nextEmployeeNumber()` は、1つ目の SQL で**待たされる**。先の人が `commit()` したあとに動くので、`6` を得る。同じ番号を2人が得ることは無い（`tests/AdminDbTest.php` の `testNextEmployeeNumberLocksUntilCommit()` が、2つ目の接続が待たされることを確かめている）。
- もしトランザクションの外（自動コミット）で呼ぶと、1つ目の SQL が終わった瞬間にロックが外れる。2つ目の SELECT までの間に別の人が +1 すると、**2人とも同じ値を読む**ことがありうる。だから、この関数はトランザクションの中で呼ぶ決まりにしている。
- 途中で失敗して `rollBack()` すると、+1 も取り消される。メールアドレスの重複で作成をやめても、通番は進まない（欠番ができない）。年間の上限（`EMPLOYEE_NUMBER_MAX`）を超えたときも、`createAccount()` が `rollBack()` するので、通番は戻る。

> **ここが重要**：最初の設計は「`SELECT … FOR UPDATE` で読んでロックしてから +1」だったが、D-067 で今の形に変えた。その年の行がまだ無いときに2人が同時に `SELECT … FOR UPDATE` すると、2人とも「行が無い」場所をロックしたまま INSERT しようとして、お互いを待ち合う（デッドロック）ため（[DB設計書.md](../../DB設計書.md) の採番の説明）。`INSERT … ON DUPLICATE KEY UPDATE` なら、**「作る」と「+1 する」が1つの SQL**なので、この問題が起きない。

---

## work_settings.php

## 7. `fetchWorkSettings()`：勤務設定（全社共通の1行）を読む

```php
function fetchWorkSettings(PDO $pdo): array {
    $sql = 'SELECT standard_start, standard_end, lunch_start, lunch_end, updated_at FROM work_settings WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $id = 1;
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('勤務設定がありません');
    }
    return $row;
}
```

表は、`id = 1` の1行だけを持つ（`docs/db/schema.sql` の最後で `INSERT INTO work_settings (id) VALUES (1);` している）。

```sql
CREATE TABLE work_settings (
  id              TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '常に1',
  standard_start  TIME NOT NULL DEFAULT '09:00:00' …,
  standard_end    TIME NOT NULL DEFAULT '18:00:00' …,
  lunch_start     TIME NOT NULL DEFAULT '12:00:00' …,
  lunch_end       TIME NOT NULL DEFAULT '13:00:00' …,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) …
```

> **注意（決まりと違うところ）**：この関数だけ **`bindParam`** を使っている。PR 1 で、依頼者の以前のコードの書き方（D-033）に合わせて書いたもの。そのあと D-060 で「値は `bindValue` に揃える」と決めたが、この関数は「そのままでよい」とした。

`bindParam` と `bindValue` の違い：

| | `bindValue` | `bindParam` |
| --- | --- | --- |
| 渡すもの | **値**（その時点の値を写す） | **変数**（参照。`execute()` の時点の中身が使われる） |
| `1` を直接渡せるか | 渡せる：`bindValue(':id', 1, …)`（8章） | 渡せない。変数が要るので、`$id = 1;` を用意している |
| 気をつけること | 特になし | `bindParam` のあと、`execute()` の前に変数を変えると、変えた値が使われる |

結果はどちらも同じ。`bindValue` のほうが「書いた時点の値が入る」ので、読み違えにくい。これが D-060 で揃えた理由。

- `TIME` の列は、`'09:00:00'` の形の**文字列**で返ってくる。呼ぶ側は、これを時刻の文字列と大小で比べる（`overtimeAvailable()` など。[src_attendance.md](./src_attendance.md) 5章）。`HH:MM:SS` と桁がそろっているので、文字列の比べ方で正しく前後が分かる。
- 行が無い（`false`）ときは、`null` を返さずに**例外を投げる**。勤務設定が無いのは、DB の作り方の誤りで、画面ではどうにもできないため。例外は共通のエラー処理（`src/errors.php`）が記録し、画面には定型の文が出る（D-036）。
- `updated_at`（最終更新日時）は PR 7 で足した列（勤務設定の画面に出す）。
- 呼ぶ側はとても多い（メイン画面、打刻の API、簡単残業申請の API、記録表示、承認画面、勤務設定の画面）。どれもトランザクションの外。
- `id` は決まった値（`1`）で、利用者の入力ではない。だから SQL に `WHERE id = 1` と直接書いても安全だが、この関数はプレースホルダの練習も兼ねて、穴にして渡している。

## 8. `updateWorkSettings()`：勤務設定を変える

```php
// 勤務設定を変える（PR 7。全社共通の1行。変えた日の履歴は持たない。D-008、D-067）
// $settings：['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00']（呼び出し側が確認済み）
function updateWorkSettings(PDO $pdo, array $settings): void {
    $sql = 'UPDATE work_settings SET standard_start = :standard_start, standard_end = :standard_end, lunch_start = :lunch_start, lunch_end = :lunch_end WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':standard_start', $settings['standard_start'], PDO::PARAM_STR);
    $stmt->bindValue(':standard_end', $settings['standard_end'], PDO::PARAM_STR);
    $stmt->bindValue(':lunch_start', $settings['lunch_start'], PDO::PARAM_STR);
    $stmt->bindValue(':lunch_end', $settings['lunch_end'], PDO::PARAM_STR);
    $stmt->bindValue(':id', 1, PDO::PARAM_INT);
    $stmt->execute();
}
```

- 4つの時刻は、`'09:00:00'` の形の文字列で `PDO::PARAM_STR`。この形は、呼ぶ側の `validateWorkSettings()` が、入力の `'09:00'` に `':00'` を足して作る。時刻の前後（始業 < 終業、昼休憩が勤務時間の中）も、そこで確かめ済み。
- `:id` には **`1` を直接** `bindValue` している。7章の `bindParam` では変数が必要だったところ。
- `updated_at` は書いていないが、`ON UPDATE CURRENT_TIMESTAMP` で DB が今の日時にする。ただし、**値が1つでも実際に変わったときだけ**。同じ設定のまま保存すると、行が変わらないので `updated_at` も変わらない（MySQL で確かめた）。画面の「最終更新日時」は、「最後に保存ボタンを押した日時」ではなく「最後に値が変わった日時」になる。
- 1つの UPDATE だけなので、トランザクションは使っていない。
- 履歴は持たない（D-067）。昼休憩の時刻を変えると、過去の日の労働時間も、新しい昼休憩で計算し直される（勤務設定の画面に注意の文がある）。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 数えるなら `GROUP BY` と `COUNT(列)` | 部署ごとの在籍者数。`COUNT(*)` だと、社員のいない部署が `1` になる |
| `LEFT JOIN` の右の表の条件は `ON` に | `WHERE` に書くと、社員のいない部署が消える |
| 重複の判断は DB の一意制約に任せる | 部署名。照合順序で、大文字・小文字やかなの違いも同じとみなす |
| 消さずに無効にする | 部署の `is_active`（外部キーで、社員のいる部署は消せない） |
| 「作る」と「+1」を1つの SQL で | `INSERT … ON DUPLICATE KEY UPDATE last_number = 表名.last_number + 1` |
| ロックはトランザクションの終わりまで | 採番は `createAccount()` のトランザクションの中で呼ぶ |
| 1つの値だけなら `fetchColumn()` | 進めたあとの通番 |
| `bindValue` は値、`bindParam` は変数 | `fetchWorkSettings()` だけ `bindParam`（D-060 で、そのままでよいとした） |
| 時刻は桁のそろった文字列 | `TIME` は `'09:00:00'` で読み書きする |
| `ON UPDATE CURRENT_TIMESTAMP` は値が変わったときだけ | 同じ設定で保存しても、最終更新日時は変わらない |

## 関連するファイル

- 呼ぶ側：`src/admin.php`（[src_admin.md](./src_admin.md)）、`admin/departments.php`・`admin/accounts.php`・`admin/settings.php`、勤務設定を読む画面（`index.php`・`records/index.php`・`approvals/index.php`）と API（`api/attendance.php`・`api/requests.php`）
- 採番と一緒に使う SQL：`src/queries/employees.php`・`src/queries/login_accounts.php`（[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md)）
- 勤務設定を使う計算：`src/attendance.php`（[src_attendance.md](./src_attendance.md)）、`src/records.php`（[src_records.md](./src_records.md)）
- 表の定義：`docs/db/schema.sql`、[DB設計書.md](../../DB設計書.md)
- 仕組みの説明：[04_DBとSQL.md](../04_DBとSQL.md)（GROUP BY と COUNT、ON DUPLICATE KEY UPDATE、トランザクション）
- テスト：`tests/AdminDbTest.php`（採番とロック、部署の一覧、勤務設定の保存）
