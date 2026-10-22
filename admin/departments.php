<?php
// 部署（D-014、D-024、D-067）：部署の追加と、一覧（名前の変更・無効化・有効に戻す）
// システム管理者だけが使える。無効にした部署は、アカウント作成の選択欄に出なくなるだけ（所属している社員は、そのまま）
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/admin.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_SYSTEM_ADMIN]);

$name = '';
$error = null;
// ウィンドウからの送信で誤りがあったときに、開き直すウィンドウ（['id' => …, 'departmentId' => …, 'error' => …]）
$reopen = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = inputString($_POST, 'action');
    $departmentId = inputString($_POST, 'department_id');
    $result = match ($action) {
        'add' => addDepartment($pdo, inputString($_POST, 'name')),
        'rename' => renameDepartment($pdo, $departmentId, inputString($_POST, 'name')),
        'deactivate' => setDepartmentActive($pdo, $departmentId, false),
        'reactivate' => setDepartmentActive($pdo, $departmentId, true),
        default => abort(400, '不正なリクエストです。画面を開き直して、もう一度お試しください。'),
    };
    if ($result['ok']) {
        setFlash($result['message']);
        redirect('admin/departments.php');
    }
    if ($action === 'add') {
        $name = inputString($_POST, 'name');
        $error = $result['error'];
    } else {
        $dialogIds = ['rename' => 'rename-dialog', 'deactivate' => 'department-deactivate-dialog', 'reactivate' => 'department-reactivate-dialog'];
        $reopen = ['id' => $dialogIds[$action], 'departmentId' => $departmentId, 'error' => $result['error']];
    }
}

$departments = fetchDepartments($pdo);
$flash = takeFlash();

$pageTitle = '部署';
include(__DIR__ . '/../templates/header.php');
?>
<main class="admin" id="admin"<?php if ($reopen !== null): ?> data-reopen="<?= h($reopen['id']) ?>" data-reopen-department="<?= h($reopen['departmentId']) ?>" data-reopen-error="<?= h($reopen['error']) ?>"<?php endif; ?>>
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($reopen !== null): ?>
        <p class="alert alert--error" role="alert"><?= h($reopen['error']) ?></p>
    <?php endif; ?>

    <h1 class="admin__title">部署</h1>

    <div class="admin__body">
        <section class="card admin__form" aria-labelledby="add-title">
            <h2 class="card__title" id="add-title">新しい部署</h2>
            <?php if ($error !== null): ?>
                <p class="alert alert--error" role="alert"><?= h($error) ?></p>
            <?php endif; ?>
            <form class="admin__fields" method="post" action="<?= h(appUrl('admin/departments.php')) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <div class="form-field">
                    <label class="form-field__label" for="department_name">部署名</label>
                    <input class="form-field__input" type="text" id="department_name" name="name" value="<?= h($name) ?>" maxlength="<?= h(ADMIN_DEPARTMENT_NAME_MAX_LENGTH) ?>" autocomplete="off" required<?= $error !== null ? ' aria-invalid="true"' : '' ?>>
                </div>
                <button class="button button--primary button--block" type="submit">部署を追加</button>
            </form>
            <p class="card__note">無効にした部署は、アカウント作成の選択欄に出なくなります。所属している社員は、そのままです。部署は削除できません。</p>
        </section>

        <section class="admin-list" aria-labelledby="list-title">
            <h2 class="admin-list__title" id="list-title">登録済みの部署（<?= h(count($departments)) ?>）</h2>
            <?php if ($departments === []): ?>
                <p class="admin-list__empty">登録済みの部署はありません。</p>
            <?php else: ?>
                <div class="admin-list__scroll">
                    <table class="admin-table">
                        <thead>
                            <tr><th scope="col">部署名</th><th scope="col">在籍人数</th><th scope="col">状態</th><th scope="col">操作</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments as $department): ?>
                                <?php $active = (int) $department['is_active'] === 1; ?>
                                <tr class="<?= $active ? '' : 'admin-table__inactive' ?>" data-department="<?= h($department['id']) ?>">
                                    <td class="admin-table__name"><?= h($department['name']) ?></td>
                                    <td class="admin-table__number"><?= h($department['active_count']) ?> 人</td>
                                    <td><?= $active ? '有効' : '無効' ?></td>
                                    <td>
                                        <div class="admin-table__actions">
                                            <button class="button button--outline button--small" type="button" data-open="rename-dialog" data-department-id="<?= h($department['id']) ?>" data-target="<?= h($department['name']) ?>" data-name="<?= h($department['name']) ?>">名前の変更</button>
                                            <?php if ($active): ?>
                                                <button class="button button--reject button--small" type="button" data-open="department-deactivate-dialog" data-department-id="<?= h($department['id']) ?>" data-target="<?= h($department['name']) ?>" data-note="<?= (int) $department['active_count'] > 0 ? h('在籍している社員 ' . $department['active_count'] . ' 人は、この部署のままです。') : '' ?>">無効化</button>
                                            <?php else: ?>
                                                <button class="button button--outline button--small" type="button" data-open="department-reactivate-dialog" data-department-id="<?= h($department['id']) ?>" data-target="<?= h($department['name']) ?>">有効に戻す</button>
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

    <!-- 名前の変更 -->
    <dialog class="dialog" id="rename-dialog" aria-labelledby="rename-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/departments.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="department_id" data-value="departmentId">
            <h2 class="dialog__title" id="rename-title">部署の名前を変えます</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <div class="form-field">
                <label class="form-field__label" for="rename_name">新しい部署名</label>
                <input class="form-field__input" type="text" id="rename_name" name="name" data-value="name" maxlength="<?= h(ADMIN_DEPARTMENT_NAME_MAX_LENGTH) ?>" autocomplete="off" required>
            </div>
            <p class="dialog__text">所属している社員の表示（ヘッダー・承認画面など）も、新しい名前になります。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--primary" type="submit">変更する</button>
            </div>
        </form>
    </dialog>

    <!-- 無効化 -->
    <dialog class="dialog" id="department-deactivate-dialog" aria-labelledby="department-deactivate-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/departments.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="department_id" data-value="departmentId">
            <h2 class="dialog__title" id="department-deactivate-title">部署を無効にします</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <p class="dialog__text">アカウント作成の部署の選択欄に出なくなります。<span data-text="note"></span></p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--danger" type="submit">無効にする</button>
            </div>
        </form>
    </dialog>

    <!-- 有効に戻す -->
    <dialog class="dialog" id="department-reactivate-dialog" aria-labelledby="department-reactivate-title">
        <form class="dialog__body" method="post" action="<?= h(appUrl('admin/departments.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="department_id" data-value="departmentId">
            <h2 class="dialog__title" id="department-reactivate-title">部署を有効に戻します</h2>
            <p class="dialog__subtitle" data-text="target"></p>
            <p class="alert alert--error" data-dialog-error role="alert" hidden></p>
            <p class="dialog__text">アカウント作成の部署の選択欄に、また出るようになります。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" data-close>キャンセル</button>
                <button class="button button--primary" type="submit">有効に戻す</button>
            </div>
        </form>
    </dialog>
</main>
<script src="<?= h(appUrl('js/admin.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
