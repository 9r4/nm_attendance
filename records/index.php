<?php
// 記録表示（FR-004）と、修正・新規登録の申請（FR-005）
// 自分の勤怠を1か月単位のカレンダーで表示する。日付を押すと、その日の記録と申請を表示し、そこから申請できる（js/records.js）
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
$requests = fetchMonthlyRequests($pdo, $currentUser['employee_id'], $month['first'], $month['last']);
$calendar = buildCalendar($month, $attendances, fetchWorkSettings($pdo), $today, $requests, $currentUser['joined_on']);
$flash = takeFlash();

// 日付を押したときに表示する、その日の記録と申請（js/records.js が使う）
$days = [];
foreach ($calendar['weeks'] as $week) {
    foreach ($week as $cell) {
        if ($cell !== null) {
            $days[$cell['date']] = array_intersect_key($cell, array_flip([
                'label', 'holiday', 'hasRecord', 'checkIn', 'checkOut', 'break', 'workTime',
                'values', 'requests', 'isFuture', 'pendingCorrection', 'canRequest',
            ]));
        }
    }
}

// 「申請」ボタンで選べる日：表示中の月の、入社日から今日までの日
$requestMin = max($month['first'], $currentUser['joined_on']);
$requestMax = min($month['last'], $today);

$pageTitle = '記録表示';
include(__DIR__ . '/../templates/header.php');
?>
<main class="records">
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php else: ?>
        <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
    <?php endif; ?>

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
        <?php if ($requestMin <= $requestMax): ?>
            <button class="button button--primary records__request" type="button" id="request-open">申請</button>
        <?php endif; ?>
    </div>

    <div class="calendar" id="calendar"
        data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
        data-api-url="<?= h(appUrl('api/requests.php')) ?>"
        data-login-url="<?= h(appUrl('login.php')) ?>"
        data-csrf-token="<?= h(csrfToken()) ?>">
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
                        $ariaLabel = $cell['label'] . ($cell['holiday'] !== '' ? ' ' . $cell['holiday'] : '') . ($cell['badge'] !== null ? ' ' . $cell['badgeLabel'] : '') . 'の記録';
                        ?>
                        <button class="<?= h(implode(' ', $classes)) ?>" type="button" data-date="<?= h($cell['date']) ?>" aria-label="<?= h($ariaLabel) ?>"<?= $cell['isToday'] ? ' aria-current="date"' : '' ?>>
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
                            <?php if ($cell['badge'] !== null): ?>
                                <span class="request-badge request-badge--<?= h($cell['badge']) ?>"><?= h($cell['badgeLabel']) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- その日の記録と申請 -->
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
            <section class="request-list" id="day-requests" hidden>
                <h3 class="request-list__title">申請</h3>
                <ul class="request-list__items" id="day-request-items"></ul>
            </section>
            <p class="alert alert--info" id="day-pending" hidden>申請中のため、新しい申請はできません。新しく申請する場合は、申請中の申請を取り消してください。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="submit" value="close">閉じる</button>
                <button class="button button--primary" type="button" id="day-request" hidden></button>
            </div>
        </form>
    </dialog>

    <!-- 申請の取り消しの確認（D-063） -->
    <dialog class="dialog" id="cancel-dialog" aria-labelledby="cancel-title">
        <div class="dialog__body">
            <h2 class="dialog__title" id="cancel-title">申請を取り消しますか？</h2>
            <p class="dialog__text" id="cancel-text"></p>
            <p class="alert alert--error" id="cancel-error" role="alert" hidden></p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" id="cancel-back">戻る</button>
                <button class="button button--danger" type="button" id="cancel-submit">取り消す</button>
            </div>
        </div>
    </dialog>

    <!-- 修正・新規登録の申請ウィンドウ（種別の欄は置かない。D-051） -->
    <dialog class="dialog dialog--wide" id="request-dialog" aria-labelledby="request-title">
        <form class="dialog__body" id="request-form" novalidate>
            <h2 class="dialog__title" id="request-title">修正・新規登録の申請</h2>
            <p class="dialog__subtitle" id="request-day" hidden></p>
            <p class="alert alert--error" id="request-error-form" role="alert" hidden></p>
            <div class="form-grid">
                <div class="form-field form-grid__wide">
                    <label class="form-field__label" for="request-date">対象日</label>
                    <input class="form-field__input" type="date" id="request-date" name="target_date" min="<?= h($requestMin) ?>" max="<?= h($requestMax) ?>" required>
                    <p class="form-field__error" id="request-error-target_date" hidden></p>
                </div>
                <?php foreach (['check_in' => '出勤時刻', 'check_out' => '退勤時刻', 'break_start' => '休憩開始', 'break_end' => '休憩終了'] as $name => $label): ?>
                    <div class="form-field">
                        <label class="form-field__label" for="request-<?= h($name) ?>"><?= h($label) ?></label>
                        <input class="form-field__input" type="time" id="request-<?= h($name) ?>" name="<?= h($name) ?>" step="60">
                        <p class="form-field__error" id="request-error-<?= h($name) ?>" hidden></p>
                    </div>
                <?php endforeach; ?>
                <div class="form-field form-grid__wide">
                    <label class="form-field__label" for="request-reason">理由（任意）</label>
                    <textarea class="form-field__input form-field__textarea" id="request-reason" name="reason" rows="3" maxlength="<?= h(REQUEST_REASON_MAX_LENGTH) ?>"></textarea>
                    <p class="form-field__error" id="request-error-reason" hidden></p>
                </div>
            </div>
            <p class="dialog__help">空欄の項目は「なし」として申請します。申請は管理者が承認するまで勤怠に反映されず、「申請中」と表示されます。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" id="request-cancel">キャンセル</button>
                <button class="button button--primary" type="submit" id="request-submit">申請する</button>
            </div>
        </form>
    </dialog>
</main>
<script src="<?= h(assetUrl('js/records.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
