<?php
// メイン画面（FR-003）：現在時刻と、本日の出勤・退勤・休憩。ボタンを押すと、js/main.js が api/attendance.php に送る
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/auth.php');
include_once(__DIR__ . '/src/attendance.php');

$currentUser = requireLogin($pdo);
$isEmployee = $currentUser['role'] !== ROLE_SYSTEM_ADMIN;

$now = time();
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$dateLabel = date('Y年n月j日', $now) . '（' . $weekdays[(int) date('w', $now)] . '）';

$view = null;
if ($isEmployee) {
    $view = attendanceView(fetchAttendance($pdo, $currentUser['employee_id'], workDateOf($now)));
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
        <p class="alert alert--info">システム管理者には、出退勤の記録はありません。</p>
    <?php else: ?>
        <div id="attendance"
            data-api-url="<?= h(appUrl('api/attendance.php')) ?>"
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

            <div class="punch">
                <?php foreach (ATTENDANCE_ACTION_LABELS as $action => $label): ?>
                    <button class="punch__button punch__button--<?= h(str_replace('_', '-', $action)) ?>" type="button" data-action="<?= h($action) ?>" <?= $view['buttons'][$action] ? '' : 'disabled' ?>><?= h($label) ?></button>
                <?php endforeach; ?>
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
    <?php endif; ?>
</main>
<script src="<?= h(appUrl('js/main.js')) ?>" defer></script>
<?php include(__DIR__ . '/templates/footer.php'); ?>
