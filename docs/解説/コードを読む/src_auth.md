# `src/auth.php` を読む（ログインと権限の確認）

## このファイルの役割

**「誰がログインしているか」「その人がこの画面を使ってよいか」を決める**ファイル。ログイン・ログアウトの処理もここにある。

- 画面（`index.php`・`records/index.php` など）は最初に `requireLogin()` を、API は `currentUser()` を呼ぶ。権限が限られる画面は、続けて `requireRole()` を呼ぶ。
- SQL は書かない。ログイン情報と社員の情報は、`src/queries/` の関数（依頼者が書く）で読む。
- セッションの細かい扱いは `src/session.php` に任せる（[src_session.md](./src_session.md)）。

## 全体の構成

| 行のあたり | 中身 |
| --- | --- |
| 4〜6行 | 部品の読み込み |
| 8〜20行 | 定数（権限の名前、システム管理者の画面、ダミーのハッシュ） |
| 22〜37行 | `attemptLogin()`：ID とパスワードでログインする |
| 39〜51行 | `logoutUser()`：ログアウトする |
| 53〜61行 | `requireLogin()`：ログインしていなければログイン画面へ（画面用） |
| 63〜100行 | `currentUser()`：ログイン中の利用者を返す（API 用。`requireLogin()` の中身） |
| 102〜107行 | `requireRole()`：権限が無ければ 403 で止める |

---

## 1. 部品の読み込みと定数

```php
include_once(__DIR__ . '/session.php');
include_once(__DIR__ . '/queries/login_accounts.php');
include_once(__DIR__ . '/queries/employees.php');
```

- `session.php`：`startAppSession()` など。`auth.php` を読み込めば、セッションの関数も使えるようになる。
- `queries/login_accounts.php`：`fetchLoginAccount()`（ログイン ID で1行読む。無ければ `null`）・`updateLastLoginAt()`（最終ログイン日時を更新）。
- `queries/employees.php`：`fetchEmployeeProfile()`（氏名・部署名など）。

```php
const ROLE_EMPLOYEE = 'employee';
const ROLE_MANAGER = 'manager';
const ROLE_SYSTEM_ADMIN = 'system_admin';
```

- 権限は3つ：社員・管理者（承認ができる）・システム管理者（アカウント・部署・勤務設定を管理する）。DB の `login_accounts.role` に入る値と同じ。
- 文字列を直接書かずに定数を使うので、打ち間違えれば PHP がエラーにしてくれる。

```php
// システム管理者の画面（メニューと、メイン画面のボタンに使う。D-067）
const ADMIN_MENU_ITEMS = [
    ['path' => 'admin/accounts.php', 'label' => 'アカウント管理'],
    ['path' => 'admin/departments.php', 'label' => '部署'],
    ['path' => 'admin/settings.php', 'label' => '勤務設定'],
];
```

- システム管理者の画面の一覧。ハンバーガーメニュー（`templates/menu.php`）と、システム管理者のメイン画面のボタン（`index.php`）の両方が、この定数からリンクを作る。一覧が1か所なので、2つが食い違わない。

```php
// 存在しない ID でも、パスワードの照合にかかる時間を同じにするためのダミー（何とも一致しない）
const DUMMY_PASSWORD_HASH = '$2y$10$Qae.ksyHzAjZva91gzGTHerEEdLz5O1RwvcW2jmo4Ueb2A4xD8n6K';
```

- `password_hash()` で作った形のハッシュ（`$2y$` は bcrypt という方式、`10` は計算の重さ）。使い道は次の章で説明する。

## 2. `attemptLogin()`：ID とパスワードでログインする

```php
function attemptLogin(PDO $pdo, string $loginId, string $password): bool {
    $account = fetchLoginAccount($pdo, $loginId);
    $passwordOk = password_verify($password, $account['password_hash'] ?? DUMMY_PASSWORD_HASH);
    if ($account === null || !$passwordOk || (int) $account['is_active'] !== 1) {
        return false;
    }
```

- `fetchLoginAccount()` は、ID が見つからなければ `null` を返す。
- `password_verify(入力, ハッシュ)`：入力したパスワードが、保存してあるハッシュと合うかを確かめる。DB にはパスワードそのものではなく、`password_hash()` で作ったハッシュ（元に戻せない値）だけを保存している。
- `$account['password_hash'] ?? DUMMY_PASSWORD_HASH`：`$account` が `null`（ID が無い）なら、ダミーのハッシュを使う。`??` は、左が無いか `null` なら右を使うので、`null` の配列を読んでもエラーにならない。
- 失敗の理由は3つ（ID が無い・パスワードが違う・利用不可）あるが、**どれでも同じ `false` を返す**。呼ぶ側（`login.php`）は理由を区別できないので、画面にも同じ文しか出せない。
- `(int) $account['is_active'] !== 1`：`is_active` は DB の `1`／`0`。`(int)` で数値にそろえてから `!==`（型まで比べる）で比べる。

> **ここが重要**：`password_verify()` は、わざと時間がかかるように作られている（パスワードの総当たりを遅くするため）。もし「ID が無ければ、照合せずにすぐ `false`」と書くと、**ID が無いときだけ返事が速くなり**、返事の速さから「この ID は存在する」と分かってしまう。ID が無いときもダミーのハッシュで照合して、かかる時間をそろえている。ダミーは、どんなパスワードとも一致しないハッシュなので、ログインできてしまうことはない。

```php
    // セッション ID を作り直す（ログイン前の ID を使い回されないようにする）
    session_regenerate_id(true);
    $_SESSION['user'] = ['login_id' => $account['login_id']];
    unset($_SESSION['timed_out']);
    updateLastLoginAt($pdo, $account['login_id']);
    return true;
}
```

- `session_regenerate_id(true)`：セッション ID を新しくする（`true` は古い ID のファイルを消す指定）。`$_SESSION` の中身は引き継がれる。
- `$_SESSION['user']` には **`login_id` だけ**を入れる。氏名や権限は入れず、毎回 DB から読み直す（4章）。
- `unset($_SESSION['timed_out'])`：自動ログアウトの印が残っていれば消す。
- `updateLastLoginAt()`：最終ログイン日時（`login_accounts.last_login_at`）を、今の日時にする。

> **ここが重要**：ログインの前後でセッション ID を変えないと、**セッション固定攻撃**を受けるおそれがある。攻撃者が、自分の知っているセッション ID を何らかの方法で利用者のブラウザに使わせ、利用者がその ID のままログインすると、攻撃者も同じ ID でログイン中として扱われてしまう。ログインに成功した時点で ID を作り直せば、攻撃者の知っている ID は使えなくなる。

## 3. `logoutUser()`：ログアウトする

```php
function logoutUser(): void {
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}
```

ログアウトでは、3つを消す。

1. `$_SESSION = [];`：このリクエストの中の、セッションの値を消す。
2. `setcookie(…)`：ブラウザのセッションの Cookie を消す。Cookie には「消す」命令が無いので、**有効期限を過去（1時間前）にした空の Cookie** を送って、ブラウザに消させる。
3. `session_destroy()`：サーバーに保存してあるセッションのファイルを消す。

- `session_get_cookie_params()` で、セッションを始めたときの Cookie の設定（`path` など）を取り出し、同じ値で `setcookie()` する。**`path` などが違うと、ブラウザは別の Cookie とみなし、元の Cookie が消えない**ため。

## 4. `requireLogin()` と `currentUser()`：ログイン中の利用者

### `requireLogin()`：画面用

```php
// ログインしていなければログイン画面へ移す（BR-003）。ログイン中の利用者を返す
// 戻り値：['login_id', 'employee_id', 'role', 'approval_scope', 'name', 'department_name', 'joined_on', 'department_id']（システム管理者の joined_on・department_id は null）
function requireLogin(PDO $pdo): array {
    $user = currentUser($pdo);
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}
```

- 中身は `currentUser()` に任せ、`null`（ログインしていない）ならログイン画面へ移す。
- `redirect()` の戻り値の型は `never`（必ず `exit` で終わる）なので、`if` の後ろに来るのは「ログインしている」ときだけ。戻り値の型を `?array` ではなく `array` にできる。呼ぶ側は `null` を気にせずに使える。

### `currentUser()`：API 用

画面は、ログインしていなければログイン画面へ移せばよい。しかし API は JavaScript から呼ばれるので、移すのではなく「401（ログインしてください）」の JSON を返したい。そのため、**移さずに `null` を返す** `currentUser()` を別に用意している。

```php
function currentUser(PDO $pdo): ?array {
    startAppSession();
    $loginId = $_SESSION['user']['login_id'] ?? null;
    if ($loginId === null) {
        return null;
    }
```

- まずセッションを始める。ここで2時間の自動ログアウトの判断も行われる（[src_session.md](./src_session.md)）。
- `$_SESSION['user']['login_id'] ?? null`：`$_SESSION['user']` が無くても、`??` なのでエラーにならず `null` になる。

```php
    // ログイン中に利用不可にされた場合も、すぐに使えなくする
    $account = fetchLoginAccount($pdo, $loginId);
    if ($account === null || (int) $account['is_active'] !== 1) {
        logoutUser();
        return null;
    }
```

- セッションに `login_id` があっても、それだけで信用しない。**リクエストのたびに DB を読み直し**、アカウントが無い・利用不可なら、その場でログアウトさせる。

> **ここが重要**：セッションに氏名や権限まで保存しておけば、DB を読まずに済む。しかしそうすると、システム管理者がアカウントを無効にしても（退職の処理。ログインの可否が「利用不可」になる）、その人がログアウトするまで使えてしまう。**セッションには `login_id` だけを持ち、権限などは毎回 DB から読む**ことで、変更がすぐに効く。

```php
    $user = [
        'login_id' => $account['login_id'],
        'employee_id' => $account['employee_id'],
        'role' => $account['role'],
        'approval_scope' => $account['approval_scope'],
        'name' => 'システム管理者',
        'department_name' => '',
        'joined_on' => null,
        'department_id' => null,
    ];
```

- まず、システム管理者のときの形で配列を作る。システム管理者は社員ではない（社員の行が無い）ので、氏名は「システム管理者」、部署名は空、入社日・部署 ID は `null`。
- `approval_scope`：管理者が承認できる範囲（自分の部署か、全社か）。

```php
    if ($account['role'] !== ROLE_SYSTEM_ADMIN) {
        $profile = fetchEmployeeProfile($pdo, $account['employee_id']);
        if ($profile === null) {
            throw new RuntimeException('社員の情報がありません: ' . $account['employee_id']);
        }
        $user['name'] = $profile['name'];
        $user['department_name'] = $profile['department_name'];
        $user['joined_on'] = $profile['joined_on'] ?? null;
        $user['department_id'] = isset($profile['department_id']) ? (int) $profile['department_id'] : null;
    }
    return $user;
}
```

- 社員・管理者なら、社員の情報（氏名・部署名・入社日・部署 ID）を読んで、上書きする。
- 社員の情報が無いのは、データがおかしいとき（ログイン情報はあるのに、社員の行が無い）。プログラムで対応できることではないので、例外を投げる。共通のエラー処理が受け止めて 500 にし、ログに記録する（[src_errors.md](./src_errors.md)）。
- `(int) $profile['department_id']`：部署 ID を数値にそろえる。`isset()` で、値があるときだけ変換する。
- **どの権限でも、返す配列のキーは同じ8つ**。呼ぶ側は、権限によってキーがあるか無いかを気にしなくてよい。

## 5. `requireRole()`：権限を確かめる

```php
// 指定した権限のどれかを持っていなければ、処理を止める（例：requireRole($user, [ROLE_MANAGER])）
function requireRole(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) {
        abort(403, 'この画面を使う権限がありません。');
    }
}
```

- `in_array(探す値, 配列, true)`：配列の中に、その値があるか。3つ目の `true` は、型まで同じものだけを「ある」とみなす指定（`==` ではなく `===` で比べる）。
- 使う側の例：承認の画面は `requireRole($currentUser, [ROLE_MANAGER])`、記録表示は `requireRole($currentUser, [ROLE_EMPLOYEE, ROLE_MANAGER])`、管理の画面は `requireRole($currentUser, [ROLE_SYSTEM_ADMIN])`。
- `403 Forbidden`：「ログインはしているが、この画面を使う権限が無い」という HTTP の状態。

> **ここが重要**：メニューに出さないだけでは、URL を直接打てば画面を開けてしまう。**権限の確認は、各画面の PHP で必ず行う**。メニュー（`templates/menu.php`）の出し分けは、見た目のためだけ。

---

## まとめ：このファイルから学べること

| 書き方 | このファイルでの例 |
| --- | --- |
| 失敗の理由を外に教えない | `attemptLogin()` は `true`／`false` だけ |
| 返事の速さでも教えない | ID が無くてもダミーのハッシュで照合する |
| パスワードはハッシュで持つ | `password_verify()` で照合 |
| ログインしたら ID を作り直す | `session_regenerate_id(true)` |
| Cookie は同じ設定で消す | `session_get_cookie_params()` の値で `setcookie()` |
| セッションには最小限だけ | `login_id` だけ持ち、権限などは毎回 DB から |
| 画面用と API 用を分ける | `requireLogin()`（移す）と `currentUser()`（`null` を返す） |
| 戻り値の形をそろえる | どの権限でも同じ8つのキー |
| 権限はサーバーで確かめる | `requireRole()`、`in_array(…, true)` |

## 関連するファイル

- セッション：[src_session.md](./src_session.md)
- 呼ぶ側：[login.md](./login.md)（`attemptLogin()`）、[logout.md](./logout.md)（`logoutUser()`）、[index.md](./index.md)（`requireLogin()`・`ADMIN_MENU_ITEMS`）、[templates.md](./templates.md)（権限ごとのメニュー）、`api/` の各ファイル（`currentUser()`。[api_attendance.md](./api_attendance.md) など）、[records_index.md](./records_index.md)・[approvals_index.md](./approvals_index.md)・`admin/` の各画面（`requireRole()`）
- DB の読み書き：[src_queries_employees_login_accounts.md](./src_queries_employees_login_accounts.md)（`fetchLoginAccount()`・`updateLastLoginAt()`・`fetchEmployeeProfile()`）
- 止めるときの関数：[src_functions.md](./src_functions.md)（`redirect()`・`abort()`）
