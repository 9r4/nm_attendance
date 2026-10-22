<?php
// 承認（FR-006、D-016、D-024、D-025、D-064）：承認範囲の判断、承認・却下の処理、一覧の表示の組み立て
// 承認できるのは管理者（manager）だけ。範囲は自分の部署（department）か全社（all）。自分の申請は承認できない

include_once(__DIR__ . '/requests.php');
include_once(__DIR__ . '/queries/attendances.php');

// 処理済みの一覧に出す件数（D-064）
const APPROVAL_PROCESSED_LIMIT = 50;

const APPROVAL_COMMENT_MAX_LENGTH = 500;

// 一覧の取得で使う、承認範囲の部署 ID（「部署」なら管理者の部署 ID、「全社」なら null）
function approvalDepartmentId(array $user): ?int {
    if ($user['approval_scope'] === 'all') {
        return null;
    }
    if ($user['department_id'] === null) {
        throw new RuntimeException('管理者の部署を取得できません: ' . $user['employee_id']);
    }
    return $user['department_id'];
}

// この管理者が、この申請を承認・却下できるか（$request は fetchRequestForUpdate() の行。department_id は申請者の部署）
function canReview(array $user, array $request): bool {
    if ($user['role'] !== ROLE_MANAGER || $request['employee_id'] === $user['employee_id']) {
        return false;
    }
    return $user['approval_scope'] === 'all' || (int) $request['department_id'] === $user['department_id'];
}

// 一覧の1件の表示（未処理・処理済みとも）。$standardEnd：標準終了時刻（残業の判断の材料に出す）
function approvalItemView(array $row, string $standardEnd): array {
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $time = strtotime($row['target_date']);
    $view = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'department' => $row['department_name'],
        'type' => requestTypeLabel($row),
        'targetDate' => date('n/j', $time) . '（' . $weekdays[(int) date('w', $time)] . '）',
        'changes' => requestChanges($row),
        'reason' => $row['reason'] ?? '',
        'createdAt' => date('n/j H:i', strtotime($row['created_at'])),
        'reference' => '',
    ];
    // 残業は、判断の材料として標準終了時刻と今の退勤時刻を添える（差異 12）
    if ($row['request_type'] === 'overtime' && array_key_exists('current_check_out_at', $row)) {
        $view['reference'] = '標準終了 ' . substr($standardEnd, 0, 5) . '・今の退勤 ' . formatClock($row['current_check_out_at']);
    }
    // 処理済み：状態、処理した管理者と日時、却下コメント（差異 13）
    if (isset($row['status']) && $row['status'] !== 'pending') {
        $view['status'] = $row['status'];
        $view['statusLabel'] = REQUEST_STATUS_LABELS[$row['status']];
        $view['reviewerName'] = $row['reviewer_name'] ?? '';
        $view['reviewedAt'] = $row['reviewed_at'] === null ? '' : date('n/j H:i', strtotime($row['reviewed_at']));
        $view['reviewComment'] = $row['review_comment'] ?? '';
    }
    return $view;
}

// 申請を承認・却下する。$decision：'approve' か 'reject'。$comment：却下コメント（任意）
// 承認は1つのトランザクションで行う：申請をロック → 申請中・承認範囲・自分でないことを確認 → 勤怠に反映 → 承認済みに
// 戻り値：['ok' => true, 'message' => …] か ['ok' => false, 'errors' => ['form' => …]]
function reviewRequest(PDO $pdo, array $user, int $requestId, string $decision, string $comment): array {
    if ($decision !== 'approve' && $decision !== 'reject') {
        throw new InvalidArgumentException('知らない操作です: ' . $decision);
    }
    $comment = trim($comment);
    // コメントを使うのは却下のときだけ。承認のときは、長さを確かめない（D-069）
    if ($decision === 'reject' && mb_strlen($comment) > APPROVAL_COMMENT_MAX_LENGTH) {
        return ['ok' => false, 'errors' => ['comment' => 'コメントは ' . APPROVAL_COMMENT_MAX_LENGTH . ' 文字までにしてください。']];
    }

    $pdo->beginTransaction();
    try {
        $request = fetchRequestForUpdate($pdo, $requestId);
        $error = null;
        if ($request === null) {
            $error = 'この申請は見つかりません。取り消された可能性があります。画面を読み込み直してください。';
        } elseif ($request['status'] !== 'pending') {
            $error = 'この申請は、すでに処理されています。画面を読み込み直してください。';
        } elseif (!canReview($user, $request)) {
            $error = 'この申請を処理する権限がありません。';
        }
        if ($error !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['form' => $error]];
        }

        if ($decision === 'approve') {
            if ($request['request_type'] === 'overtime') {
                upsertOvertimeEnd($pdo, $request['employee_id'], $request['target_date'], $request['requested_overtime_end_at']);
            } else {
                // 修正・新規登録は、4つの時刻をすべて申請の値にする（DB設計書 P-4）
                $times = [];
                foreach (array_keys(REQUEST_TIME_FIELDS) as $field) {
                    $times[$field] = $request['requested_' . $field];
                }
                upsertAttendanceTimes($pdo, $request['employee_id'], $request['target_date'], $times);
            }
            updateRequestStatus($pdo, $requestId, 'approved', $user['employee_id'], null);
            $message = $request['name'] . ' さんの申請を承認し、勤怠に反映しました。';
        } else {
            updateRequestStatus($pdo, $requestId, 'rejected', $user['employee_id'], $comment === '' ? null : $comment);
            $message = $request['name'] . ' さんの申請を却下しました。';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        // 途中で失敗したら、すべて元に戻す
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'message' => $message];
}
