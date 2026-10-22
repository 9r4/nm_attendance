<?php
// 承認（FR-006）：承認範囲の申請を一覧で見て、承認・却下する。管理者だけが使える
// PC はリスト型、スマホ（768px 未満）はカード型（D-052）。承認・却下は js/approvals.js が api/approvals.php に送る
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/approvals.php');

$currentUser = requireLogin($pdo);
requireRole($currentUser, [ROLE_MANAGER]);

$tab = ($_GET['tab'] ?? '') === 'done' ? 'done' : 'todo';
$standardEnd = fetchWorkSettings($pdo)['standard_end'];
$departmentId = approvalDepartmentId($currentUser);
$pending = fetchPendingRequests($pdo, $currentUser['employee_id'], $departmentId);
$processed = fetchProcessedRequests($pdo, $currentUser['employee_id'], $departmentId, APPROVAL_PROCESSED_LIMIT);
$items = array_map(fn ($row) => approvalItemView($row, $standardEnd), $tab === 'todo' ? $pending : $processed);
$flash = takeFlash();

$pageTitle = '承認';
include(__DIR__ . '/../templates/header.php');
?>
<main class="approvals" id="approvals"
    data-api-url="<?= h(appUrl('api/approvals.php')) ?>"
    data-login-url="<?= h(appUrl('login.php')) ?>"
    data-csrf-token="<?= h(csrfToken()) ?>">
    <?php if ($flash !== null): ?>
        <div class="toast" id="toast" role="status" aria-live="polite"><?= h($flash) ?></div>
    <?php else: ?>
        <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
    <?php endif; ?>

    <div class="approvals__head">
        <h1 class="approvals__title">承認</h1>
        <p class="approvals__scope">承認範囲：<?= h($currentUser['approval_scope'] === 'all' ? '全社' : $currentUser['department_name']) ?></p>
    </div>

    <nav class="tabs" aria-label="申請の切り替え">
        <a class="tabs__item" href="?tab=todo"<?= $tab === 'todo' ? ' aria-current="page"' : '' ?>>未処理（<?= h(count($pending)) ?>）</a>
        <a class="tabs__item" href="?tab=done"<?= $tab === 'done' ? ' aria-current="page"' : '' ?>>処理済み（<?= h(count($processed)) ?>）</a>
    </nav>
    <?php if ($tab === 'done'): ?>
        <p class="approvals__note">新しい順に <?= h(APPROVAL_PROCESSED_LIMIT) ?> 件まで表示します。</p>
    <?php endif; ?>

    <div class="approval-list">
        <div class="approval-list__head" aria-hidden="true">
            <div>申請者</div><div>種別</div><div>対象日</div><div>内容（変更前 → 申請値）</div><div>申請日時</div><div><?= $tab === 'todo' ? '操作' : '結果' ?></div>
        </div>
        <?php if ($items === []): ?>
            <p class="approval-list__empty"><?= $tab === 'todo' ? '未処理の申請はありません。' : '処理済みの申請はありません。' ?></p>
        <?php endif; ?>
        <?php foreach ($items as $item): ?>
            <article class="approval-item" aria-label="<?= h($item['name'] . 'さんの' . $item['type'] . 'の申請') ?>">
                <div class="approval-item__applicant">
                    <div class="approval-item__name"><?= h($item['name']) ?></div>
                    <div class="approval-item__department"><?= h($item['department']) ?></div>
                </div>
                <div><span class="approval-item__type"><?= h($item['type']) ?></span></div>
                <div class="approval-item__date"><?= h($item['targetDate']) ?></div>
                <div class="approval-item__content">
                    <ul class="approval-item__changes">
                        <?php foreach ($item['changes'] as $change): ?>
                            <li><?= h($change['label']) ?>　<?= h($change['before']) ?> → <b><?= h($change['after']) ?></b></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($item['reference'] !== ''): ?>
                        <p class="approval-item__note"><?= h($item['reference']) ?></p>
                    <?php endif; ?>
                    <?php if ($item['reason'] !== ''): ?>
                        <p class="approval-item__note">理由：<?= h($item['reason']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="approval-item__created"><span class="approval-item__label">申請 </span><?= h($item['createdAt']) ?></div>
                <?php if ($tab === 'todo'): ?>
                    <div class="approval-item__actions">
                        <button class="button button--primary" type="button" data-approve="<?= h($item['id']) ?>">承認</button>
                        <button class="button button--reject" type="button" data-reject="<?= h($item['id']) ?>" data-target="<?= h($item['name'] . ' さん・' . $item['type'] . '（' . $item['targetDate'] . '）') ?>">却下</button>
                    </div>
                <?php else: ?>
                    <div class="approval-item__result">
                        <span class="request-badge request-badge--<?= h($item['status']) ?>"><?= h($item['statusLabel']) ?></span>
                        <div class="approval-item__note"><?= h($item['reviewerName']) ?>　<?= h($item['reviewedAt']) ?></div>
                        <?php if ($item['reviewComment'] !== ''): ?>
                            <div class="approval-item__note">コメント：<?= h($item['reviewComment']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <p class="alert alert--error approvals__error" id="approval-error" role="alert" hidden></p>

    <!-- 却下（コメントは任意） -->
    <dialog class="dialog dialog--sheet" id="reject-dialog" aria-labelledby="reject-title">
        <form class="dialog__body" id="reject-form" novalidate>
            <h2 class="dialog__title" id="reject-title">申請を却下します</h2>
            <p class="dialog__subtitle" id="reject-target"></p>
            <p class="alert alert--error" id="reject-error" role="alert" hidden></p>
            <div class="form-field">
                <label class="form-field__label" for="reject-comment">コメント（任意）</label>
                <textarea class="form-field__input form-field__textarea" id="reject-comment" name="comment" rows="4" maxlength="<?= h(APPROVAL_COMMENT_MAX_LENGTH) ?>"></textarea>
            </div>
            <div class="dialog__actions">
                <button class="button button--outline" type="button" id="reject-cancel">キャンセル</button>
                <button class="button button--danger" type="submit" id="reject-submit">却下する</button>
            </div>
        </form>
    </dialog>
</main>
<script src="<?= h(assetUrl('js/approvals.js')) ?>" defer></script>
<?php include(__DIR__ . '/../templates/footer.php'); ?>
