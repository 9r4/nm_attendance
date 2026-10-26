# `tests/` を読む（自動テスト）

## このフォルダの役割

PHPUnit で、`src/` の関数が正しく動くかを確かめる。`composer test` で全部を実行する（`composer.json` の `"test": "phpunit tests"`）。

| ファイル | 対象 | DB |
| --- | --- | --- |
| `AttendanceTest.php` | `src/attendance.php`（ボタンのルール） | 使わない |
| `RecordsTest.php` | `src/records.php`（月の決め方・労働時間・カレンダー） | 使わない |
| `HolidaysTest.php` | `src/holidays.php`（祝日） | 使わない |
| `RequestsTest.php` | `src/requests.php`（申請の入力の確認・表示） | 使わない |
| `ApprovalsTest.php` | `src/approvals.php`（承認範囲・一覧の表示） | 使わない |
| `AdminTest.php` | `src/admin.php`（社員コード・入力の確認） | 使わない |
| `MembersTest.php` | `src/members.php`（見てよい社員か・一覧の1行の表示）と、`calendarDays()` の見るだけの値 | 使わない |
| `AssetUrlTest.php` | `src/functions.php` の `assetUrl()` | 使わない |
| `ApprovalsDbTest.php` | 承認の SQL とトランザクション | テスト用の DB |
| `AdminDbTest.php` | システム管理者の SQL・採番・トランザクション | テスト用の DB |
| `MembersDbTest.php` | 社員の勤怠の一覧の SQL（`fetchMemberAttendances()`）と、在籍状態の列（`fetchEmployeeProfile()`） | テスト用の DB |

全部の形を説明すると長いので、**読むときに知っておくと役に立つ書き方**を、実際のテストで説明する。

---

## 1. テストの基本の形（`AttendanceTest.php`）

```php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/attendance.php';

final class AttendanceTest extends TestCase {
    …
    public function testUnknownActionIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        actionMode(null, 'delete');
    }
```

- `TestCase` を継承したクラスを作り、名前が `test` で始まるメソッドを書く。1つのメソッドが1つのテスト。
- テストしたい `src/` のファイルを `require_once` で読み込み、関数を直接呼ぶ。画面や DB を通さないので、一瞬で終わる。
- `expectException()`：「このあと、この例外が起きるはず」。起きなければ失敗になる。知らない操作で `actionMode()` が例外を投げることを確かめている。

## 2. テスト用のデータを作る小さな関数

```php
// 勤怠の行を作る（省略した列は NULL）
private static function row(?string $in = null, ?string $out = null, ?string $breakStart = null, ?string $breakEnd = null): array {
    $at = fn (?string $time) => $time === null ? null : '2026-10-15 ' . $time . ':00';
    return [
        'check_in_at' => $at($in),
        'check_out_at' => $at($out),
        'break_start_at' => $at($breakStart),
        'break_end_at' => $at($breakEnd),
    ];
}
```

- `self::row('09:00', null, '12:30')` と書くだけで、「出勤 9:00、休憩開始 12:30」の勤怠の行ができる。
- `fn (…) => …` はアロー関数（1行で書ける無名関数）。外側の変数を、そのまま使える。

> **ここが重要**：テストのデータを毎回フルに書くと、何を試しているのかが読み取れなくなる。**小さな関数で「違うところだけ」を書ける**ようにすると、テストが仕様書のように読める。

## 3. データを変えて繰り返す（データプロバイダ）

```php
public static function states(): array {
    $r = ACTION_RECORD;
    $o = ACTION_OVERWRITE;
    $u = ACTION_UNAVAILABLE;
    return [
        '行が無い' => [null, [$r, $u, $u, $u], 'not_started'],
        '出勤のみ' => [self::row('09:00'), [$o, $r, $r, $u], 'working'],
        '休憩中' => [self::row('09:00', null, '12:30'), [$u, $u, $u, $r], 'on_break'],
        …
        // 修正申請（PR 5）で作られうる、ボタンでは起きない組み合わせ
        '出勤なしで退勤あり' => [self::row(null, '18:00'), [$u, $u, $u, $u], 'finished'],
    ];
}

#[DataProvider('states')]
public function testActionModeAndStatus(?array $attendance, array $expectedModes, string $expectedStatus): void {
    $actions = ['check_in', 'check_out', 'break_start', 'break_end'];
    foreach ($actions as $i => $action) {
        $this->assertSame($expectedModes[$i], actionMode($attendance, $action), $action);
    }
    …
}
```

- `#[DataProvider('states')]`（PHP 8 の「属性」という書き方）を付けると、`states()` が返す組ごとに、テストを1回ずつ実行する。キー（`'休憩中'` など）が、失敗したときに表示される名前になる。
- 1行が「この状態で、出勤・退勤・休憩開始・休憩終了は、記録／上書き／押せない のどれか」を表す**表**になっている。ボタンのルールの一覧として、そのまま読める。
- `assertSame()` の3つ目の引数（`$action`）は、失敗したときに出す説明。どのボタンで失敗したかが分かる。

> **ここが重要**：「ボタンでは起きないが、修正申請の承認で作られうるデータ」（出勤なしで退勤あり など）も試している。**普通の操作では起きない組み合わせ**こそ、誤りが隠れやすい。

## 4. 境目を試す（`AdminTest.php`）

```php
public function testFormatEmployeeId(): void {
    $this->assertSame('2026001', formatEmployeeId(2026, 1));
    $this->assertSame('2026999', formatEmployeeId(2026, 999));
    $this->assertSame('2026A01', formatEmployeeId(2026, 1000));
    $this->assertSame('2026A99', formatEmployeeId(2026, 1098));
    $this->assertSame('2026B01', formatEmployeeId(2026, 1099));
    $this->assertSame('2026Z99', formatEmployeeId(2026, 3573));
    …
    // 番号が重ならない（1〜3573 がすべて違う社員コードになる）
    $ids = array_map(fn ($n) => formatEmployeeId(2026, $n), range(1, EMPLOYEE_NUMBER_MAX));
    $this->assertCount(EMPLOYEE_NUMBER_MAX, array_unique($ids));
}
```

- 999 と 1000、1098 と 1099 など、**形が切り替わる境目**を必ず試す。計算の誤り（1つずれ）は、境目で起きる。
- 最後の2行は「全部の番号を作って、重複を取り除いても数が減らない」＝重複が無い、を確かめている。個別の例だけでなく、**性質（ルール全体）**を確かめる書き方。このテストで、DB設計書の採番の例の番号のずれが見つかった。

### 誤りの並ぶ順も確かめる

```php
        // 複数の誤りは、画面の順に並ぶ（画面には、先頭の1つを出す）
        $this->assertSame(['name', 'email', 'password'], array_keys($errors(['name' => '', 'email' => 'x', 'password' => ''])));
        // 画面の並び（氏名 → 部署 → メールアドレス）の順。部署とメールアドレスの両方が誤っていたら、部署が先（D-069）
        $this->assertSame(['department_id', 'email'], array_keys($errors(['department_id' => '', 'email' => 'x'])));
```

- `array_keys()`：配列のキーだけを、入っている順に取り出す。誤りの文ではなく、**どの欄の誤りが、どの順に並ぶか**を確かめている。
- 画面には最初の誤り1つだけを出すので、並ぶ順も「画面で何が出るか」を決めるルールの1つ。PR 9 で順を画面の並びに直したとき（D-069）、このテストを足して、順が戻らないようにした（[src_admin.md](./src_admin.md) 5章）。

## 5. 公開データと突き合わせる（`HolidaysTest.php`）

```php
// 2000〜2050 年（公開データがある範囲）のすべての祝日・休日が、日付も名前も一致する
public function testMatchesPublicData(): void {
    for ($year = 2000; $year <= 2050; $year++) {
        $expected = [];
        foreach (Holidays::$holidays as $date => $holiday) {
            if (str_starts_with($date, $year . '-')) {
                $expected[$date] = $holiday['name'];
            }
        }
        $actual = japaneseHolidays($year);
        $this->assertSame(array_keys($expected), array_keys($actual), $year . ' 年の日付');
        …
```

- 自分で答えを書くと、答えのほうを間違えることがある。内閣府の祝日の一覧を元にした公開データ（`holiday-jp/holiday_jp`。開発のときだけ使う）を「正解」にして、51年分を全部比べている。

## 6. DB を使うテスト（`ApprovalsDbTest.php`・`AdminDbTest.php`・`MembersDbTest.php`）

### 毎回、表を作り直す

```php
protected function setUp(): void {
    $dsn = getenv('ATTENDANCE_TEST_DSN');
    if ($dsn === false || $dsn === '') {
        $this->markTestSkipped('ATTENDANCE_TEST_DSN が無いため、DB のテストは飛ばす');
    }
    …
    foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $this->pdo->exec('DROP TABLE `' . $table . '`');
    }
    …
    foreach (['schema.sql', 'test_data.sql'] as $file) {
        …
    }
}
```

- `setUp()` は、各テストの前に毎回呼ばれる。表を全部消して、`schema.sql`・`test_data.sql` から作り直すので、**前のテストの結果に左右されない**。
- 環境変数が無ければ `markTestSkipped()` で飛ばす。WSL で `composer test` しても、開発用の DB を消してしまうことはない。

### わざと失敗させて、元に戻るかを確かめる

```php
// 申請の更新を、必ず失敗させる（テスト用のトリガー）
$this->pdo->exec("CREATE TRIGGER fail_request_update BEFORE UPDATE ON attendance_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
try {
    reviewRequest($this->pdo, $manager, $id, 'approve', '');
    $this->fail('例外になるはず');
} catch (PDOException $e) {
    $this->assertStringContainsString('test failure', $e->getMessage());
}
$this->assertFalse($this->pdo->inTransaction());
$this->assertNull($this->attendance('2025001', '2026-10-13')); // 勤怠に反映していない
$this->assertSame('pending', $this->request($id)['status']);
```

- トリガー（表の更新の前に自動で動く処理）で、申請の更新を**必ず失敗**させる。承認の処理は「勤怠を更新 → 申請を更新」の順なので、1つ目は成功し、2つ目で失敗する。
- そのあと、勤怠も元のまま（`null`）であることを確かめる。トランザクションの `rollBack()` が効いている証拠になる。
- `$this->fail()`：ここまで来たら（例外が起きなかったら）失敗にする。

### 直したことを、テストで残す（`ApprovalsDbTest.php`）

```php
    // コメントの長さを確かめるのは、却下のときだけ（承認ではコメントを使わない。D-069）
    public function testCommentLengthOnlyForReject(): void {
        $sales = $this->manager(self::SALES_MANAGER);
        $long = str_repeat('あ', APPROVAL_COMMENT_MAX_LENGTH + 1);
        $a = $this->addRequest('2025001', '2026-10-12', 'correction', ['check_in_at' => '2026-10-12 09:00:00']);
        $this->assertSame(['comment' => 'コメントは 500 文字までにしてください。'], reviewRequest($this->pdo, $sales, $a, 'reject', $long)['errors']);
        $this->assertSame('pending', $this->request($a)['status']);
        $this->assertTrue(reviewRequest($this->pdo, $sales, $a, 'approve', $long)['ok']);
        $this->assertSame(['approved', null], [$this->request($a)['status'], $this->request($a)['review_comment']]);
    }
```

- `str_repeat('あ', 501)`：上限より1文字だけ長いコメントを作る（境目の1つ外）。
- 同じ長いコメントで、却下は断られ（申請中のまま）、承認は通る（コメントは `null` で記録される）ことを、1つのテストで確かめる。PR 9 で直したこと（[src_approvals.md](./src_approvals.md) 5章）を、テストとして残している。

### 2つの接続で、ロックを確かめる（`AdminDbTest.php`）

```php
$other = self::connect();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
$this->pdo->beginTransaction();
$this->assertSame(1, nextEmployeeNumber($this->pdo, 2032));
$other->beginTransaction();
try {
    nextEmployeeNumber($other, 2032);
    $this->fail('ロックで待たされるはず');
} catch (PDOException $e) {
    $this->assertSame(1205, $e->errorInfo[1]); // Lock wait timeout exceeded
}
```

- 1つ目の接続のトランザクションの途中で、2つ目の接続から同じ年の通番を進めようとする。待つ時間を1秒にしておき、**待たされて時間切れ（エラー 1205）になる**ことで、行がロックされていることを確かめる。
- 1つ目が `commit()` したあとは、2つ目が続きの番号（2）になることも確かめている。

> **ここが重要**：同時の操作やトランザクションの失敗は、画面を手で操作しても、なかなか起こせない。テストの中で**わざと起こして**確かめることで、「本番でまれに起きる誤り」を前もってつぶせる。

### 「無い」ことを確かめるデータを足す（`MembersDbTest.php`）

```php
        $this->pdo->exec("INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
            ('2025001', '2026-10-26', '2026-10-26 09:01:00', NULL, '2026-10-26 15:00:00', NULL),
            ('2025001', '2026-10-25', '2026-10-25 08:00:00', '2026-10-25 17:00:00', NULL, NULL),
            ('2025006', '2026-10-25', '2026-10-25 08:00:00', '2026-10-25 17:00:00', NULL, NULL),
```

```php
        // 打刻していない社員（2025006。ほかの日の勤怠はある）も出て、勤怠の列は null（ほかの日の勤怠が混ざらない）
        $this->assertSame([null, null, null, null], [$rows[1]['check_in_at'], $rows[1]['check_out_at'], $rows[1]['break_start_at'], $rows[1]['break_end_at']]);
```

- `setUp()` で、テスト用の社員（2025006）に、**今日（10/26）ではない日（10/25）の勤怠だけ**を入れておく。
- 一覧の SQL が `LEFT JOIN` の `ON` に日付の条件を書いていれば、2025006 は一覧に出て、勤怠の列は全部 `null` になる。もし `WHERE` に書いてしまうと、2025006 が一覧から消えて、テストが失敗する。`ON` に日付を書き忘れると、10/25 の時刻が混ざって、やはり失敗する（[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md) の6章）。
- 「打刻していない社員」だけでなく、「ほかの日の勤怠はある社員」にしているのがポイント。勤怠が1件も無い社員だけだと、日付の条件の誤りに気付けない。

### 2つの関数が同じ答えになることを確かめる

```php
        // 一覧の対象と、詳細を見てよい社員が同じであること
        $manager = ['employee_id' => '2025002', 'role' => ROLE_MANAGER, 'approval_scope' => 'department', 'department_id' => 1];
        $viewable = [];
        foreach (['2025001', '2025002', '2025003', '2025004', '2025005', '2025006'] as $id) {
            if (canViewMember($manager, fetchEmployeeProfile($this->pdo, $id))) {
                $viewable[] = $id;
            }
        }
        $this->assertSame($this->ids(fetchMemberAttendances($this->pdo, '2025002', 1, '2026-10-26')), $viewable);
```

- 一覧に出す社員は SQL（`fetchMemberAttendances()`）で、詳細を見てよい社員は PHP（`canViewMember()`）で決めている。別々に書いた2つの条件が、**同じ社員を選ぶ**ことを、テストデータの全員で確かめる。
- どちらかの条件だけを変えると、このテストが失敗する。「一覧には出るのに詳細は 404」のような食い違いを防ぐ（[src_members.md](./src_members.md) の2章）。

---

## まとめ：このフォルダから学べること

| 書き方 | 例 |
| --- | --- |
| 関数を直接呼んで、すぐ終わるテストにする | `require_once` して `actionMode()` を呼ぶ |
| 違うところだけ書けるデータの作り方 | `self::row('09:00', null, '12:30')` |
| 表のようなテスト | データプロバイダ `states()` |
| 境目と性質を試す | 999／1000、全番号の重複なし |
| 外の正解と比べる | 祝日の公開データ |
| 毎回まっさらにする | `setUp()` で表を作り直す |
| わざと失敗・同時の操作を起こす | トリガー、2つの接続とロックの時間切れ |
| 誤りがあれば失敗するデータを選ぶ | ほかの日の勤怠だけがある社員（`LEFT JOIN` の `ON`） |
| 別々に書いた条件を突き合わせる | `canViewMember()` と `fetchMemberAttendances()` |

## 関連する資料

- 実行のしかたと、ブラウザでの確認（Playwright）：[../../検証スクリプト/README.md](../../検証スクリプト/README.md)
- テストの全体の考え方：[../11_テスト.md](../11_テスト.md)
