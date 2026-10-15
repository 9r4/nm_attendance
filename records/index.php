<?php
// 記録表示（FR-004）：自分の勤怠を、1か月単位のカレンダーで表示する。日付を押すと、その日の記録を表示する（js/records.js）
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/records.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_EMPLOYEE, ROLE_MANAGER]);
if ($currentUser['joined_on'] === null) {
    throw new RuntimeException('入社日を取得できません: ' . $currentUser['employee_id']);
}

$today = date('Y-m-d');
$requested = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null;
$month = resolveMonth($requested, $today, $currentUser['joined_on']);
$attendances = fetchMonthlyAttendances($pdo, $currentUser['employee_id'], $month['first'], $month['last']);
$calendar = buildCalendar($month, $attendances, fetchWorkSettings($pdo), $today);

// 日付を押したときに表示する、その日の記録（js/records.js が使う）
$days = [];
foreach ($calendar['weeks'] as $week) {
    foreach ($week as $cell) {
        if ($cell !== null) {
            $days[$cell['date']] = array_intersect_key($cell, array_flip(['label', 'holiday', 'hasRecord', 'checkIn', 'checkOut', 'break', 'workTime']));
        }
    }
}

$pageTitle = '記録表示';
include(__DIR__ . '/../templates/header.php');
?>
<main class="records">
    <div class="records__bar">
        <nav class="month-nav" aria-label="月の切り替え">
            <?php if ($month['prev'] !== null): ?>
                <a class="month-nav__button" href="?month=<?= h($month['prev']) ?>" aria-label="前の月"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></a>
            <?php else: ?>
                <span class="month-nav__button month-nav__button--disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_left</span></span>
            <?php endif; ?>
            <h1 class="month-nav__label"><?= h($month['label']) ?></h1>
            <?php if ($month['next'] !== null): ?>
                <a class="month-nav__button" href="?month=<?= h($month['next']) ?>" aria-label="次の月"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></a>
            <?php else: ?>
                <span class="month-nav__button month-nav__button--disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_right</span></span>
            <?php endif; ?>
        </nav>
        <div class="summary">
            <span class="summary__item">出勤日数 <b id="summary-days"><?= h($calendar['workDays']) ?>日</b></span>
            <span class="summary__item">総労働時間 <b id="summary-total"><?= h(formatMinutes($calendar['totalMinutes'])) ?></b></span>
        </div>
    </div>

    <div class="calendar" id="calendar" data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
        <div class="calendar__head" aria-hidden="true">
            <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $weekdayName): ?>
                <div class="calendar__weekday calendar__weekday--<?= h($i) ?>"><?= h($weekdayName) ?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach ($calendar['weeks'] as $week): ?>
            <div class="calendar__week">
                <?php foreach ($week as $cell): ?>
                    <?php if ($cell === null): ?>
                        <div class="calendar__blank"></div>
                    <?php else: ?>
                        <?php
                        $classes = ['calendar__day'];
                        if ($cell['isRestDay']) {
                            $classes[] = 'calendar__day--rest';
                        }
                        if ($cell['isToday']) {
                            $classes[] = 'calendar__day--today';
                        }
                        $dateClass = ($cell['weekday'] === 0 || $cell['holiday'] !== '') ? ' calendar__date--sunday' : ($cell['weekday'] === 6 ? ' calendar__date--saturday' : '');
                        ?>
                        <button class="<?= h(implode(' ', $classes)) ?>" type="button" data-date="<?= h($cell['date']) ?>" aria-label="<?= h($cell['label'] . ($cell['holiday'] !== '' ? ' ' . $cell['holiday'] : '') . 'の記録') ?>"<?= $cell['isToday'] ? ' aria-current="date"' : '' ?>>
                            <span class="calendar__top">
                                <span class="calendar__date<?= h($dateClass) ?>"><?= h($cell['day']) ?></span>
                                <?php if ($cell['holiday'] !== ''): ?>
                                    <span class="calendar__holiday"><?= h($cell['holiday']) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($cell['hasRecord']): ?>
                                <span class="calendar__times">
                                    <span><span class="calendar__mark">出</span> <?= h($cell['checkIn']) ?></span>
                                    <span><span class="calendar__mark">退</span> <?= h($cell['checkOut']) ?></span>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <dialog class="dialog" id="day-dialog" aria-labelledby="day-title">
        <form method="dialog" class="dialog__body">
            <h2 class="dialog__title" id="day-title"></h2>
            <p class="dialog__note" id="day-holiday" hidden></p>
            <dl class="day-detail" id="day-detail">
                <div class="day-detail__row"><dt>出勤</dt><dd id="day-check-in"></dd></div>
                <div class="day-detail__row"><dt>退勤</dt><dd id="day-check-out"></dd></div>
                <div class="day-detail__row"><dt>休憩</dt><dd id="day-break"></dd></div>
                <div class="day-detail__row"><dt>労働時間</dt><dd id="day-work-time"></dd></div>
            </dl>
            <p class="dialog__text" id="day-empty" hidden>記録はありません。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="submit" value="close">閉じる</button>
            </div>
        </form>
    </dialog>
</main>
<script src="<?= h(appUrl('js/records.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
