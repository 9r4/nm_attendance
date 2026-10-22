<?php
// メイン画面（FR-003、FR-008）：現在時刻と、本日の出勤・退勤・休憩、簡単残業申請
// ボタンを押すと、js/main.js が api/attendance.php（出退勤）・api/requests.php（残業申請）に送る
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/auth.php');
include_once(__DIR__ . '/src/queries/work_settings.php');
include_once(__DIR__ . '/src/requests.php');

$currentUser = requireLogin($pdo);
$isEmployee = $currentUser['role'] !== ROLE_SYSTEM_ADMIN;

$now = time();
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabel = date('Y年n月j日', $now) . '（' . $weekdays[(int) date('w', $now)] . '）';

$view = null;
if ($isEmployee) {
    $standardEnd = fetchWorkSettings($pdo)['standard_end'];
    $today = workDateOf($now);
    // 今日の修正・新規登録が申請中なら打刻を止め（D-063）、残業申請が申請中なら簡単残業申請を止める（D-069）
    $pending = pendingStatesOf($pdo, $currentUser['employee_id'], $today);
    $view = attendanceView(fetchAttendance($pdo, $currentUser['employee_id'], $today), $standardEnd, $pending['correction'], $pending['overtime']);
    $headerStatus = $view;
}

$pageTitle = 'メイン';
include(__DIR__ . '/templates/header.php');
?>
<main class="main-screen">
    <section class="clock" id="clock" aria-label="現在時刻" data-server-time="<?= h((string) (int) floor(microtime(true) * 1000)) ?>">
        <div class="clock__date" id="clock-date"><?= h($dateLabel) ?></div>
        <div class="clock__time" id="clock-time"><?= h(date('H:i:s', $now)) ?></div>
    </section>

    <?php if (!$isEmployee): ?>
        <!-- システム管理者：出退勤の記録は無いので、管理の画面へのボタンを並べる（D-067） -->
        <nav class="admin-links" aria-label="管理の画面">
            <?php foreach (ADMIN_MENU_ITEMS as $item): ?>
                <a class="admin-links__item" href="<?= h(appUrl($item['path'])) ?>"><?= h($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php else: ?>
        <div id="attendance"
            data-api-url="<?= h(appUrl('api/attendance.php')) ?>"
            data-request-api-url="<?= h(appUrl('api/requests.php')) ?>"
            data-login-url="<?= h(appUrl('login.php')) ?>"
            data-csrf-token="<?= h(csrfToken()) ?>"
            data-view="<?= h(json_encode($view, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
            <dl class="today">
                <div class="today__item">
                    <dt>本日の出勤</dt>
                    <dd id="today-check-in"><?= h($view['checkIn']) ?></dd>
                </div>
                <div class="today__item">
                    <dt>本日の退勤</dt>
                    <dd id="today-check-out"><?= h($view['checkOut']) ?></dd>
                </div>
                <div class="today__item">
                    <dt>休憩</dt>
                    <dd id="today-break"><?= h($view['break']) ?></dd>
                </div>
            </dl>

            <p class="alert alert--info punch-lock" id="punch-lock" role="status"<?= $view['locked'] ? '' : ' hidden' ?>>
                <?= h(PUNCH_LOCKED_MESSAGE) ?>
                <a href="<?= h(appUrl('records/')) ?>">記録表示へ</a>
            </p>

            <div class="punch">
                <?php foreach (ATTENDANCE_ACTION_LABELS as $action => $label): ?>
                    <button class="punch__button punch__button--<?= h(str_replace('_', '-', $action)) ?>" type="button" data-action="<?= h($action) ?>" <?= $view['buttons'][$action] ? '' : 'disabled' ?>><?= h($label) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="overtime">
                <button class="overtime__button" type="button" id="overtime-open" <?= $view['buttons']['overtime'] ? '' : 'disabled' ?>>簡単残業申請</button>
                <p class="overtime__note" id="overtime-note"<?= $view['overtimePending'] ? ' hidden' : '' ?>>退勤済みで、退勤が <?= h(formatTime($standardEnd)) ?> より後の場合に申請できます</p>
                <p class="overtime__note" id="overtime-pending" role="status"<?= $view['overtimePending'] ? '' : ' hidden' ?>><?= h(OVERTIME_PENDING_MESSAGE) ?></p>
            </div>
        </div>

        <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

        <dialog class="dialog" id="confirm-dialog" aria-labelledby="confirm-title">
            <form method="dialog" class="dialog__body">
                <h2 class="dialog__title" id="confirm-title"></h2>
                <p class="dialog__text" id="confirm-text"></p>
                <div class="dialog__actions">
                    <button class="button button--outline" type="submit" value="cancel">キャンセル</button>
                    <button class="button button--primary" type="submit" value="ok">上書きする</button>
                </div>
            </form>
        </dialog>

        <!-- 簡単残業申請（FR-008）。理由の欄は無い（D-041） -->
        <dialog class="dialog" id="overtime-dialog" aria-labelledby="overtime-title">
            <form class="dialog__body" id="overtime-form" novalidate>
                <h2 class="dialog__title" id="overtime-title">簡単残業申請</h2>
                <p class="alert alert--error" id="overtime-error-form" role="alert" hidden></p>
                <dl class="overtime-times">
                    <div class="overtime-times__item"><dt>標準終了時刻</dt><dd id="overtime-standard-end"><?= h(formatTime($standardEnd)) ?></dd></div>
                    <div class="overtime-times__item"><dt>退勤時刻</dt><dd id="overtime-check-out"><?= h($view['checkOut']) ?></dd></div>
                </dl>
                <div class="form-field">
                    <label class="form-field__label" for="overtime-end">残業の終了時刻</label>
                    <input class="form-field__input" type="time" id="overtime-end" name="overtime_end" step="60" required>
                    <p class="form-field__error" id="overtime-error-overtime_end" hidden></p>
                    <p class="dialog__help"><?= h(formatTime($standardEnd)) ?> より後、退勤時刻までの時刻を指定できます。</p>
                </div>
                <div class="dialog__actions">
                    <button class="button button--outline" type="button" id="overtime-cancel">キャンセル</button>
                    <button class="button button--primary" type="submit" id="overtime-submit">申請する</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
</main>
<script src="<?= h(assetUrl('js/main.js')) ?>" defer></script>
<?php include(__DIR__ . '/templates/footer.php'); ?>
