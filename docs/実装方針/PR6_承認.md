# 実装 PR 6：承認（一覧、承認・却下、承認範囲）

状態: **承認待ち**（2026-10-15 作成）。7章の確認事項への回答をもらってから、作業ブランチ **`claude/pr6-approvals`** に枠をプッシュする。

## 1. 目的

- 管理者（`manager`）が、社員の申請（修正・新規登録、残業）を一覧で見て、**承認**（勤怠に反映）または**却下**（コメント任意）できる（FR-006、D-010、D-016、D-024、D-025）。
- 承認できるのは、**承認範囲**（自分の部署／全社）の申請だけ。**自分の申請は承認できない**。
- 1つの画面で、PC はリスト型、スマホはカード型に切り替える（D-052）。
- 依頼者が、初めての **LEFT JOIN**・**条件によって変わる SQL**・**INSERT … ON DUPLICATE KEY UPDATE**・**SELECT … FOR UPDATE** と、トランザクションの中で使う関数を書く。

画面モックの確認結果は [画面モック確認結果.md](../画面モック確認結果.md) の 4章（差異 10〜14）。

## 2. 画面と動き

URL は `approvals/`（[技術方針.md](../技術方針.md) 2-1）。使えるのは管理者だけ（社員・システム管理者は 403）。ハンバーガーメニューに「承認」（管理者のみ）を追加する。

| 部分 | 内容 |
| --- | --- |
| タブ | 「未処理（件数）」「処理済み（件数）」（画面モックのとおり） |
| 未処理 | 承認範囲の**申請中**の申請。**古い順**（先に出された申請から）。件数の上限なし |
| 処理済み | 承認範囲の、承認・却下された申請。**新しい順に 50 件** |
| 1件の内容 | 申請者（氏名・部署）、種別（修正／新規登録／残業）、対象日、**変わった項目だけ「変更前 → 申請値」**（利用者の画面と同じ。差異 11）、理由、申請日時 |
| 残業の申請 | 「残業終了 なし → 19:30」に加えて、判断の材料として「標準終了 18:00・今の退勤 19:45」を添える（差異 12） |
| 未処理の操作 | 「承認」「却下」のボタン |
| 処理済みの表示 | 状態のバッジ（承認済み・却下）、処理した管理者の氏名と日時、却下コメント（差異 13） |
| 承認 | 押すとすぐに反映し、「○○ さんの申請を承認し、勤怠に反映しました。」（画面モックのとおり、確認なし） |
| 却下 | 「申請を却下します」のウィンドウ（対象の申請者・種別・対象日、コメント（任意・500 文字まで）、「キャンセル」「却下する」）。スマホでは画面の下から出す |
| PC とスマホ | 1つの画面で、画面の幅（768px 未満）でカード型に切り替える（D-052） |

- 承認・却下は、画面を切り替えずに JavaScript から送り（`api/approvals.php`）、結果の通知を出して一覧を読み込み直す。
- 利用者の画面（記録表示）では、承認されると勤怠の時刻とバッジ（承認済み）が変わり、却下されるとバッジ（却下）と却下コメントが出る（PR 5 で表示の準備済み）。

### 2-1. 申請中の打刻の制限と、申請の取り消し（2026-10-15 依頼者の指摘を受けて追加 → PR 6 とは別に、PR #7 で Claude が実装。D-063）

**起きる問題**：今日の分の修正申請（例：20 時に「退勤 19:00」で申請）を出したあとに、メイン画面で退勤ボタンを押すと、勤怠が「退勤 20:00」に変わる。そのあと申請が承認されると、申請の値（19:00）で上書きされる。押した打刻が消えたり、どちらが正しいか分からなくなったりする。

**対応**：

| 項目 | 内容 |
| --- | --- |
| 打刻の制限 | **今日の分の修正・新規登録が申請中の間は、メイン画面の出勤・退勤・休憩開始・休憩終了を押せなくする**（サーバーでも止める）。メイン画面に「本日の修正申請が申請中のため、打刻できません。打刻する場合は、記録表示で申請を取り消してください。」と出す。申請は4つの時刻の全体を持つため（P-4）、どのボタンも対象にする |
| 残業の申請中 | 打刻は止めない（残業の申請は退勤時刻を変えず、退勤を押し直しても、退勤は後ろにずれるだけで、申請の範囲（退勤時刻まで）を外れないため） |
| 申請の取り消し | 自分の**申請中**の申請（修正・新規登録・残業）を、記録表示の日付のウィンドウの「取り消す」ボタンで取り消せる（確認のウィンドウを出す）。取り消した申請は、**削除する**（承認・却下されていないので、履歴に残す必要はない。DB の変更が要らない）。承認・却下されたものは取り消せない |
| 取り消したあと | 打刻ができるようになり、同じ日に新しい申請も出せる。管理者が取り消しと同時に承認・却下しようとした場合は、先に処理された方が有効（もう一方は「この申請は、すでに処理されています。」） |

- D-062 の「取り下げの機能は作らない」を変更した（D-063）。依頼者の了承を得て、この件は PR 6 とは別の PR（#7。[実装方針/PR5補足_申請中の打刻の制限と取り消し.md](./PR5補足_申請中の打刻の制限と取り消し.md)）で、DB の関数も含めて Claude が実装する。PR 6 の範囲から外す。

## 3. 承認・却下の処理（サーバー）

**承認**（1つのトランザクションで行う。途中で失敗したら、すべて元に戻す）：

1. 申請を取得し、**行をロックする**（`SELECT … FOR UPDATE`。2人の管理者が同時に押しても、二重に処理しない）。
2. 確認する：申請中であること（すでに処理済みなら「この申請は、すでに処理されています。」）、承認範囲であること、自分の申請でないこと。
3. 勤怠に反映する：
   - 修正・新規登録：その日の**4つの時刻をすべて申請の値にする**（DB設計書 P-4）。その日の行が無ければ作る（`INSERT … ON DUPLICATE KEY UPDATE`）。
   - 残業：その日の `overtime_end_at` を申請の値にする（DB設計書 P-6）。
4. 申請を「承認済み」にする（承認した管理者・日時を記録）。

**却下**：1〜2 と同じ確認のあと、申請を「却下」にする（処理した管理者・日時・コメントを記録）。勤怠は変わらない。

- 申請のあとに勤怠が変わることは、2-1 の打刻の制限で起きないようにする（今日の分の修正・新規登録の申請中は、打刻できない）。
- 承認・却下されると、その日・その種別の申請中が無くなるので、利用者は新しい申請を出せる（BR-002 の一意制約は、申請中だけが対象）。

## 4. 変更するファイル

| ファイル | 内容 | 担当 |
| --- | --- | --- |
| **`src/queries/attendance_requests.php`** | **`fetchPendingRequests()`、`fetchProcessedRequests()`、`fetchRequestForUpdate()`、`updateRequestStatus()`** | **依頼者**（枠は Claude） |
| **`src/queries/attendances.php`** | **`upsertAttendanceTimes()`、`upsertOvertimeEnd()`** | **依頼者**（枠は Claude） |
| **`src/queries/employees.php`** | **`fetchEmployeeProfile()` の取得する列に、部署 ID（`e.department_id`）を追加** | **依頼者** |
| `src/approvals.php`（新規） | 承認範囲の判断、承認・却下の処理（トランザクション）、一覧の表示の組み立て | Claude |
| `api/approvals.php`（新規） | 承認・却下の受け口（POST＋CSRF のみ、JSON） | Claude |
| `approvals/index.php`、`js/approvals.js`（新規） | 承認画面（タブ・一覧・却下のウィンドウ） | Claude |
| `templates/menu.php`、`src/auth.php` | メニューに「承認」（管理者のみ）、ログイン中の利用者に部署 ID を追加 | Claude |
| `css/style.css`、`tests/` | 画面のスタイル、承認範囲・表示の組み立てのテスト | Claude |

- DB の変更・Apache の設定の変更は不要。

## 5. 依頼者が書くもの（仕様）

値は `bindValue` で渡す（D-060）。申請の ID・件数は数値なので `PDO::PARAM_INT`、それ以外は `PDO::PARAM_STR`。関数の `{` は関数名と同じ行（D-056）。枠には、処理の流れのコメントを書いておく（D-054）。トランザクション（`beginTransaction()`・`commit()`・`rollBack()`）は Claude の処理が行うので、関数の中には書かない。

### 5-1. `fetchPendingRequests()`（未処理の一覧）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchPendingRequests(PDO $pdo, string $approverId, ?int $departmentId): array {` |
| 引数 | `$approverId`：承認する管理者の社員 ID（自分の申請を除くため）。`$departmentId`：承認範囲が「部署」なら管理者の部署 ID、「全社」なら null |
| SQL | 申請 `attendance_requests r` に、申請者 `employees e`・部署 `departments d` を JOIN し、その日の勤怠 `attendances a` を **LEFT JOIN**（勤怠が無い日もあるため）。条件：`r.status = 'pending' AND r.employee_id <> :approver_id`。`$departmentId` が null でなければ `AND e.department_id = :department_id` を**付け足す**。並び順：`ORDER BY r.created_at, r.id` |
| 取得する列 | `r.id, r.employee_id, r.target_date, r.request_type`、`requested_*` と `original_*`（各5列）、`r.reason, r.created_at`、`e.name, d.name AS department_name`、今の退勤時刻 `a.check_out_at AS current_check_out_at`（残業の申請で、判断の材料として出す） |
| ポイント | **LEFT JOIN** は、右の表（勤怠）に行が無くても、左の表（申請）の行を残す（無い列は NULL）。LEFT JOIN の結ぶ条件は2つ：`a.employee_id = r.employee_id AND a.work_date = r.target_date`。**条件によって SQL を足す**ときも、値は必ずプレースホルダで渡す（文字列を直接つなげない） |

### 5-2. `fetchProcessedRequests()`（処理済みの一覧）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchProcessedRequests(PDO $pdo, string $approverId, ?int $departmentId, int $limit): array {` |
| SQL | 5-1 と同じ JOIN（勤怠の LEFT JOIN は不要）に、処理した管理者 `employees rv` を **LEFT JOIN**（`rv.employee_id = r.reviewer_id`）。条件：`r.status <> 'pending' AND r.employee_id <> :approver_id`（＋部署の条件）。並び順：`ORDER BY r.reviewed_at DESC, r.id DESC`。件数：`LIMIT :limit` |
| 取得する列 | 5-1 の申請の列と申請者の列に加えて、`r.status, r.review_comment, r.reviewed_at, rv.name AS reviewer_name` |
| ポイント | 同じ表（`employees`）を2回 JOIN するので、別名（`e` と `rv`）で区別する。`:limit` は数値なので `PDO::PARAM_INT`（初めての INT の bindValue） |

### 5-3. `fetchRequestForUpdate()`（承認・却下の前に、申請を取得してロック）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function fetchRequestForUpdate(PDO $pdo, int $requestId): ?array {` |
| SQL | `SELECT r.id, r.employee_id, r.target_date, r.request_type, r.requested_check_in_at, r.requested_check_out_at, r.requested_break_start_at, r.requested_break_end_at, r.requested_overtime_end_at, r.status, e.name, e.department_id FROM attendance_requests r JOIN employees e ON e.employee_id = r.employee_id WHERE r.id = :id FOR UPDATE` |
| 戻り値 | 見つかればその行、無ければ null |
| ポイント | 末尾の **`FOR UPDATE`** で、トランザクションが終わるまで、ほかの人がこの申請を変えられないようにする |

### 5-4. `updateRequestStatus()`（申請を承認済み・却下にする）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function updateRequestStatus(PDO $pdo, int $requestId, string $status, string $reviewerId, ?string $comment): void {` |
| SQL | `UPDATE attendance_requests SET status = :status, reviewer_id = :reviewer_id, review_comment = :review_comment, reviewed_at = NOW() WHERE id = :id AND status = 'pending'` |
| ポイント | `$status` は `'approved'` か `'rejected'`（Claude の処理が渡す）。承認のときの `$comment` は null |

### 5-5. `upsertAttendanceTimes()`（修正・新規登録の承認で、勤怠の4つの時刻を申請の値にする）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function upsertAttendanceTimes(PDO $pdo, string $employeeId, string $workDate, array $times): void {` |
| 引数 | `$times`：`['check_in_at' => …, 'check_out_at' => …, 'break_start_at' => …, 'break_end_at' => …]`（値は日時か null） |
| SQL | `INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES (:employee_id, :work_date, :check_in_at, :check_out_at, :break_start_at, :break_end_at) AS new ON DUPLICATE KEY UPDATE check_in_at = new.check_in_at, check_out_at = new.check_out_at, break_start_at = new.break_start_at, break_end_at = new.break_end_at` |
| ポイント | **INSERT … ON DUPLICATE KEY UPDATE**：その日の行が無ければ追加し、あれば（一意制約 `employee_id, work_date` に当たれば）更新する。`AS new` は、追加しようとした値に付ける別名（MySQL 8.0.19 以降の書き方） |

### 5-6. `upsertOvertimeEnd()`（残業の承認で、承認済みの残業終了時刻を記録する）

| 項目 | 内容 |
| --- | --- |
| 関数 | `function upsertOvertimeEnd(PDO $pdo, string $employeeId, string $workDate, string $overtimeEndAt): void {` |
| SQL | `INSERT INTO attendances (employee_id, work_date, overtime_end_at) VALUES (:employee_id, :work_date, :overtime_end_at) AS new ON DUPLICATE KEY UPDATE overtime_end_at = new.overtime_end_at` |

### 5-7. `fetchEmployeeProfile()` の変更（`src/queries/employees.php`）

- SELECT する列に `e.department_id` を追加する（承認範囲が「部署」の管理者の、部署の判断に使う）。

### 5-8. 確認方法（依頼者の WSL）

テストデータ：2025001（山田・営業部・社員）、2025002（佐藤・営業部・管理者・承認範囲は部署）、2025003（鈴木・開発部・管理者・承認範囲は全社）。

1. 2025001 で、修正の申請と残業の申請を出す（PR 5）。
2. 2025002 でログイン →「≡」→「承認」→ 未処理に 2025001 の申請が出る。修正を承認 → 2025001 の記録表示で、時刻が変わり「承認済み」になる。
3. 残業の申請を、コメントを付けて却下 → 2025001 の記録表示で「却下」とコメントが出る。処理済みのタブに、処理した人（佐藤 花子）と日時が出る。
4. 2025002 で修正の申請を出す → 2025002 の未処理には出ない（自分の申請）。2025003（全社）でログインすると出て、承認できる。
5. 2025003（開発部）で申請を出す → 2025002（営業部・承認範囲は部署）の未処理には出ない。
6. 2025001（社員）で `approvals/` を開くと、使えない（403）。メニューにも「承認」が出ない。

## 6. Claude の確認（テスト）

- **PHPUnit**：承認範囲の判断（部署・全社・自分の申請・社員・システム管理者）、一覧の表示の組み立て（変更前 → 申請値、残業の参考、申請のあとの変化の注意）。
- **DB（PHPUnit で、テスト用の DB を使う）**：承認で勤怠が申請の値になる（行が無い日は作る・ある日は4つの時刻を置き換える・残業）、申請が承認済みになり処理した管理者と日時が入る、却下では勤怠が変わらない、途中で失敗したら何も変わらない（トランザクション）、2人が同時に承認しても1回だけ処理される。
- **画面（ブラウザ）**：5-8 の流れ、タブと件数、却下のウィンドウ（キャンセル・コメントあり・なし）、すでに処理された申請を押したとき、PC・スマホの幅、エスケープ（理由・コメントに `<script>`）。
- **API**：GET（405）、CSRF（400）、未ログイン（401）、社員・システム管理者（403）、承認範囲の外の申請・自分の申請・存在しない申請を、画面を通さずに送っても処理されないこと。
- PHP の警告 0 件、PR 3〜5 のブラウザの確認の再実行。

## 7. 確認したいこと

1. **範囲と担当**（4章・5章）：依頼者が書くのは 5章の6つの関数と、`fetchEmployeeProfile()` への部署 ID の追加でよいか（これまでより多い。LEFT JOIN・条件で足す SQL・ON DUPLICATE KEY UPDATE・FOR UPDATE が初めて）。
2. **一覧の内容**（2章、差異 10・13）：未処理は承認範囲の申請中を古い順に全部、処理済みは新しい順に 50 件。自分の申請は出さない。処理済みには、処理した管理者と日時も出す。
3. **内容の出し方**（差異 11・12）：画面モックの「前／後」（出勤・退勤の全体）ではなく、利用者の画面と同じく変わった項目だけ「変更前 → 申請値」。残業は、標準終了時刻と今の退勤時刻を添える。
4. **承認は確認なし**（画面モックのとおり）、却下はコメント任意のウィンドウ。
5. ~~申請中の打刻の制限と、申請の取り消し~~ → 決定（D-063）。PR 6 とは別に、PR #7 で Claude が実装。一覧の「申請のあとに勤怠が変わった」注意は作らない。
