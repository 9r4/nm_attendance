# `src/members.php` を読む（見てよい社員かの判断と、一覧の1行の表示）

## このファイルの役割

社員の勤怠（PR 10。D-070）について、**「この管理者が、この社員の勤怠を見てよいか」**と、**「一覧の1行に何を出すか」**を決めるファイル。一覧（`members/index.php`）と詳細（`members/detail.php`）から呼ばれる。

- HTML は出さない。SQL も書かない（SQL は `src/queries/employees.php` の `fetchMemberAttendances()`・`fetchEmployeeProfile()`）。
- 見てよいのは、管理者（`manager`）が、**自分以外の、在籍中の、承認範囲（自分の部署／全社）の社員**を見るときだけ。
- どちらの関数も、配列を受け取って答えを返すだけなので、DB が無くてもテストできる（`tests/MembersTest.php`）。
- 48行の短いファイル。考え方は [src_approvals.md](./src_approvals.md) の `canReview()`・`approvalItemView()` と同じなので、並べて読むと分かりやすい。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 先頭 | 読み込みと、見られないときの文（定数） |
| `canViewMember()` | この社員の勤怠を見てよいか（詳細の画面で使う） |
| `memberRowView()` | 一覧の1行を、画面用の形にする |

---

## 1. 読み込みと定数

```php
include_once(__DIR__ . '/approvals.php');
include_once(__DIR__ . '/records.php');
include_once(__DIR__ . '/queries/employees.php');

// 見られない社員（対象外・存在しない・自分・退職）のときに出す文。どの理由かは区別しない
const MEMBER_NOT_VIEWABLE_MESSAGE = 'この社員の勤怠は表示できません。';
```

- `approvals.php`：承認範囲の部署 ID を作る `approvalDepartmentId()`。一覧の画面が、承認画面と同じ範囲で社員を選ぶために使う。
- `records.php`：詳細のカレンダー（`resolveMonth()`・`buildCalendar()`・`calendarDays()`）と、労働時間（`dailyWorkMinutes()`・`formatMinutes()`）。`records.php` は `attendance.php`（`attendanceView()`）と、勤怠・申請の SQL も読み込むので、画面はこの1つを読めば足りる。
- `queries/employees.php`：`fetchMemberAttendances()`（一覧）と `fetchEmployeeProfile()`（詳細）。
- `ROLE_MANAGER` などの権限の定数は `src/auth.php` にある。このファイルでは読み込まず、画面（とテスト）が先に `src/auth.php` を読む約束にしている（`src/approvals.php` と同じ）。
- 断るときの文を**定数にしている**。詳細の画面は、どの理由で断るときも、この1つの文を出す（3つの理由ごとに文を書かない）。テストやブラウザの確認（`pr10.js`）も、同じ文かを確かめる。

## 2. `canViewMember()`：この社員の勤怠を見てよいか

```php
// この管理者が、この社員の勤怠を見られるか（$profile は fetchEmployeeProfile() の行）
// 一覧（fetchMemberAttendances()）の対象と同じ条件：管理者、自分でない、在籍中、承認範囲（部署／全社）
function canViewMember(array $user, array $profile): bool {
    if ($user['role'] !== ROLE_MANAGER || $profile['employee_id'] === $user['employee_id']) {
        return false;
    }
    // 在籍状態を取得していない（列が無い）ときも、見せない
    if (($profile['employment_status'] ?? null) !== 'active') {
        return false;
    }
    return $user['approval_scope'] === 'all' || (int) $profile['department_id'] === $user['department_id'];
}
```

- `$user`：ログイン中の利用者（`requireLogin()` の戻り値）。`$profile`：見ようとしている社員（`fetchEmployeeProfile()` の戻り値）。
- 断る条件を先に書き、`return false;` ですぐ抜ける（早期リターン）。上から順に：
  1. 管理者でない（社員・システム管理者）。画面は `requireRole()` で先に 403 にしているが、関数だけを見ても正しい答えになるように、ここでも確かめる。
  2. 自分（`employee_id` が同じ）。自分の記録は、記録表示で見る。
  3. 在籍中（`'active'`）でない。退職した社員（`'retired'`）は出さない。
- 最後に承認範囲。「全社」ならだれでもよく、「部署」なら、社員の部署 ID と管理者の部署 ID が同じときだけ。
- `(int) $profile['department_id']`：DB から読んだ値を整数にそろえてから、`===` で比べる。`$user['department_id']` は `currentUser()` で整数にしてある。テストでは、部署 ID が文字列 `'1'` で来ても見られることを確かめている。

| 管理者 | 社員 | 答え |
| --- | --- | --- |
| 2025002（営業部・部署） | 2025001（営業部・在籍） | 見られる |
| 2025002（営業部・部署） | 2025004（開発部・在籍） | 見られない（範囲の外） |
| 2025002（営業部・部署） | 2025002（自分） | 見られない |
| 2025003（開発部・全社） | 2025002（営業部の管理者） | 見られる（管理者も社員の一人） |
| 2025003（開発部・全社） | 退職した営業部の社員 | 見られない |

### `?? null` で、「分からないときは見せない」

```php
    if (($profile['employment_status'] ?? null) !== 'active') {
```

- `??`（null 合体演算子）：左が無い（キーが無い・`null`）なら、右の値にする。`$profile['employment_status']` と直接書くと、キーが無いときに「未定義のキー」の警告が出る。
- `employment_status` は、PR 10 で依頼者が `fetchEmployeeProfile()` の SQL に足した列。もし列を取り忘れると、このキーが無くなる。そのときは `null` → `'active'` ではない → 見せない、になる（テストでも、キーを消した行で確かめている）。
- 「`'retired'` なら断る」ではなく、**「`'active'` のときだけ通す」**と書いているのもポイント。在籍状態に知らない値が入っても、通さない側に倒れる。

> **ここが重要**：見てよいかの判断は、**分からないとき・想定外のときは「見せない」**に倒す（許すものだけを書く。ホワイトリスト）。逆に「退職なら見せない」と書くと、列を取り忘れたとき（`null`）に、退職した社員まで見えてしまう。守りの判断では、**間違えたときに「見えすぎる」より「見えない」ほうが安全**。

### 一覧の SQL と、同じ条件にする

一覧に出す社員は、`fetchMemberAttendances()` の SQL の条件（`WHERE e.employment_status = 'active' AND e.employee_id <> :manager_id`、部署の条件）で選ぶ。詳細で見てよい社員は、この関数で決める。**2つの条件がずれると**、「一覧には出るのに、詳細を押すと 404」や、逆に「一覧には出ないのに、URL を打てば見られる」が起きる。

そこで、DB を使うテスト（`tests/MembersDbTest.php` の `testProfileHasEmploymentStatus()`）で、テストデータの全社員について `canViewMember()` を試し、**見られる社員の一覧が、`fetchMemberAttendances()` の結果と同じになる**ことを確かめている（[tests.md](./tests.md) の6章）。

## 3. `memberRowView()`：一覧の1行を、画面用の形にする

```php
function memberRowView(array $row, string $workDate, array $settings): array {
    $attendance = [
        'check_in_at' => $row['check_in_at'],
        'check_out_at' => $row['check_out_at'],
        'break_start_at' => $row['break_start_at'],
        'break_end_at' => $row['break_end_at'],
    ];
    $view = attendanceView($attendance);
    $minutes = dailyWorkMinutes($attendance, $workDate, $settings);
```

- `$row`：`fetchMemberAttendances()` の1行（社員 ID・氏名・部署名と、その日の勤怠の4つの時刻）。打刻していない社員は、4つの時刻が `null`。
- まず、行から**勤怠の4つの列だけ**を取り出して、`$attendance` を作る。メイン画面の `attendanceView()` も、記録表示の `dailyWorkMinutes()` も、「勤怠の行」（この4つのキーを持つ配列）を受け取る関数なので、同じ形にそろえて渡す。
- 打刻していない社員も、`null` を4つ持った配列にする（`null` そのものではなく）。`attendanceStatus()` は4つが `null` なら「未出勤」と判断し、`dailyWorkMinutes()` は出勤か退勤が `null` なら `null` を返す。
- `attendanceView($attendance)`：状態（`'working'` など）・状態の名前（「勤務中」）・出勤・退勤・休憩の表示（`09:01`・`--:--`・`15:00–休憩中`）を作る。メイン画面と同じ関数なので、表示がそろう。2つ目以降の引数（標準終了時刻など）は渡さない（ボタンの判断は、この画面では使わない）。
- `dailyWorkMinutes()`：その日の労働時間（分）。退勤していなければ `null`。

```php
    return [
        'employeeId' => $row['employee_id'],
        'name' => $row['name'],
        'department' => $row['department_name'],
        'status' => $view['status'],
        'statusLabel' => $view['statusLabel'],
        'checkIn' => $view['checkIn'],
        'checkOut' => $view['checkOut'],
        'break' => $view['break'],
        'workTime' => $minutes === null ? '--' : formatMinutes($minutes),
    ];
}
```

- 画面で使うキーだけを返す。`attendanceView()` が返す `buttons` などは使わないので、ここで落とす。
- 労働時間：`null`（退勤前）なら `--`、あれば `formatMinutes()` で「8時間15分」の形。**勤務中の人の「今までの労働時間」は出さない**（退勤していないので、まだ決まらない）。

テスト（`tests/MembersTest.php` の `rowCases()`）で確かめている例：

| 勤怠 | 状態 | 出勤 | 退勤 | 休憩 | 労働時間 |
| --- | --- | --- | --- | --- | --- |
| 無し（打刻していない） | 未出勤 | `--:--` | `--:--` | `--:--` | `--` |
| 出勤 9:01 | 勤務中 | `09:01` | `--:--` | `--:--` | `--` |
| 出勤 9:01、休憩開始 15:00 | 休憩中 | `09:01` | `--:--` | `15:00–休憩中` | `--` |
| 出勤 9:00、退勤 18:30、休憩 15:00〜15:15 | 退勤済み | `09:00` | `18:30` | `15:00–15:15` | `8時間15分`（昼休憩 1 時間と休憩 15 分を引く） |

> **ここが重要**：状態や時刻の作り方を、この関数の中に**新しく書いていない**。メイン画面の `attendanceView()`、記録表示の `dailyWorkMinutes()`・`formatMinutes()` を呼んでいるだけ。ルールが1か所なので、労働時間の計算を直せば、メイン画面・記録表示・社員の勤怠が全部そろって変わる。この関数の仕事は「DB の行を、その関数が受け取る形にして、結果を画面のキーに並べる」ことだけ。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 断る条件を先に書いて、すぐ抜ける | `canViewMember()` の早期リターン |
| 分からないときは「見せない」に倒す | `($profile['employment_status'] ?? null) !== 'active'` |
| 許す値を書く（ホワイトリスト） | 「`'active'` だけ通す」。「`'retired'` を断る」ではない |
| `===` の前に型をそろえる | `(int) $profile['department_id']` |
| 一覧の条件と、詳細の判断をそろえる | DB のテストで、2つが同じ社員になることを確かめる |
| 断る文は1つの定数に | `MEMBER_NOT_VIEWABLE_MESSAGE` |
| 表示の作り方は共有する | `attendanceView()`・`dailyWorkMinutes()`・`formatMinutes()` |
| 関数が受け取る形にそろえてから渡す | 行から勤怠の4つの列だけで `$attendance` を作る |

## 関連するファイル

- 呼ぶ側：[members.md](./members.md)（`members/index.php`・`members/detail.php`）
- 呼ばれる側：[src_attendance.md](./src_attendance.md)（`attendanceView()`）、[src_records.md](./src_records.md)（`dailyWorkMinutes()`・`formatMinutes()`・`calendarDays()`）、[src_approvals.md](./src_approvals.md)（`approvalDepartmentId()`、同じ形の `canReview()`）
- SQL：[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md)（`fetchMemberAttendances()`・`fetchEmployeeProfile()`）
- ログイン中の利用者の形（部署 ID・承認範囲）：[src_auth.md](./src_auth.md)
- 仕組みの説明：[../13_社員の勤怠.md](../13_社員の勤怠.md)
- テスト：`tests/MembersTest.php`（見てよいかの判断、1行の表示、見るだけの値）、`tests/MembersDbTest.php`（一覧の SQL と、判断が同じ社員になること）
- 決定事項：[決定事項.md](../../決定事項.md)（D-025・D-070）
