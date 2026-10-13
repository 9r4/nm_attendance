<?php
// ログイン画面（FR-001）
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/auth.php');

startAppSession();
if (isset($_SESSION['user'])) {
    redirect();
}

$loginId = '';
$errorMessage = '';
$infoMessage = '';
if (!empty($_SESSION['timed_out'])) {
    $infoMessage = '一定時間操作がなかったため、ログアウトしました。もう一度ログインしてください。';
    unset($_SESSION['timed_out']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $loginId = is_string($_POST['login_id'] ?? null) ? trim($_POST['login_id']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($loginId === '' || $password === '') {
        $errorMessage = 'ID とパスワードを入力してください。';
    } elseif (strlen($loginId) <= 20 && attemptLogin($pdo, $loginId, $password)) {
        redirect();
    } else {
        $errorMessage = 'ID またはパスワードが正しくありません。';
    }
}

$pageTitle = 'ログイン';
include(__DIR__ . '/templates/header.php');
?>
<main class="login">
    <div class="login__card">
        <div>
            <h1 class="login__title">ログイン</h1>
            <p class="login__lead">ID とパスワードを入力してください。</p>
        </div>

        <?php if ($infoMessage !== ''): ?>
            <p class="alert alert--info" role="status"><?= h($infoMessage) ?></p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <p class="alert alert--error" role="alert"><?= h($errorMessage) ?></p>
        <?php endif; ?>

        <form class="login__form" method="post" action="<?= h(appUrl('login.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="form-field">
                <label class="form-field__label" for="login_id">ID</label>
                <input class="form-field__input" type="text" id="login_id" name="login_id" value="<?= h($loginId) ?>" maxlength="20" autocomplete="username" required autofocus>
            </div>
            <div class="form-field">
                <label class="form-field__label" for="password">パスワード</label>
                <input class="form-field__input" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="button button--primary button--block" type="submit">ログイン</button>
        </form>

        <details class="login__forgot">
            <summary>パスワードをお忘れの方</summary>
            <p>システム管理者にお問い合わせください。</p>
        </details>
        <p class="login__note">アカウントは、システム管理者が作成します。</p>
    </div>
</main>
<?php include(__DIR__ . '/templates/footer.php'); ?>
