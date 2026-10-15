<?php
// 勤務設定（D-008、D-019、D-024、D-067）：標準始業・終業時刻と、昼休憩の変更（全社共通の1つ。履歴は持たない）
// システム管理者だけが使える。変えると、過去の日も含めて、労働時間の計算と簡単残業申請の判定に、すぐに反映される
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/admin.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_SYSTEM_ADMIN]);

$settings = fetchWorkSettings($pdo);
$form = [];
foreach (array_keys(WORK_SETTING_FIELDS) as $field) {
    $form[$field] = formatTime($settings[$field]);
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $result = saveWorkSettings($pdo, $_POST);
    if ($result['ok']) {
        setFlash($result['message']);
        redirect('admin/settings.php');
    }
    $errors = $result['errors'];
    foreach (array_keys(WORK_SETTING_FIELDS) as $field) {
        $form[$field] = inputString($_POST, $field);
    }
}

$flash = takeFlash();
$firstError = $errors === [] ? null : reset($errors);
$updatedAt = isset($settings['updated_at']) ? date('Y/m/d H:i', strtotime($settings['updated_at'])) : null;

$pageTitle = '勤務設定';
include(__DIR__ . '/../templates/header.php');
?>
<main class="admin admin--narrow" id="admin">
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php endif; ?>

    <h1 class="admin__title">勤務設定</h1>

    <section class="card admin__form" aria-labelledby="settings-title">
        <h2 class="card__title" id="settings-title">全社共通の勤務時間</h2>
        <?php if ($firstError !== null): ?>
            <p class="alert alert--error" role="alert"><?= h($firstError) ?></p>
        <?php endif; ?>
        <form class="admin__fields" method="post" action="<?= h(appUrl('admin/settings.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="form-grid">
                <?php foreach (['standard_start', 'standard_end', 'lunch_start', 'lunch_end'] as $field): ?>
                    <div class="form-field">
                        <label class="form-field__label" for="<?= h($field) ?>"><?= h(WORK_SETTING_FIELDS[$field]) ?></label>
                        <input class="form-field__input" type="time" id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h($form[$field]) ?>" step="60" required<?= isset($errors[$field]) ? ' aria-invalid="true"' : '' ?>>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="alert alert--info">変更は、すぐに全社員に反映されます。過去の日も含めて、記録表示の労働時間は新しい昼休憩で計算し直されます。簡単残業申請・承認画面の標準終了時刻も、新しい値になります。</p>
            <button class="button button--primary button--block" type="submit">保存</button>
        </form>
        <?php if ($updatedAt !== null): ?>
            <p class="card__note" id="settings-updated">最終更新：<?= h($updatedAt) ?></p>
        <?php endif; ?>
    </section>
</main>
<script src="<?= h(appUrl('js/admin.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
