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
    throw new RuntimeException('insertCorrectionRequest は未実装です');
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
    throw new RuntimeException('insertOvertimeRequest は未実装です');
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
    throw new RuntimeException('fetchMonthlyRequests は未実装です');
}
