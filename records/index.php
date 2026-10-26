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
$days = calendarDays($calendar);

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

    <?php
    $monthQuery = '?';
    $monthHeading = 'h1';
    $readOnly = false;
    include(__DIR__ . '/../templates/calendar.php');
    ?>

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
