<?php
// 勤怠（attendances テーブル）を読み書きする関数
// 引数の形：$workDate は '2026-10-15'、時刻（$checkInAt など）は '2026-10-15 09:01:00'（どちらも呼び出し側が作って渡す）
// 値は bindValue で渡す（D-060）。社員 ID・日付・日時は、どれも文字列なので、型は PDO::PARAM_STR

// 社員の、ある日の勤怠を取得する
// 戻り値：['check_in_at' => '2026-10-15 09:01:00', 'check_out_at' => null, 'break_start_at' => null, 'break_end_at' => null]。見つからなければ null
function fetchAttendance(PDO $pdo, string $employeeId, string $workDate): ?array {
    // TODO（依頼者）：勤怠を1行取得して返す
    // 1. SQL を書く：attendances から check_in_at, check_out_at, break_start_at, break_end_at を取得
    //    条件は2つ：employee_id = :employee_id AND work_date = :work_date
    // 2. $pdo->prepare() で準備する
    // 3. :employee_id に $employeeId、:work_date に $workDate を渡す（bindValue。型は PDO::PARAM_STR）
    // 4. execute() して、fetch() で1行取得する（1社員×1日＝1行なので、1行だけ）
    // 5. 見つからなかったら null、見つかったらその行を返す（return $row === false ? null : $row;）
    // throw new RuntimeException('fetchAttendance は未実装です');

    $sql = 'SELECT check_in_at, check_out_at, break_start_at, break_end_at FROM attendances WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// その日の行を作って、出勤時刻を記録する
function insertCheckIn(PDO $pdo, string $employeeId, string $workDate, string $checkInAt): void {
    // TODO（依頼者）：勤怠の行を追加する（初めての INSERT）
    // 1. SQL を書く：INSERT INTO attendances (employee_id, work_date, check_in_at) VALUES (:employee_id, :work_date, :check_in_at)
    //    （id・created_at・updated_at は DB が自動で入れるので、書かない）
    // 2. $pdo->prepare() で準備する
    // 3. 3つの値を bindValue で渡す（型は PDO::PARAM_STR）
    // 4. execute() する（戻り値は無い）
    //    同じ日の行がすでにあると、一意制約の違反で例外になる。呼び出し側（src/attendance.php）で受け止めるので、ここで try catch は書かない
    // throw new RuntimeException('insertCheckIn は未実装です');

    $sql = 'INSERT INTO attendances (employee_id, work_date, check_in_at) VALUES (:employee_id, :work_date, :check_in_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 出勤時刻を更新する（上書き）
function updateCheckIn(PDO $pdo, string $employeeId, string $workDate, string $checkInAt): void {
    // TODO（依頼者）：check_in_at を更新する
    // 1. SQL を書く：UPDATE attendances SET check_in_at = :check_in_at WHERE employee_id = :employee_id AND work_date = :work_date
    //    （WHERE を忘れると、全員・全日の行が更新される。必ず2つの条件を付ける。updated_at は DB が自動で更新する）
    // 2. $pdo->prepare() で準備する
    // 3. 3つの値を bindValue で渡す（型は PDO::PARAM_STR）
    // 4. execute() する（UPDATE なので、fetch() はしない）
    // throw new RuntimeException('updateCheckIn は未実装です');
    $sql = 'UPDATE attendances SET check_in_at = :check_in_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 退勤時刻を記録する（記録済みのときは上書き）
function updateCheckOut(PDO $pdo, string $employeeId, string $workDate, string $checkOutAt): void {
    // TODO（依頼者）：check_out_at を更新する（updateCheckIn と同じ形。列名とプレースホルダの名前だけが違う）
    // 1. SQL を書く：UPDATE attendances SET check_out_at = :check_out_at WHERE employee_id = :employee_id AND work_date = :work_date
    // 2. prepare → 3. bindValue（3つ） → 4. execute
    // throw new RuntimeException('updateCheckOut は未実装です');
    $sql = 'UPDATE attendances SET check_out_at = :check_out_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':check_out_at', $checkOutAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 休憩開始の時刻を記録する
function updateBreakStart(PDO $pdo, string $employeeId, string $workDate, string $breakStartAt): void {
    // TODO（依頼者）：break_start_at を更新する（updateCheckIn と同じ形）
    // 1. SQL を書く：UPDATE attendances SET break_start_at = :break_start_at WHERE employee_id = :employee_id AND work_date = :work_date
    // 2. prepare → 3. bindValue（3つ） → 4. execute
    // throw new RuntimeException('updateBreakStart は未実装です');
    $sql = 'UPDATE attendances SET break_start_at = :break_start_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':break_start_at', $breakStartAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 休憩終了の時刻を記録する
function updateBreakEnd(PDO $pdo, string $employeeId, string $workDate, string $breakEndAt): void {
    // TODO（依頼者）：break_end_at を更新する（updateCheckIn と同じ形）
    // 1. SQL を書く：UPDATE attendances SET break_end_at = :break_end_at WHERE employee_id = :employee_id AND work_date = :work_date
    // 2. prepare → 3. bindValue（3つ） → 4. execute
    // throw new RuntimeException('updateBreakEnd は未実装です');
    $sql = 'UPDATE attendances SET break_end_at = :break_end_at WHERE employee_id = :employee_id AND work_date = :work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->bindValue(':break_end_at', $breakEndAt, PDO::PARAM_STR);
    $stmt->execute();
}

// 社員の、期間（初日〜末日）の勤怠を、日付の順に全部取得する（記録表示で使う）
// 戻り値：[['work_date' => '2026-10-01', 'check_in_at' => '2026-10-01 09:01:00', 'check_out_at' => …, 'break_start_at' => …, 'break_end_at' => …], …]。記録が無ければ []
function fetchMonthlyAttendances(PDO $pdo, string $employeeId, string $fromDate, string $toDate): array {
    // TODO（依頼者）：期間の勤怠を、複数行まとめて取得して返す（初めての fetchAll）
    // 1. SQL を書く：attendances から work_date, check_in_at, check_out_at, break_start_at, break_end_at を取得
    //    条件：employee_id = :employee_id AND work_date BETWEEN :from_date AND :to_date（BETWEEN は両端の日を含む）
    //    並び順：ORDER BY work_date（日付の古い順）
    // 2. $pdo->prepare() で準備する
    // 3. 3つの値を bindValue で渡す（型は PDO::PARAM_STR）
    // 4. execute() して、fetchAll() で全部の行を取得する（fetch() は1行だけなので、ここでは使わない）
    // 5. 取得した配列を、そのまま return する（行が無いときは、fetchAll() が空の配列 [] を返すので、null にしない）
    $sql = 'SELECT work_date, check_in_at, check_out_at, break_start_at, break_end_at FROM attendances WHERE employee_id = :employee_id AND work_date BETWEEN :from_date AND :to_date ORDER BY work_date';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
