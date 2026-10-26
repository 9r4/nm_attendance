<?php
// 社員の勤怠（D-070）：対象社員の一覧と、今日の勤怠（状態・出勤・退勤・休憩・労働時間）。管理者だけが使える
// 対象社員は承認範囲と同じ（自分の部署／全社）。自分と退職した社員は出さない。「詳細」で、その社員の月の記録（members/detail.php）へ
// PC は表（1行に1人）、スマホ（768px 未満）はカード型（D-052 と同じ切り替え）
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/members.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);

$now = time();
$today = date('Y-m-d', $now);
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabel = date('Y年n月j日', $now) . '（' . $weekdays[(int) date('w', $now)] . '）';

$settings = fetchWorkSettings($pdo);
$rows = fetchMemberAttendances($pdo, $currentUser['employee_id'], approvalDepartmentId($currentUser), $today);
$members = array_map(fn ($row) => memberRowView($row, $today, $settings), $rows);

$pageTitle = '社員の勤怠';
include(__DIR__ . '/../templates/header.php');
?>
<main class="members">
    <div class="members__head">
        <h1 class="members__title">社員の勤怠</h1>
        <p class="members__note"><?= h($dateLabel) ?>　対象：<?= h($currentUser['approval_scope'] === 'all' ? '全社' : $currentUser['department_name']) ?></p>
    </div>

    <div class="member-list">
        <div class="member-list__head" aria-hidden="true">
            <div>氏名（社員コード）</div><div>部署</div><div>状態</div><div>出勤</div><div>退勤</div><div>休憩</div><div>労働時間</div><div></div>
        </div>
        <?php if ($members === []): ?>
            <p class="member-list__empty">対象の社員はいません。</p>
        <?php endif; ?>
        <?php foreach ($members as $member): ?>
            <article class="member-item" aria-label="<?= h($member['name'] . 'さんの本日の勤怠') ?>">
                <div class="member-item__person">
                    <div class="member-item__name"><?= h($member['name']) ?></div>
                    <div class="member-item__sub"><?= h($member['employeeId']) ?></div>
                </div>
                <div class="member-item__department"><?= h($member['department']) ?></div>
                <div class="member-item__status">
                    <span class="status-badge status-badge--<?= h(str_replace('_', '-', $member['status'])) ?>"><?= h($member['statusLabel']) ?></span>
                </div>
                <div class="member-item__time"><span class="member-item__label">出勤</span><?= h($member['checkIn']) ?></div>
                <div class="member-item__time"><span class="member-item__label">退勤</span><?= h($member['checkOut']) ?></div>
                <div class="member-item__time"><span class="member-item__label">休憩</span><?= h($member['break']) ?></div>
                <div class="member-item__time"><span class="member-item__label">労働時間</span><?= h($member['workTime']) ?></div>
                <div class="member-item__actions">
                    <a class="button button--outline" href="<?= h(appUrl('members/detail.php') . '?id=' . rawurlencode($member['employeeId'])) ?>" aria-label="<?= h($member['name'] . 'さんの勤怠の詳細') ?>">詳細</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
