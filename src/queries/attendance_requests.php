<?php
// 申請（attendance_requests テーブル）を読み書きする関数
// 値は bindValue で渡す（D-060）。社員 ID・日付・日時・理由は、どれも文字列なので、型は PDO::PARAM_STR
// NULL の値（空欄の時刻、理由なし）も、そのまま bindValue(…, PDO::PARAM_STR) で渡せば、DB に NULL が入る

// 修正・新規登録の申請を保存する（状態は DB の既定値の「申請中（pending）」になる）
// $requested・$original：['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => …, 'break_start_at' => …, 'break_end_at' => …]（値は日時か null）
// $requested は申請する値、$original は申請した時点の勤怠の値。$reason は理由か null
function insertCorrectionRequest(PDO $pdo, string $employeeId, string $targetDate, array $requested, array $original, ?string $reason): void {
    // TODO（依頼者）：申請を1行追加する（値が 11 個の INSERT）
    // 1. SQL を書く：
    //    INSERT INTO attendance_requests (employee_id, target_date, request_type,
    //      requested_check_in_at, requested_check_out_at, requested_break_start_at, requested_break_end_at,
    //      original_check_in_at, original_check_out_at, original_break_start_at, original_break_end_at, reason)
    //    VALUES (:employee_id, :target_date, 'correction', :requested_check_in_at, …, :reason)
    //    （request_type は利用者の入力ではないので、SQL に 'correction' と直接書く。status・id・日時は DB が入れるので書かない）
    // 2. $pdo->prepare() で準備する
    // 3. 11 個の値を bindValue で渡す（型は PDO::PARAM_STR）
    //    例：$stmt->bindValue(':requested_check_in_at', $requested['check_in_at'], PDO::PARAM_STR);
    //        $stmt->bindValue(':original_check_in_at', $original['check_in_at'], PDO::PARAM_STR);
    // 4. execute() する
    //    申請中の同じ申請があると、一意制約の違反（BR-002）で例外になる。呼び出し側（src/requests.php）で受け止めるので、try catch は書かない
    // throw new RuntimeException('insertCorrectionRequest は未実装です');
    $sql = 'INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at, requested_check_out_at, requested_break_start_at, requested_break_end_at, original_check_in_at, original_check_out_at, original_break_start_at, original_break_end_at, reason) VALUES (:employee_id, :target_date, \'correction\', :requested_check_in_at, :requested_check_out_at, :requested_break_start_at, :requested_break_end_at, :original_check_in_at, :original_check_out_at, :original_break_start_at, :original_break_end_at, :reason)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
    $stmt->bindValue(':requested_check_in_at', $requested['check_in_at'], PDO::PARAM_STR);
    $stmt->bindValue(':requested_check_out_at', $requested['check_out_at'], PDO::PARAM_STR);
    $stmt->bindValue(':requested_break_start_at', $requested['break_start_at'], PDO::PARAM_STR);
    $stmt->bindValue(':requested_break_end_at', $requested['break_end_at'], PDO::PARAM_STR);
    $stmt->bindValue(':original_check_in_at', $original['check_in_at'], PDO::PARAM_STR);
    $stmt->bindValue(':original_check_out_at', $original['check_out_at'], PDO::PARAM_STR);
    $stmt->bindValue(':original_break_start_at', $original['break_start_at'], PDO::PARAM_STR);
    $stmt->bindValue(':original_break_end_at', $original['break_end_at'], PDO::PARAM_STR);
    $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
    $stmt->execute();
}

// 簡単残業申請を保存する
// $overtimeEndAt：残業の終了時刻（'2026-10-15 19:00:00'）。$originalOvertimeEndAt：申請した時点の勤怠の残業終了時刻か null
function insertOvertimeRequest(PDO $pdo, string $employeeId, string $targetDate, string $overtimeEndAt, ?string $originalOvertimeEndAt): void {
    // TODO（依頼者）：残業申請を1行追加する
    // 1. SQL を書く：
    //    INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_overtime_end_at, original_overtime_end_at)
    //    VALUES (:employee_id, :target_date, 'overtime', :requested_overtime_end_at, :original_overtime_end_at)
    // 2. prepare → 3. bindValue（4 個。型は PDO::PARAM_STR） → 4. execute
    //    （insertCorrectionRequest と同じく、try catch は書かない）
    // throw new RuntimeException('insertOvertimeRequest は未実装です');
    $sql = 'INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_overtime_end_at, original_overtime_end_at) VALUES (:employee_id, :target_date, \'overtime\', :requested_overtime_end_at, :original_overtime_end_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
    $stmt->bindValue(':requested_overtime_end_at', $overtimeEndAt, PDO::PARAM_STR);
    $stmt->bindValue(':original_overtime_end_at', $originalOvertimeEndAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 社員の、期間（初日〜末日）の申請を、対象日・申請の順に全部取得する（記録表示で使う）
// 戻り値：行の配列。記録が無ければ []
function fetchMonthlyRequests(PDO $pdo, string $employeeId, string $fromDate, string $toDate): array {
    // TODO（依頼者）：期間の申請を、複数行まとめて取得して返す（PR 4 の fetchMonthlyAttendances と同じ形）
    // 1. SQL を書く：attendance_requests から次の列を取得
    //    id, target_date, request_type,
    //    requested_check_in_at, requested_check_out_at, requested_break_start_at, requested_break_end_at, requested_overtime_end_at,
    //    original_check_in_at, original_check_out_at, original_break_start_at, original_break_end_at, original_overtime_end_at,
    //    reason, status, review_comment, created_at
    //    条件：employee_id = :employee_id AND target_date BETWEEN :from_date AND :to_date
    //    並び順：ORDER BY target_date, created_at, id
    // 2. prepare → 3. bindValue（3 個） → 4. execute して fetchAll()
    // 5. 取得した配列を、そのまま return する
    // throw new RuntimeException('fetchMonthlyRequests は未実装です');
    $sql = 'SELECT * FROM attendance_requests WHERE employee_id = :employee_id AND target_date BETWEEN :from_date AND :to_date ORDER BY target_date, created_at, id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// ---- PR 6（承認）：管理者が使う関数 ----
// 申請の ID・件数は数値なので PDO::PARAM_INT、それ以外は PDO::PARAM_STR

// 承認範囲の、申請中の申請を、古い順に全部取得する（承認画面の「未処理」）
// $approverId：承認する管理者の社員 ID（自分の申請を除く）。$departmentId：承認範囲が「部署」なら管理者の部署 ID、「全社」なら null
// 戻り値：行の配列。申請の列に加えて name（申請者の氏名）、department_name（部署名）、current_check_out_at（その日の今の退勤時刻。勤怠が無ければ null）
function fetchPendingRequests(PDO $pdo, string $approverId, ?int $departmentId): array {
    // TODO（依頼者）：申請中の申請を取得する（初めての LEFT JOIN と、条件によって足す SQL）
    // 1. SQL を書く：
    //    SELECT r.id, r.employee_id, r.target_date, r.request_type,
    //      r.requested_check_in_at, r.requested_check_out_at, r.requested_break_start_at, r.requested_break_end_at, r.requested_overtime_end_at,
    //      r.original_check_in_at, r.original_check_out_at, r.original_break_start_at, r.original_break_end_at, r.original_overtime_end_at,
    //      r.reason, r.created_at, e.name, d.name AS department_name, a.check_out_at AS current_check_out_at
    //    FROM attendance_requests r
    //    JOIN employees e ON e.employee_id = r.employee_id
    //    JOIN departments d ON d.id = e.department_id
    //    LEFT JOIN attendances a ON a.employee_id = r.employee_id AND a.work_date = r.target_date
    //    WHERE r.status = 'pending' AND r.employee_id <> :approver_id
    //    （LEFT JOIN：その日の勤怠が無い申請（新規登録）も残す。勤怠の列は NULL になる）
    // 2. $departmentId が null でなければ、SQL に ' AND e.department_id = :department_id' を足す（値は必ずプレースホルダで渡す）
    // 3. 最後に ' ORDER BY r.created_at, r.id' を足す
    // 4. prepare → bindValue（:approver_id は PARAM_STR。$departmentId が null でなければ :department_id も。こちらは PARAM_INT）
    //    （SQL に無いプレースホルダを bindValue すると、エラーになる。部署の条件を足したときだけ渡す）
    // 5. execute して fetchAll() の結果を返す
    throw new RuntimeException('fetchPendingRequests は未実装です');
}

// 承認範囲の、承認・却下された申請を、新しい順に $limit 件取得する（承認画面の「処理済み」）
// 戻り値：行の配列。申請の列・申請者の列に加えて status、review_comment、reviewed_at、reviewer_name（処理した管理者の氏名）
function fetchProcessedRequests(PDO $pdo, string $approverId, ?int $departmentId, int $limit): array {
    // TODO（依頼者）：処理済みの申請を取得する（同じ表を2回 JOIN する）
    // 1. SQL を書く：fetchPendingRequests と同じ申請の列・申請者の列（current_check_out_at は不要）に加えて
    //    r.status, r.review_comment, r.reviewed_at, rv.name AS reviewer_name
    //    FROM attendance_requests r
    //    JOIN employees e ON e.employee_id = r.employee_id
    //    JOIN departments d ON d.id = e.department_id
    //    LEFT JOIN employees rv ON rv.employee_id = r.reviewer_id   ← 処理した管理者（employees をもう一度。別名 rv で区別）
    //    WHERE r.status <> 'pending' AND r.employee_id <> :approver_id
    // 2. $departmentId が null でなければ ' AND e.department_id = :department_id' を足す
    // 3. 最後に ' ORDER BY r.reviewed_at DESC, r.id DESC LIMIT :limit' を足す
    // 4. prepare → bindValue（:limit は数値なので PDO::PARAM_INT）
    // 5. execute して fetchAll() の結果を返す
    throw new RuntimeException('fetchProcessedRequests は未実装です');
}

// 承認・却下の前に、申請を1件取得して、行をロックする（トランザクションの中で呼ぶ。トランザクションは呼び出し側が行う）
// 戻り値：['id', 'employee_id', 'target_date', 'request_type', 'requested_*'（5列）, 'status', 'name', 'department_id']。見つからなければ null
function fetchRequestForUpdate(PDO $pdo, int $requestId): ?array {
    // TODO（依頼者）：申請を取得して、ロックする（初めての FOR UPDATE）
    // 1. SQL を書く：
    //    SELECT r.id, r.employee_id, r.target_date, r.request_type,
    //      r.requested_check_in_at, r.requested_check_out_at, r.requested_break_start_at, r.requested_break_end_at, r.requested_overtime_end_at,
    //      r.status, e.name, e.department_id
    //    FROM attendance_requests r JOIN employees e ON e.employee_id = r.employee_id
    //    WHERE r.id = :id FOR UPDATE
    //    （末尾の FOR UPDATE：トランザクションが終わるまで、ほかの人がこの申請を変えられないようにする。2人が同時に承認しても、二重に処理しない）
    // 2. prepare → bindValue（:id は PDO::PARAM_INT） → execute → fetch()
    // 3. 見つからなければ null、見つかればその行を返す
    throw new RuntimeException('fetchRequestForUpdate は未実装です');
}

// 申請を「承認済み（approved）」か「却下（rejected）」にする（処理した管理者・日時・コメントを記録）
// $status：'approved' か 'rejected'（呼び出し側が渡す）。$comment：却下コメントか null（承認のときは null）
function updateRequestStatus(PDO $pdo, int $requestId, string $status, string $reviewerId, ?string $comment): void {
    // TODO（依頼者）：申請の状態を更新する
    // 1. SQL を書く：
    //    UPDATE attendance_requests SET status = :status, reviewer_id = :reviewer_id, review_comment = :review_comment, reviewed_at = NOW()
    //    WHERE id = :id AND status = 'pending'
    //    （status = 'pending' の条件で、処理済みの申請を二重に更新しない）
    // 2. prepare → bindValue（:id は PARAM_INT、ほかは PARAM_STR。null の comment もそのまま渡せる） → execute
    throw new RuntimeException('updateRequestStatus は未実装です');
}

