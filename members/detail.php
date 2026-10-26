<?php
// 社員の勤怠の詳細（D-070）：対象社員の1人の勤怠を、記録表示と同じ月カレンダーで見る。管理者だけが使える
// 見るだけ（申請・修正・取り消しはできない）。日付を押すと、その日の記録と申請を表示する（js/records.js）
// URL：members/detail.php?id=2025001&month=2026-09。見られない社員は、理由を区別せずに 404
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/members.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);

$memberId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$member = $memberId === '' ? null : fetchEmployeeProfile($pdo, $memberId);
if ($member === null || !canViewMember($currentUser, $member)) {
    abort(404, MEMBER_NOT_VIEWABLE_MESSAGE);
}
if ($member['joined_on'] === null) {
    throw new RuntimeException('入社日を取得できません: ' . $member['employee_id']);
}

// 月の範囲は、その社員の入社した月から今月まで（D-061）
$today = date('Y-m-d');
$requested = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
$month = resolveMonth($requested, $today, $member['joined_on']);
$attendances = fetchMonthlyAttendances($pdo, $member['employee_id'], $month['first'], $month['last']);
$requests = fetchMonthlyRequests($pdo, $member['employee_id'], $month['first'], $month['last']);
$calendar = buildCalendar($month, $attendances, fetchWorkSettings($pdo), $today, $requests, $member['joined_on']);

// 日付を押したときに表示する、その日の記録と申請（見るだけ）
$days = calendarDays($calendar, true);

$pageTitle = '社員の勤怠';
include(__DIR__ . '/../templates/header.php');
?>
<main class="records member-detail">
    <div class="member-detail__head">
        <a class="member-detail__back" href="<?= h(appUrl('members/')) ?>"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>社員の勤怠</a>
        <h1 class="member-detail__name"><?= h($member['name']) ?><span class="member-detail__sub">（<?= h($member['employee_id']) ?>・<?= h($member['department_name']) ?>）</span></h1>
    </div>

    <?php
    $monthQuery = '?id=' . rawurlencode($member['employee_id']) . '&';
    $monthHeading = 'h2';
    $readOnly = true;
    include(__DIR__ . '/../templates/calendar.php');
    ?>
</main>
<script src="<?= h(assetUrl('js/records.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
