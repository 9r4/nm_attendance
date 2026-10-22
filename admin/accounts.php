<?php
// アカウント管理（FR-007、D-015、D-066、D-067）：社員・管理者のアカウントの作成と、一覧（無効化・有効に戻す・パスワード再設定）
// システム管理者だけが使える。送信は普通のフォーム（POST）で、成功したら同じ画面に戻して通知を出す。ウィンドウの開閉は js/admin.js
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/admin.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_SYSTEM_ADMIN]);

$now = time();
$today = date('Y-m-d', $now);
// 入力欄の値（初期値。入社日は今日）
$form = ['name' => '', 'email' => '', 'department_id' => '', 'joined_on' => $today, 'role' => ROLE_EMPLOYEE, 'approval_scope' => 'department'];
$errors = [];
// ウィンドウからの送信で誤りがあったときに、開き直すウィンドウ（['id' => …, 'employeeId' => …, 'error' => …]）
$reopen = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = inputString($_POST, 'action');
    $employeeId = inputString($_POST, 'employee_id');
    $result = match ($action) {
        'create' => createAccount($pdo, $_POST, $now),
        'deactivate' => deactivateAccount($pdo, $employeeId, inputString($_POST, 'left_on'), $today),
        'reactivate' => reactivateAccount($pdo, $employeeId),
        'reset_password' => resetPassword($pdo, $employeeId, inputRaw($_POST, 'password'), inputRaw($_POST, 'password_confirm')),
        default => abort(400, '不正なリクエストです。画面を開き直して、もう一度お試しください。'),
    };
    if ($result['ok']) {
        setFlash($result['message']);
        redirect('admin/accounts.php');
    }
    if ($action === 'create') {
        $errors = $result['errors'];
        $form = $result['values'];
    } else {
        $dialogIds = ['deactivate' => 'deactivate-dialog', 'reactivate' => 'reactivate-dialog', 'reset_password' => 'password-dialog'];
        $reopen = ['id' => $dialogIds[$action], 'employeeId' => $employeeId, 'error' => $result['error']];
    }
}

$departments = activeDepartments(fetchDepartments($pdo));
$accounts = array_map('accountView', fetchAccounts($pdo));
$flash = takeFlash();
$firstError = $errors === [] ? null : reset($errors);
$invalid = fn (string $field) => isset($errors[$field]) ? ' aria-invalid="true"' : '';

$pageTitle = 'アカウント管理';
include(__DIR__ . '/../templates/header.php');
?>
<main class="admin" id="admin"<?php if ($reopen !== null): ?> data-reopen="<?= h($reopen['id']) ?>" data-reopen-employee="<?= h($reopen['employeeId']) ?>" data-reopen-error="<?= h($reopen['error']) ?>"<?php endif; ?>>
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($reopen !== null): ?>
        <p class="alert alert--error" role="alert"><?= h($reopen['error']) ?></p>
    <?php endif; ?>

    <h1 class="admin__title">アカウント管理</h1>

    <div class="admin__body">
        <section class="card admin__form" aria-labelledby="create-title">
            <h2 class="card__title" id="create-title">新しいアカウント</h2>
            <?php if ($firstError !== null): ?>
                <p class="alert alert--error" role="alert"><?= h($firstError) ?></p>
            <?php endif; ?>
            <?php if ($departments === []): ?>
                <p class="alert alert--info" role="status">先に部署を登録してください。<a href="<?= h(appUrl('admin/departments.php')) ?>">部署へ</a></p>
            <?php endif; ?>
            <form class="admin__fields" method="post" action="<?= h(appUrl('admin/accounts.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <p class="card__note">社員コード（ログイン ID）は、作成したときに自動で決まります。</p>
                <div class="form-grid">
                    <div class="form-field">
                        <label class="form-field__label" for="name">氏名</label>
                        <input class="form-field__input" type="text" id="name" name="name" value="<?= h($form['name']) ?>" maxlength="<?= h(ADMIN_NAME_MAX_LENGTH) ?>" autocomplete="off" required<?= $invalid('name') ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-field__label" for="department_id">部署</label>
                        <select class="form-field__input" id="department_id" name="department_id" required<?= $invalid('department_id') ?>>
                            <option value="">選んでください</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= h($department['id']) ?>"<?= (string) $department['id'] === $form['department_id'] ? ' selected' : '' ?>><?= h($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field form-grid__wide">
                        <label class="form-field__label" for="email">メールアドレス</label>
                        <input class="form-field__input" type="email" id="email" name="email" value="<?= h($form['email']) ?>" maxlength="<?= h(ADMIN_EMAIL_MAX_LENGTH) ?>" autocomplete="off" required<?= $invalid('email') ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-field__label" for="joined_on">入社日</label>
                        <input class="form-field__input" type="date" id="joined_on" name="joined_on" value="<?= h($form['joined_on']) ?>" min="<?= h(ADMIN_JOINED_ON_MIN) ?>" max="<?= h(date('Y-m-d', strtotime($today . ' +1 year'))) ?>" required<?= $invalid('joined_on') ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-field__label" for="role">権限</label>
                        <select class="form-field__input" id="role" name="role"<?= $invalid('role') ?>>
                            <?php foreach (ACCOUNT_ROLE_LABELS as $role => $label): ?>
                                <option value="<?= h($role) ?>"<?= $role === $form['role'] ? ' selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <fieldset class="form-field form-grid__wide admin__scope" id="scope-field"<?= $form['role'] === ROLE_MANAGER ? '' : ' hidden' ?>>
                        <legend class="form-field__label">承認範囲</legend>
                        <div class="admin__choices">
                            <?php foreach (APPROVAL_SCOPE_LABELS as $scope => $label): ?>
                                <label class="admin__choice"><input type="radio" name="approval_scope" value="<?= h($scope) ?>"<?= $scope === $form['approval_scope'] ? ' checked' : '' ?>> <?= h($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="form-field">
                        <label class="form-field__label" for="password">パスワード</label>
                        <input class="form-field__input" type="password" id="password" name="password" maxlength="64" autocomplete="new-password" required<?= $invalid('password') ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-field__label" for="password_confirm">パスワード（確認）</label>
                        <input class="form-field__input" type="password" id="password_confirm" name="password_confirm" maxlength="64" autocomplete="new-password" required<?= $invalid('password_confirm') ?>>
                    </div>
                </div>
                <p class="dialog__help">パスワードは、半角の英数字・記号で 8〜64 文字にしてください。</p>
                <button class="button button--primary button--block" type="submit"<?= $departments === [] ? ' disabled' : '' ?>>アカウントを作成</button>
            </form>
        </section>

        <section class="admin-list" aria-labelledby="list-title">
            <h2 class="admin-list__title" id="list-title">登録済みアカウント（<?= h(count($accounts)) ?>）</h2>
            <?php if ($accounts === []): ?>
                <p class="admin-list__empty">登録済みのアカウントはありません。</p>
            <?php else: ?>
                <div class="admin-list__scroll">
                    <table class="admin-table">
                        <thead>
                            <tr><th scope="col">社員コード</th><th scope="col">氏名</th><th scope="col">部署</th><th scope="col">権限</th><th scope="col">状態</th><th scope="col">操作</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <tr class="<?= $account['active'] ? '' : 'admin-table__inactive' ?>" data-employee="<?= h($account['employeeId']) ?>">
                                    <td class="admin-table__code"><?= h($account['employeeId']) ?></td>
                                    <td class="admin-table__wide"><div class="admin-table__name"><?= h($account['name']) ?></div><div class="admin-table__sub"><?= h($account['email']) ?></div></td>
                                    <td class="admin-table__nowrap"><?= h($account['department']) ?></td>
                                    <td class="admin-table__nowrap"><div><?= h($account['role']) ?></div><?php if ($account['scope'] !== ''): ?><div class="admin-table__sub"><?= h($account['scope']) ?></div><?php endif; ?></td>
                                    <td class="admin-table__nowrap"><div><?= h($account['status']) ?></div><?php if ($account['leftOn'] !== ''): ?><div class="admin-table__sub"><?= h($account['leftOn']) ?></div><?php endif; ?></td>
                                    <td>
                                        <div class="admin-table__actions">
                                            <button class="button button--outline button--small" type="button" data-open="password-dialog" data-employee-id="<?= h($account['employeeId']) ?>" data-target="<?= h($account['employeeId'] . '　' . $account['name']) ?>">パスワード再設定</button>
                                            <?php if ($account['active']): ?>
                                                <?php $range = leftOnRange($account['joinedOn'], $today); ?>
                                                <button class="button button--reject button--small" type="button" data-open="deactivate-dialog" data-employee-id="<?= h($account['employeeId']) ?>" data-target="<?= h($account['employeeId'] . '　' . $account['name']) ?>" data-left-on="<?= h($range['max']) ?>" data-min="<?= h($range['min']) ?>" data-max="<?= h($range['max']) ?>">無効化</button>
                                            <?php else: ?>
                                                <button class="button button--outline button--small" type="button" data-open="reactivate-dialog" data-employee-id="<?= h($account['employeeId']) ?>" data-target="<?= h($account['employeeId'] . '　' . $account['name']) ?>">有効に戻す</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- 無効化（退社日を入れる） -->
    <dialog class="dialog" id="deactivate-dialog" aria-labelledby="deactivate-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/accounts.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="employee_id" data-value="employeeId">
            <h2 class="dialog__title" id="deactivate-title">アカウントを無効にします</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <div class="form-field">
                <label class="form-field__label" for="left_on">退社日</label>
                <input class="form-field__input" type="date" id="left_on" name="left_on" data-value="leftOn" data-min-from="min" data-max-from="max" required>
            </div>
            <p class="dialog__text">無効にすると、この社員はログインできなくなります（ログイン中の場合も、次の操作から使えなくなります）。勤怠と申請の記録は残ります。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--danger" type="submit">無効にする</button>
            </div>
        </form>
    </dialog>

    <!-- 有効に戻す -->
    <dialog class="dialog" id="reactivate-dialog" aria-labelledby="reactivate-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/accounts.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="employee_id" data-value="employeeId">
            <h2 class="dialog__title" id="reactivate-title">アカウントを有効に戻します</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <p class="dialog__text">在籍に戻し、退社日を消して、ログインできるようにします。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--primary" type="submit">有効に戻す</button>
            </div>
        </form>
    </dialog>

    <!-- パスワード再設定 -->
    <dialog class="dialog" id="password-dialog" aria-labelledby="password-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/accounts.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="employee_id" data-value="employeeId">
            <h2 class="dialog__title" id="password-title">パスワードを再設定します</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <div class="form-field">
                <label class="form-field__label" for="new_password">新しいパスワード</label>
                <input class="form-field__input" type="password" id="new_password" name="password" maxlength="64" autocomplete="new-password" required>
            </div>
            <div class="form-field">
                <label class="form-field__label" for="new_password_confirm">新しいパスワード（確認）</label>
                <input class="form-field__input" type="password" id="new_password_confirm" name="password_confirm" maxlength="64" autocomplete="new-password" required>
            </div>
            <p class="dialog__help">半角の英数字・記号で 8〜64 文字にしてください。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--primary" type="submit">再設定する</button>
            </div>
        </form>
    </dialog>
</main>
<script src="<?= h(assetUrl('js/admin.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
