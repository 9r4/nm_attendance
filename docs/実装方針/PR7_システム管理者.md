# 実装 PR 7：システム管理者（アカウント管理、部署、勤務設定）（完了：2026-10-22、PR #9）

状態: **完了（2026-10-22、PR #9 をマージ。44f1c4a）**。以下は経緯。承認済み（2026-10-15。D-067）。作業ブランチ `claude/pr7-admin` に、Claude の担当部分と枠をプッシュ（7-2）。依頼者が関数を書いてプッシュ（97c4bf9）。テストして PR #9 を作成（7-3）。

## 1. 目的

- システム管理者（`system_admin`）が、社員・管理者のアカウントを**作成・無効化（退職）・有効に戻す・パスワード再設定**できる（FR-007、D-013、D-015、D-024、D-026）。
- システム管理者が、**部署マスタ**（追加・名前の変更・無効化）と、**勤務設定**（標準始業・終業、昼休憩）を変えられる（D-014、D-019、D-024）。
- 依頼者が、初めての **GROUP BY と COUNT（集計）**と、**1つの関数で2つの SQL を順に実行する採番**を書く。

画面モックの確認結果は [画面モック確認結果.md](../画面モック確認結果.md) の 5章（差異 15〜25）。

## 2. 画面と動き

使えるのはシステム管理者だけ（社員・管理者は 403）。URL は `admin/` の下（[技術方針.md](../技術方針.md) 2-1）。

| 画面 | URL |
| --- | --- |
| アカウント管理 | `admin/accounts.php` |
| 部署 | `admin/departments.php` |
| 勤務設定 | `admin/settings.php` |

- **ハンバーガーメニュー**（システム管理者）：「メイン画面」「アカウント管理」「部署」「勤務設定」（差異 23）。
- **システム管理者のメイン画面**：今の「システム管理者には、出退勤の記録はありません。」の代わりに、3つの画面へのリンク（ボタン）を並べる（ログインしてすぐ使えるように）。
- 3つの画面とも、画面モックの見た目（PC：左に入力のカード、右に一覧）。768px 未満では縦に並べ、一覧は横にスクロールする。
- 送信は、画面を切り替える普通のフォーム（POST＋CSRF）。**成功したら同じ画面に戻して（リダイレクト）、通知を出す**（二重送信を防ぐ。[PHPコーディング方針.md](../PHPコーディング方針.md) 3-3）。入力の誤りは、同じ画面に入力した値を残したまま、カードの上にエラーを出す（画面モックのとおり、先に見つかった1つ）。
- 確認のウィンドウ（無効化・パスワード再設定・部署名の変更）は `<dialog>` の中のフォーム。ウィンドウからの送信で誤りがあったときは、画面の上にエラーを出す。

### 2-1. アカウント管理（`admin/accounts.php`）

**新しいアカウント**（左のカード）：

| 項目 | 入力のルール |
| --- | --- |
| 氏名 | 必須。前後の空白は取る。100 文字まで |
| メールアドレス | 必須。メールアドレスの形（PHP の `filter_var(…, FILTER_VALIDATE_EMAIL)`）。254 文字まで。**他のアカウントと同じものは不可**（差異 17） |
| 部署 | 必須。**有効な部署を並べた選択欄**（差異 16）。有効な部署が1つも無いときは「先に部署を登録してください。」と出し、作成ボタンを押せなくする |
| 入社日 | 必須。初期値は今日。**先の日付も可**（入社前に作る）。2000-01-01 〜 今日の1年後まで（差異 17） |
| 権限 | 「社員」「管理者」（初期値は社員）。システム管理者は作れない（差異 18） |
| 承認範囲 | **「管理者」を選んだときだけ出す**。「所属部署のみ」「全社員」（初期値は所属部署のみ）（差異 18） |
| パスワード | 必須。**半角の英数字・記号で 8〜64 文字**（空白は不可）。文字の種類の組み合わせは求めない（差異 19） |
| パスワード（確認） | 必須。パスワードと同じであること（差異 19） |

- **ID の入力欄は無い**。社員コード（＝ログイン ID）はシステムが決める（差異 15、3章）。
- 作成したら、入力欄を空に戻して「アカウント「2026005」（山田 太郎）を作成しました。社員コード（ログイン ID）とパスワードを、本人に伝えてください。」と出す。
- エラーの文（例）：「氏名を入力してください。」「メールアドレスの形が正しくありません。」「このメールアドレスは、すでに使われています。」「パスワードは、半角の英数字・記号で 8〜64 文字にしてください。」「確認用のパスワードが一致しません。」「選んだ部署は使えません。画面を開き直してください。」

**登録済みアカウント**（右の一覧。件数付き）：

| 列 | 内容 |
| --- | --- |
| 社員コード | 社員コード順に並べる。システム管理者は出さない（差異 22） |
| 氏名 | 氏名（下にメールアドレスを小さく） |
| 部署 | 部署名 |
| 権限 | 「社員」「管理者（所属部署のみ）」「管理者（全社員）」 |
| 状態 | 「在籍」／「退職（2026/10/31）」（退社日が無ければ「退職」）。退職の行は文字を薄くする |
| 操作 | 「パスワード再設定」、在籍なら「無効化」、退職なら「有効に戻す」 |

| 操作 | 内容 |
| --- | --- |
| 無効化（差異 20） | ウィンドウ「アカウントを無効にします」（社員コード・氏名、**退社日**（初期値は今日。入社日から今日まで）、「キャンセル」「無効にする」）。在籍状態を `retired`、退社日を記録、ログインを不可にする（同時に。4章）。ログイン中でも、次の操作から使えなくなる（実装済み）。通知「アカウント「2025001」（山田 太郎）を無効にしました。」 |
| 有効に戻す（差異 20） | ウィンドウで確認してから、在籍状態を `active`、退社日を空、ログインを可にする（同時に）。通知「アカウント「…」（…）を有効に戻しました。」 |
| パスワード再設定（差異 21） | ウィンドウ（社員コード・氏名、新しいパスワード、確認、「キャンセル」「再設定する」）。ルールは作成と同じ。通知「アカウント「…」（…）のパスワードを再設定しました。新しいパスワードを、本人に伝えてください。」 |

- 退職したアカウントの、承認されていない申請は、そのまま管理者の承認画面に残る（承認・却下できる）。
- アカウントの内容（氏名・部署・権限など）を直す操作は作らない（差異 25）。

### 2-2. 部署（`admin/departments.php`）

| 部分 | 内容 |
| --- | --- |
| 新しい部署（左のカード） | 部署名（必須。前後の空白は取る。50 文字まで。**他の部署と同じ名前は不可**）、「部署を追加」。通知「部署「総務部」を追加しました。」 |
| 一覧（右） | 部署名、**在籍人数**（在籍の社員・管理者の数）、状態（有効／無効）、操作（「名前の変更」、有効なら「無効化」、無効なら「有効に戻す」）。登録した順 |
| 名前の変更 | ウィンドウ（今の名前が入った入力欄、「キャンセル」「変更する」）。ルールは追加と同じ。所属する社員の表示（ヘッダー・承認画面など）も新しい名前になる |
| 無効化・有効に戻す | 確認のウィンドウのあとに切り替える。**無効にした部署は、アカウント作成の選択欄に出なくなるだけ**（所属している社員は、そのまま。承認範囲も変わらない）。在籍人数が1人以上でも無効にできる（ウィンドウに「在籍している社員 ○ 人は、この部署のままです。」と出す） |

- 部署の削除はしない（社員から参照されているため。DB の外部キーでも消せない）。

### 2-3. 勤務設定（`admin/settings.php`）

| 部分 | 内容 |
| --- | --- |
| 入力 | 標準始業時刻、標準終業時刻、昼休憩の開始、昼休憩の終了（時:分。今の値が入った状態）、「保存」。最終更新日時を出す |
| ルール | すべて必須で、時:分の形。**始業 ＜ 終業**、**昼休憩の開始 ＜ 終了**、**昼休憩は始業から終業の間**（始業 ≦ 昼休憩の開始、昼休憩の終了 ≦ 終業） |
| 注意の文 | 「変更は、すぐに全社員に反映されます。過去の日も含めて、記録表示の労働時間は新しい昼休憩で計算し直されます。簡単残業申請・承認画面の標準終了時刻も、新しい値になります。」 |
| 通知 | 「勤務設定を保存しました。」 |

- 勤務設定は1つだけ（全社共通。D-019）で、変えた日の履歴は持たない。そのため、変えると過去の日の労働時間も変わる（記録表示の計算は、昼休憩を使う。D-049）。上の注意の文で知らせる。
- 出した後の申請は、変更の影響を受けない（申請のときの確認は、そのときの設定で行い、承認のときに確認し直さない）。
- 標準始業時刻は、今は画面の計算に使っていない（遅刻の判定は今回作らないため。D-022）。設定としては変えられるようにする。

## 3. 社員コードの採番（D-013）

アカウント作成のトランザクションの中で行う（途中で失敗したら、採番も含めてすべて元に戻す）。

1. 今年（日本時間）の通番を1つ進めて、新しい通番 n を得る（依頼者の関数 `nextEmployeeNumber()`。5-1）。
2. n から社員コードを作る（Claude の関数）。n ≦ 999 は `yyyy`＋3桁（2026001）、1000 以上は `yyyy`＋英字＋2桁（2026A01 〜 2026Z99）。Z99 を超えたら「今年作れるアカウントの上限に達しました。」
3. 社員（`employees`）と、ログイン情報（`login_accounts`。ログイン ID＝社員コード）を追加する。
4. メールアドレスが重複していたら（一意制約のエラー）、元に戻して「このメールアドレスは、すでに使われています。」（通番は進まない）。

- **採番の SQL の変更（DB設計書の書き方の変更）**：DB設計書は「当年の行を `SELECT … FOR UPDATE` で確保して +1」と書いているが、**その年の最初の1人**のときは行がまだ無く、2人が同時に作ると、2人とも「行が無い」と判断して、片方がエラー（デッドロック）になる（Claude の環境の MySQL 8.0 で、実際に起きることを確認）。そこで、**`INSERT … ON DUPLICATE KEY UPDATE` で「行が無ければ 1 で作り、あれば +1」を1回で行い、そのあと同じ行を読む**形にする（PR 6 の `upsertAttendanceTimes()` と同じ書き方）。行を作った・増やした時点で、トランザクションが終わるまで、その行はほかの人が変えられない（ロックされる）ので、同時に作っても重複しない（Claude の環境で、同じ年の最初の1人を 8 人同時に作って、1〜8 が重複なく出ること、エラーにならないこと、トランザクションを元に戻すと通番も戻ることを確認）。承認されたら、DB設計書の採番の説明を直す。
- テストデータの社員（2025001〜2025004）は 2025 年の番号なので、2026 年の採番（2026001〜）とは重ならない。

## 4. 無効化・有効に戻す（DB設計書 6章）

1つのトランザクションで、社員とログイン情報を同時に変える。

| 操作 | `employees` | `login_accounts` |
| --- | --- | --- |
| 無効化 | `employment_status` ＝ `retired`、`left_on` ＝ 退社日 | `is_active` ＝ 0 |
| 有効に戻す | `employment_status` ＝ `active`、`left_on` ＝ NULL | `is_active` ＝ 1 |

- `employment_status` の値に `retired`（退職）を加える（DB設計書は「他の値は運用に合わせて追加する」としている。DB の変更は不要）。承認されたら、DB設計書に追記する。
- 一覧の「状態」は、ログインの可否（`is_active`）で決める（テストデータの 2025004 のように、ログイン不可で在籍状態が `active` のままの行も「退職」と出し、「有効に戻す」を押せる）。

## 5. 変更するファイル

| ファイル | 内容 | 担当 |
| --- | --- | --- |
| **`src/queries/employee_id_sequences.php`**（新規） | **`nextEmployeeNumber()`** | **依頼者**（枠は Claude） |
| **`src/queries/departments.php`**（新規） | **`fetchDepartments()`、`insertDepartment()`、`updateDepartmentName()`、`updateDepartmentActive()`** | **依頼者**（枠は Claude） |
| **`src/queries/employees.php`** | **`insertEmployee()`、`fetchAccounts()`、`updateEmploymentStatus()`** | **依頼者**（枠は Claude） |
| **`src/queries/login_accounts.php`** | **`insertLoginAccount()`、`updateLoginActive()`、`updatePasswordHash()`** | **依頼者**（枠は Claude） |
| **`src/queries/work_settings.php`** | **`updateWorkSettings()`** | **依頼者**（枠は Claude） |
| `src/admin.php`（新規） | 入力の確認、社員コードの作成、アカウント作成・無効化・有効に戻す・再設定のトランザクション、部署・勤務設定の処理、一覧の表示の組み立て | Claude |
| `admin/accounts.php`、`admin/departments.php`、`admin/settings.php`、`js/admin.js`（新規） | 3つの画面（表示と POST の処理）、ウィンドウの開閉、承認範囲の出し分け | Claude |
| `templates/menu.php`、`index.php` | システム管理者のメニューと、メイン画面のリンク | Claude |
| `css/style.css`、`tests/` | 画面のスタイル、テスト | Claude |

- DB の変更・Apache の設定の変更は不要。
- パスワードのハッシュは Claude の処理が作り（`password_hash($password, PASSWORD_DEFAULT)`）、依頼者の関数には、ハッシュを渡す（平文は渡さない）。

## 6. 依頼者が書くもの（仕様）

値は `bindValue` で渡す（D-060）。部署 ID・年・数値は `PDO::PARAM_INT`、それ以外（社員 ID を含む）は `PDO::PARAM_STR`。null を渡す列は `PDO::PARAM_NULL` ではなく `PDO::PARAM_STR` のままでよい（PR 5 と同じ）。関数の `{` は関数名と同じ行（D-056）。枠には、処理の流れのコメントを書いておく（D-054）。トランザクションは Claude の処理が行うので、関数の中には書かない。合わせて **12 個**（ほとんどは1文の INSERT・UPDATE）。

### 6-1. `nextEmployeeNumber()`（`src/queries/employee_id_sequences.php`。今年の通番を1つ進めて返す）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function nextEmployeeNumber(PDO $pdo, int $year): int {` |
| SQL 1 | `INSERT INTO employee_id_sequences (year, last_number) VALUES (:year, 1) AS new ON DUPLICATE KEY UPDATE last_number = employee_id_sequences.last_number + 1` |
| SQL 2 | `SELECT last_number FROM employee_id_sequences WHERE year = :year` |
| 戻り値 | SQL 2 で読んだ `last_number`（数値にして返す：`(int)`） |
| ポイント | **1つの関数で SQL を2つ、順に実行する**（prepare・bindValue・execute を2回）。SQL 1 は「行が無ければ 1 で作り、あれば今の値に +1」（3章）。`employee_id_sequences.last_number` は「今、表にある値」、`new.…` は「追加しようとした値」 |

### 6-2. `fetchDepartments()`（`src/queries/departments.php`。部署の一覧と在籍人数）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchDepartments(PDO $pdo): array {` |
| SQL | `SELECT d.id, d.name, d.is_active, COUNT(e.employee_id) AS active_count FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = 'active' GROUP BY d.id, d.name, d.is_active ORDER BY d.id` |
| 戻り値 | 全部の部署（無効も含む）。アカウント作成の選択欄は、Claude の処理が有効な部署だけを選ぶ |
| ポイント | **GROUP BY**：部署ごとに行をまとめる。**COUNT(列)**：まとめた行のうち、その列が NULL でない数（社員がいない部署は、LEFT JOIN で社員の列が NULL になるので 0）。この SQL は、Claude の環境で、退職の社員を数えないこと・社員のいない部署が 0 になることを確認済み。在籍の条件（`e.employment_status = 'active'`）は、WHERE ではなく **ON に書く**（WHERE に書くと、社員がいない部署の行が消える） |

### 6-3. `insertDepartment()`、`updateDepartmentName()`、`updateDepartmentActive()`（`src/queries/departments.php`）

| 関数 | SQL |
| --- | --- |
| `function insertDepartment(PDO $pdo, string $name): void {` | `INSERT INTO departments (name) VALUES (:name)` |
| `function updateDepartmentName(PDO $pdo, int $id, string $name): void {` | `UPDATE departments SET name = :name WHERE id = :id` |
| `function updateDepartmentActive(PDO $pdo, int $id, bool $isActive): void {` | `UPDATE departments SET is_active = :is_active WHERE id = :id`（`$isActive ? 1 : 0` を `PDO::PARAM_INT` で渡す） |

- 名前の重複は、DB の一意制約のエラーになる（Claude の処理が受け止めて「同じ名前の部署があります。」と出す）。関数の中で確かめなくてよい。

### 6-4. `insertEmployee()`、`fetchAccounts()`、`updateEmploymentStatus()`（`src/queries/employees.php`）

| 関数 | SQL・内容 |
| --- | --- |
| `function insertEmployee(PDO $pdo, string $employeeId, string $name, string $email, int $departmentId, string $joinedOn): void {` | `INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES (:employee_id, :name, :email, :department_id, :joined_on)` |
| `function fetchAccounts(PDO $pdo): array {` | `SELECT e.employee_id, e.name, e.email, e.joined_on, e.left_on, e.employment_status, d.name AS department_name, l.role, l.approval_scope, l.is_active FROM employees e JOIN departments d ON d.id = e.department_id JOIN login_accounts l ON l.employee_id = e.employee_id ORDER BY e.employee_id` |
| `function updateEmploymentStatus(PDO $pdo, string $employeeId, string $status, ?string $leftOn): void {` | `UPDATE employees SET employment_status = :status, left_on = :left_on WHERE employee_id = :employee_id`（有効に戻すときの `$leftOn` は null） |

- `fetchAccounts()` は、3つの表を JOIN する（社員・部署・ログイン情報）。システム管理者は `employees` に無いので、JOIN で自然に除かれる。

### 6-5. `insertLoginAccount()`、`updateLoginActive()`、`updatePasswordHash()`（`src/queries/login_accounts.php`）

| 関数 | SQL |
| --- | --- |
| `function insertLoginAccount(PDO $pdo, string $employeeId, string $passwordHash, string $role, string $approvalScope): void {` | `INSERT INTO login_accounts (login_id, employee_id, password_hash, role, approval_scope) VALUES (:login_id, :employee_id, :password_hash, :role, :approval_scope)`（`:login_id` と `:employee_id` には、どちらも `$employeeId` を渡す） |
| `function updateLoginActive(PDO $pdo, string $loginId, bool $isActive): void {` | `UPDATE login_accounts SET is_active = :is_active WHERE login_id = :login_id` |
| `function updatePasswordHash(PDO $pdo, string $loginId, string $passwordHash): void {` | `UPDATE login_accounts SET password_hash = :password_hash WHERE login_id = :login_id` |

- `$role` は `'employee'` か `'manager'`、`$approvalScope` は `'department'` か `'all'`（社員のときは `'department'`）を、Claude の処理が渡す。

### 6-6. `updateWorkSettings()`（`src/queries/work_settings.php`）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function updateWorkSettings(PDO $pdo, array $settings): void {` |
| 引数 | `$settings`：`['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00']` |
| SQL | `UPDATE work_settings SET standard_start = :standard_start, standard_end = :standard_end, lunch_start = :lunch_start, lunch_end = :lunch_end WHERE id = 1` |
| もう1つ | `fetchWorkSettings()` の取得する列に `updated_at` を追加する（最終更新日時の表示に使う） |

### 6-7. 確認方法（依頼者の WSL）

ログインはシステム管理者 `sysadmin`。

1. メニューに「アカウント管理」「部署」「勤務設定」が出る。メイン画面にも3つのボタンが出る。
2. 部署：「総務部」を追加 → 一覧に出る（在籍人数 0）。同じ名前をもう一度追加 → エラー。名前を「総務課」に変更 → 一覧が変わる。
3. アカウント管理：総務課・管理者（全社員）で作成 → 「アカウント「2026001」…を作成しました。」。一覧に出る。ログアウトして 2026001 と設定したパスワードでログインできる。メニューに「承認」が出る。
4. もう1人作る → 2026002 になる。メールアドレスを 1 人目と同じにすると、エラー（通番は進まない：次に正しく作ると 2026002）。
5. 2026002 を無効化（退社日を入れる）→ 「退職（日付）」。2026002 でログインできない。「有効に戻す」→ ログインできる。
6. パスワード再設定 → 新しいパスワードでログインでき、古いパスワードではできない。
7. 部署「総務課」を無効化 → アカウント作成の選択欄に出なくなる（所属の社員はそのまま）。
8. 勤務設定：昼休憩を 12:30〜13:30 に変える → 2025001 の記録表示の労働時間が変わる。始業より前の昼休憩など、ルールに合わない値はエラー。**確認が終わったら 12:00〜13:00 に戻す。**
9. 2025002（管理者）で `admin/accounts.php` を開くと、使えない（403）。

- 確認で作ったアカウント・部署は、削除の画面が無い。消したいときは、[DB構築手順書.md](../DB構築手順書.md) の 5-5 の手順で消す（テストデータの状態と勤務設定も元に戻る）。

## 7. Claude の確認（テスト）

- **PHPUnit**：社員コードの作成（1・999・1000・1099・1100・3573・3574 番目）、入力の確認（氏名・メール・部署・入社日・権限・承認範囲・パスワード・確認、部署名、勤務設定の時刻の前後）、一覧の表示（権限・状態の表示）。
- **DB（PHPUnit で、テスト用の DB を使う。環境変数を指定したときだけ）**：依頼者の 12 個の関数。採番（その年の最初・続き・年が変わる、**2つの接続から同時に作っても重複しない**）、アカウント作成（社員とログイン情報ができる、メール重複で何も残らず通番も戻る）、無効化・有効に戻す（3つの列が同時に変わる、途中で失敗したら元に戻る）、パスワード再設定、部署の一覧の在籍人数（退職は数えない、社員のいない部署は 0）、勤務設定の保存。
- **画面（ブラウザ）**：6-7 の流れ、入力の誤りごとのエラーと入力の残り方、承認範囲の出し分け、ウィンドウ（キャンセル・送信）、作成したアカウントでのログイン・無効化したアカウントのログイン中の締め出し、勤務設定の変更が記録表示・簡単残業申請に反映されること、スマホの幅、エスケープ（氏名・部署名に `<script>`）。
- **権限と送信**：社員・管理者・未ログインで3つの画面を開く・POST する（403・ログイン画面へ）、CSRF の無い POST（400）、存在しない社員コード・部署・システム管理者の ID を、画面を通さずに送っても処理されないこと。
- PHP の警告 0 件。PR 3〜6・PR #7 の検証スクリプトの再実行。

## 7-2. Claude の作業結果（2026-10-15、枠のプッシュ時点）

- 追加：`admin/accounts.php`・`admin/departments.php`・`admin/settings.php`（3つの画面）、`js/admin.js`（通知、ウィンドウの開閉、承認範囲の出し分け、誤りのときにウィンドウを開き直す、二重送信の防止）、`src/admin.php`（入力の確認、社員コードの作成、アカウント作成・無効化・有効に戻す・再設定のトランザクション、部署・勤務設定の処理、一覧の表示）、システム管理者のメニューとメイン画面のボタン（`src/auth.php` の `ADMIN_MENU_ITEMS`、`templates/menu.php`、`index.php`）、CSS、テスト（`tests/AdminTest.php`、`tests/AdminDbTest.php`）。
- 枠：`src/queries/employee_id_sequences.php`（1つ）、`src/queries/departments.php`（4つ）、`src/queries/employees.php`（3つ）、`src/queries/login_accounts.php`（3つ）、`src/queries/work_settings.php`（1つと、`fetchWorkSettings()` への `updated_at` の TODO）。枠のままだと、3つの画面はエラーになる（ほかの画面は動く）。勤務設定の画面は、`updated_at` が無くても動く（最終更新日時が出ないだけ）。
- 方針から細かく決めた点：
  - 入社日が先の日付（入社前）のアカウントを無効にするときは、退社日に入社日を指定できる（「入社日〜今日」が空になるため）。
  - 一覧の権限・状態は2行で出す（例：「管理者／全社員」「退職／退社日 2026/10/31」）。PC の幅で一覧が横にはみ出さないようにするため。
  - 部署の在籍人数は、方針の SQL のとおり在籍状態（`employment_status`）で数える。テストデータの 2025004（ログイン不可で、在籍状態は `active` のまま）は、一覧では「退職」と出るが、部署の在籍人数には数えられる（画面で無効化・有効に戻すと、そろう）。
- **DB設計書の採番の例の誤り**：「1099番目＝2026A99、1100番目＝2026B01」は1つずれていた（A01〜A99 は 1000〜1098 番目）。式と上限（3573 人）は正しいので、例を直した。テストで、1〜3573 番目がすべて違う社員コードになることを確かめた。
- 確認（Claude の環境。PHP の組み込みサーバー（4 並列）＋MySQL 8.0.46、ブラウザは Playwright。依頼者の関数は、検証用の仮の中身で実行。コミットしない）：
  - PHPUnit：100 件（DB のテスト 17 件を含む）すべて通過。採番は、別の接続から同じ年の通番を進めようとすると、先のトランザクションが終わるまで待たされ、終わったあとは続きの番号になることを確かめた。トランザクションの途中で失敗したとき（テスト用のトリガー）、社員・採番・在籍状態が元に戻ることも確かめた。
  - ブラウザ（`docs/検証スクリプト/pr7.js`）：90 項目すべて通過（メニューと権限（社員・管理者・未ログイン）、部署（追加・重複・名前の変更・無効化・有効に戻す・エスケープ）、アカウント作成（入力の誤り 7 種類と入力の残り方、承認範囲の出し分け、作成と採番、作ったアカウントでのログイン、無効の部署・システム管理者の権限を画面を通さずに送る）、無効化（退社日の範囲、誤りのときにウィンドウを開き直す、ログインできなくなる、ログイン中の社員も次の操作で締め出される）、有効に戻す、パスワード再設定、勤務設定（入力の誤り、記録表示の労働時間・メイン画面の標準終了時刻への反映）、CSRF、スマホの幅で横にはみ出さない）。
  - PR 3（36）・PR 4（41）・PR 5（57）・PR #7（20）・PR 6（50）のブラウザの確認も、すべて通過。PR 3・PR 4 の確認のうち、システム管理者のメイン画面とメニューの2項目は、PR 7 で変わったため、確認の内容を直した。PHP の警告 0 件。
  - 確認で作ったデータを WSL で消す手順を、[DB構築手順書.md](../DB構築手順書.md) の 5-5 に追加した（Claude の環境で、SQL を実行して確かめた）。

## 7-3. 依頼者のコードのテスト（2026-10-22）

- 依頼者のコミット 97c4bf9（「pr7-1st」）：12 個の関数と、`fetchWorkSettings()` への `updated_at` の追加。書き換えずにテストした。
- 方針 6章の SQL・bindValue の型のとおり。PHPUnit 100 件（DB のテスト 17 件を含む）、ブラウザ 90 項目、PR 3〜6・PR #7 の確認 204 項目、すべて通過。PHP の警告 0 件。1回目で通過。
- PR #9（https://github.com/9r4/nm_attendance/pull/9）を作成。自動で付け足された「Generated by Claude Code」は、更新して削除し、消えたことを確認（D-057）。

## 8. 確認したいこと（2026-10-15 承認。D-067）

1. **範囲と担当**（5章・6章）：依頼者が書くのは 6章の 12 個の関数と、`fetchWorkSettings()` への `updated_at` の追加でよいか（数は多いが、ほとんどが1文の INSERT・UPDATE。初めてなのは GROUP BY・COUNT と、1つの関数で SQL を2つ実行する採番）。アカウント管理と、部署・勤務設定を、**1つの PR にまとめてよいか**（分けると PR が2つになり、やり取りが増える）。
2. **採番の書き方**（3章）：DB設計書の「`SELECT … FOR UPDATE` で +1」ではなく、「`INSERT … ON DUPLICATE KEY UPDATE` で +1 してから読む」に変えてよいか（その年の最初の1人を、同時に作ったときのエラーを防ぐため）。
3. **部署の無効化**（2-2）：在籍している社員がいても無効にできる（アカウント作成の選択欄から消えるだけ）でよいか。名前の変更も作ってよいか。
4. **勤務設定の変更の影響**（2-3）：履歴を持たず、過去の日の労働時間も新しい昼休憩で計算し直される（注意の文で知らせる）でよいか。
5. **システム管理者のメイン画面**（2章）：3つの画面へのボタンを並べてよいか。
6. **細かいルール**：入社日の範囲（2000-01-01 〜 今日の1年後）、退社日の範囲（入社日 〜 今日）、氏名 100 文字・部署名 50 文字（DB の列の長さ）。
