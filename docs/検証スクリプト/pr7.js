// PR 7 システム管理者（アカウント管理・部署・勤務設定）の E2E 確認（Claude の環境用）
// 最初と最後に、テストデータ（docs/db/test_data.sql）の状態に戻す（作ったアカウント・部署を消し、勤務設定を初期値にする）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/pr7_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
const errors = [];
let browser;
async function open(opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ja-JP', ...opts });
  const page = await ctx.newPage();
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[01345]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
  return page;
}
async function login(id, pw, opts = {}) {
  const page = await open(opts);
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
async function canLogin(id, pw) {
  const page = await login(id, pw);
  const ok = !page.url().includes('login.php');
  await page.context().close();
  return ok;
}
// 画面を通さずに POST する（CSRF トークンは画面のフォームから取る。token を指定すると、それを使う）
async function post(page, path, body) {
  return page.evaluate(async ([path, b]) => {
    const fd = new FormData();
    fd.append('csrf_token', b.token ?? document.querySelector('input[name=csrf_token]').value);
    for (const [k, v] of Object.entries(b)) if (k !== 'token') fd.append(k, v);
    const r = await fetch('/nm_attendance/' + path, { method: 'POST', body: fd, redirect: 'manual' });
    return { status: r.status, type: r.type, text: r.type === 'opaqueredirect' ? '' : await r.text() };
  }, [path, body]);
}
const toast = (page) => page.textContent('#toast');
const rowText = (page, attr, id) => page.$eval(`tr[data-${attr}="${id}"]`, el => el.innerText.replace(/\s+/g, ' ')).catch(() => null);
const alertText = (page) => page.$$eval('.alert--error', els => els.filter(e => !e.hidden && e.offsetParent !== null).map(e => e.textContent.trim()));
const TEST_HASH = '$2y$10$Wym.MkHpWDNPV3umkOBDQeO3TTq2PNZDiNOYieKVw7.jk9BcKBwue'; // Test1234
function restore() {
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql("DELETE FROM login_accounts WHERE role <> 'system_admin' AND employee_id NOT IN ('2025001','2025002','2025003','2025004')");
  sql("DELETE FROM employees WHERE employee_id NOT IN ('2025001','2025002','2025003','2025004')");
  sql('DELETE FROM employee_id_sequences');
  sql("UPDATE employees SET employment_status = 'active', left_on = NULL");
  sql(`UPDATE login_accounts SET is_active = IF(login_id = '2025004', 0, 1), password_hash = '${TEST_HASH}' WHERE role <> 'system_admin'`);
  sql("DELETE FROM departments WHERE name NOT IN ('営業部','開発部')"); sql("UPDATE departments SET is_active = 1");
  sql("UPDATE work_settings SET standard_start = '09:00:00', standard_end = '18:00:00', lunch_start = '12:00:00', lunch_end = '13:00:00'");
}

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const Y = D.slice(0, 4);
  restore();
  browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  console.log('[1] メニュー・メイン画面・権限');
  const admin = await login('sysadmin', 'Admin1234');
  check('メイン画面に3つのボタン', JSON.stringify(await admin.$$eval('.admin-links__item', as => as.map(a => a.textContent))) === JSON.stringify(['アカウント管理', '部署', '勤務設定']));
  check('「出退勤の記録はありません」は出ない', !(await admin.content()).includes('出退勤の記録はありません'));
  await admin.click('#menu-open');
  check('メニュー', JSON.stringify(await admin.$$eval('#menu .menu__link', as => as.map(a => a.textContent))) === JSON.stringify(['メイン画面', 'アカウント管理', '部署', '勤務設定']));
  await Promise.all([admin.waitForNavigation(), admin.click('#menu >> text=部署')]);
  check('メニューから部署へ・今の画面に印', admin.url().endsWith('admin/departments.php') && (await admin.getAttribute('#menu a[aria-current=page]', 'href')).endsWith('admin/departments.php'));
  const manager = await login('2025002', 'Test1234');
  const employee = await login('2025001', 'Test1234');
  for (const [who, page] of [['管理者', manager], ['社員', employee]]) {
    await page.click('#menu-open');
    const links = await page.$$eval('#menu .menu__link', as => as.map(a => a.textContent));
    check(`${who}のメニューに管理の画面は無い`, !links.includes('アカウント管理') && !links.includes('部署') && !links.includes('勤務設定'), JSON.stringify(links));
    for (const p of ['accounts', 'departments', 'settings']) {
      check(`${who}は admin/${p}.php を開けない（403）`, (await page.goto(BASE + `admin/${p}.php`)).status() === 403);
    }
    await page.goto(BASE);
    check(`${who}は POST もできない（403）`, (await post(page, 'admin/departments.php', { action: 'add', name: '不正' })).status === 403 && sql("SELECT COUNT(*) FROM departments WHERE name = '不正'") === '0');
  }
  const guest = await open();
  await guest.goto(BASE + 'admin/accounts.php');
  check('未ログインはログイン画面へ', guest.url().endsWith('login.php'));
  await guest.context().close();

  console.log('[2] 部署');
  await admin.goto(BASE + 'admin/departments.php');
  check('一覧と在籍人数', (await rowText(admin, 'department', sql("SELECT id FROM departments WHERE name='営業部'"))).includes('営業部 2 人 有効'));
  await admin.fill('#department_name', '総務部');
  await Promise.all([admin.waitForNavigation(), admin.click('text=部署を追加')]);
  check('追加の通知', (await toast(admin)) === '部署「総務部」を追加しました。');
  const somu = sql("SELECT id FROM departments WHERE name='総務部'");
  check('一覧に出る（0 人）', (await rowText(admin, 'department', somu)).includes('総務部 0 人 有効'));
  await admin.fill('#department_name', '営業部');
  await Promise.all([admin.waitForNavigation(), admin.click('text=部署を追加')]);
  check('同じ名前はエラー・入力が残る', (await alertText(admin)).includes('同じ名前の部署があります。別の名前にしてください。') && (await admin.inputValue('#department_name')) === '営業部');
  await admin.fill('#department_name', '');
  await Promise.all([admin.waitForNavigation(), admin.click('text=部署を追加')]);
  check('空欄はエラー', (await alertText(admin)).includes('部署名を入力してください。'));
  // 名前の変更
  await admin.click(`tr[data-department="${somu}"] >> text=名前の変更`); await admin.waitForSelector('#rename-dialog[open]');
  check('名前の変更：今の名前が入っている', (await admin.inputValue('#rename_name')) === '総務部');
  await admin.click('#rename-dialog [data-close]'); await admin.waitForTimeout(100);
  check('キャンセルで閉じる', !(await admin.$('#rename-dialog[open]')));
  await admin.click(`tr[data-department="${somu}"] >> text=名前の変更`);
  await admin.fill('#rename_name', '開発部');
  await Promise.all([admin.waitForNavigation(), admin.click('#rename-dialog [type=submit]')]);
  await admin.waitForSelector('#rename-dialog[open]');
  check('重複はウィンドウを開き直してエラー', (await admin.textContent('#rename-dialog [data-dialog-error]')) === '同じ名前の部署があります。別の名前にしてください。' && (await admin.textContent('#rename-dialog [data-text=target]')) === '総務部');
  await admin.fill('#rename_name', '総務課');
  await Promise.all([admin.waitForNavigation(), admin.click('#rename-dialog [type=submit]')]);
  check('名前の変更の通知', (await toast(admin)) === '部署「総務部」の名前を「総務課」に変えました。' && sql(`SELECT name FROM departments WHERE id=${somu}`) === '総務課');
  // 無効化（在籍者がいる部署）
  const dev = sql("SELECT id FROM departments WHERE name='開発部'");
  await admin.click(`tr[data-department="${dev}"] >> text=無効化`); await admin.waitForSelector('#department-deactivate-dialog[open]');
  check('無効化：在籍者の注意', (await admin.textContent('#department-deactivate-dialog .dialog__text')).includes('在籍している社員 2 人は、この部署のままです。'));
  await admin.screenshot({ path: SHOT + 'dept_deactivate.png' });
  await Promise.all([admin.waitForNavigation(), admin.click('#department-deactivate-dialog [type=submit]')]);
  check('無効化の通知・一覧', (await toast(admin)) === '部署「開発部」を無効にしました。' && (await rowText(admin, 'department', dev)).includes('無効') && (await admin.$(`tr[data-department="${dev}"].admin-table__inactive`)) !== null);
  check('所属の社員はそのまま', sql("SELECT department_id FROM employees WHERE employee_id='2025003'") === dev);
  await admin.click(`tr[data-department="${somu}"] >> text=無効化`); await admin.waitForSelector('#department-deactivate-dialog[open]');
  check('在籍者がいなければ注意なし', !(await admin.textContent('#department-deactivate-dialog .dialog__text')).includes('在籍している社員'));
  await admin.click('#department-deactivate-dialog [data-close]');
  check('同じ操作をもう一度送るとエラー', (await post(admin, 'admin/departments.php', { action: 'deactivate', department_id: dev })).text.includes('この部署は、すでに無効です。'));
  await admin.fill('#department_name', '<b>営業二課</b>');
  await Promise.all([admin.waitForNavigation(), admin.click('text=部署を追加')]);
  check('部署名のエスケープ', (await admin.textContent('#toast')) === '部署「<b>営業二課</b>」を追加しました。' && (await admin.$('.admin-table b')) === null);
  await admin.screenshot({ path: SHOT + 'departments.png', fullPage: true });

  console.log('[3] アカウント作成');
  await admin.goto(BASE + 'admin/accounts.php');
  const options = await admin.$$eval('#department_id option', os => os.map(o => o.textContent));
  check('部署の選択欄：有効な部署だけ（無効の開発部は出ない）', JSON.stringify(options) === JSON.stringify(['選んでください', '営業部', '総務課', '<b>営業二課</b>']), JSON.stringify(options));
  check('入社日の初期値は今日', (await admin.inputValue('#joined_on')) === D);
  check('社員のときは承認範囲を出さない', await admin.$eval('#scope-field', el => el.hidden));
  await admin.selectOption('#role', 'manager');
  check('管理者を選ぶと承認範囲が出る（初期値は所属部署のみ）', !(await admin.$eval('#scope-field', el => el.hidden)) && (await admin.$eval('input[name=approval_scope]:checked', el => el.value)) === 'department');
  await admin.selectOption('#role', 'employee');
  async function fill(v) {
    await admin.fill('#name', v.name ?? '山田 花子'); await admin.fill('#email', v.email ?? 'hanako@example.com');
    await admin.selectOption('#department_id', { label: v.dept ?? '総務課' }); await admin.fill('#joined_on', v.joined ?? D);
    await admin.selectOption('#role', v.role ?? 'employee');
    if (v.scope) await admin.check(`input[name=approval_scope][value=${v.scope}]`);
    await admin.fill('#password', v.pw ?? 'Abcd1234'); await admin.fill('#password_confirm', v.pw2 ?? v.pw ?? 'Abcd1234');
    await Promise.all([admin.waitForNavigation(), admin.click('text=アカウントを作成')]);
  }
  // 入力の誤り（先に見つかった1つを出す。入力した値は残し、パスワードは消す）
  const cases = [
    [{ name: '' }, '氏名を入力してください。', '#name'],
    [{ email: 'hanako@' }, 'メールアドレスの形が正しくありません。', '#email'],
    [{ email: 'yamada@example.com' }, 'このメールアドレスは、すでに使われています。', '#email'],
    [{ joined: '1999-12-31' }, '入社日は、2000-01-01 から', '#joined_on'],
    [{ pw: 'short' }, 'パスワードは、半角の英数字・記号で 8〜64 文字にしてください。', '#password'],
    [{ pw: 'Abcd 1234' }, 'パスワードは、半角の英数字・記号で 8〜64 文字にしてください。', '#password'],
    [{ pw2: 'Abcd1235' }, '確認用のパスワードが一致しません。', '#password_confirm'],
  ];
  for (const [v, message, field] of cases) {
    await fill(v);
    const alerts = await alertText(admin);
    check(`誤り：${message}`, alerts.length === 1 && alerts[0].startsWith(message) && (await admin.getAttribute(field, 'aria-invalid')) === 'true', JSON.stringify(alerts));
  }
  check('誤りのあとも入力が残る（パスワード以外）', (await admin.inputValue('#name')) === '山田 花子' && (await admin.inputValue('#email')) === 'hanako@example.com'
    && (await admin.$eval('#department_id option:checked', o => o.textContent)) === '総務課' && (await admin.inputValue('#password')) === '');
  check('誤りでは採番しない', sql('SELECT COUNT(*) FROM employee_id_sequences') === '0');
  await admin.screenshot({ path: SHOT + 'account_error.png', fullPage: true });
  // 管理者で誤り → 承認範囲が出たまま
  await fill({ name: '', role: 'manager', scope: 'all' });
  check('管理者で誤り：承認範囲が出たまま・選んだ値が残る', !(await admin.$eval('#scope-field', el => el.hidden)) && (await admin.$eval('input[name=approval_scope]:checked', el => el.value)) === 'all');
  // 作成
  await fill({ name: '<script>alert(1)</script>山田 花子' });
  const first = `${Y}001`;
  check('作成の通知（社員コード）', (await toast(admin)) === `アカウント「${first}」（<script>alert(1)</script>山田 花子）を作成しました。社員コード（ログイン ID）とパスワードを、本人に伝えてください。`);
  check('入力欄が空に戻る', (await admin.inputValue('#name')) === '' && (await admin.inputValue('#email')) === '' && (await admin.inputValue('#joined_on')) === D);
  check('一覧に出る（エスケープ）', (await rowText(admin, 'employee', first)).startsWith(`${first} <script>alert(1)</script>山田 花子 hanako@example.com 総務課 社員 在籍`), await rowText(admin, 'employee', first));
  check('件数', (await admin.textContent('#list-title')) === '登録済みアカウント（5）');
  check('DB：社員とログイン情報', sql(`SELECT CONCAT(e.joined_on,'|',e.employment_status,'|',l.role,'|',l.approval_scope,'|',l.is_active) FROM employees e JOIN login_accounts l ON l.employee_id=e.employee_id WHERE e.employee_id='${first}'`) === `${D}|active|employee|department|1`);
  await fill({ name: '佐々木 次郎', email: 'sasaki@example.com', dept: '営業部', role: 'manager', scope: 'all', pw: 'P@ss-w0rd!' });
  const second = `${Y}002`;
  check('2人目は 002・管理者（全社員）', (await toast(admin)).startsWith(`アカウント「${second}」（佐々木 次郎）`) && (await rowText(admin, 'employee', second)).includes('管理者 全社員'));
  await admin.screenshot({ path: SHOT + 'accounts.png', fullPage: true });
  check('作ったアカウントでログインできる', await canLogin(first, 'Abcd1234'));
  const sasaki = await login(second, 'P@ss-w0rd!');
  await sasaki.click('#menu-open');
  check('管理者のメニューに「承認」', (await sasaki.$$eval('#menu .menu__link', as => as.map(a => a.textContent))).includes('承認'));
  check('ヘッダーに部署名', (await sasaki.textContent('.app-header__department')) === '営業部');
  // 画面を通さずに送る：無効の部署・システム管理者の権限
  let r = await post(admin, 'admin/accounts.php', { action: 'create', name: 'x', email: 'x@example.com', department_id: dev, joined_on: D, role: 'employee', password: 'Abcd1234', password_confirm: 'Abcd1234' });
  check('無効の部署は作れない', r.text.includes('選んだ部署は使えません。'));
  r = await post(admin, 'admin/accounts.php', { action: 'create', name: 'x', email: 'x@example.com', department_id: sql("SELECT id FROM departments WHERE name='営業部'"), joined_on: D, role: 'system_admin', password: 'Abcd1234', password_confirm: 'Abcd1234' });
  check('システム管理者は作れない', r.text.includes('権限を選んでください。') && sql("SELECT COUNT(*) FROM employees WHERE email='x@example.com'") === '0');

  console.log('[4] 無効化・有効に戻す');
  const yamada = await login('2025001', 'Test1234'); // ログイン中に無効にされる社員
  await admin.goto(BASE + 'admin/accounts.php');
  await admin.click('tr[data-employee="2025001"] >> text=無効化'); await admin.waitForSelector('#deactivate-dialog[open]');
  check('退社日の初期値は今日・範囲（入社日〜今日）', (await admin.inputValue('#left_on')) === D && (await admin.getAttribute('#left_on', 'min')) === '2025-04-01' && (await admin.getAttribute('#left_on', 'max')) === D);
  check('ウィンドウに対象', (await admin.textContent('#deactivate-dialog [data-text=target]')) === '2025001　山田 太郎');
  await admin.click('#deactivate-dialog [data-close]');
  check('キャンセルで変わらない', sql("SELECT is_active FROM login_accounts WHERE login_id='2025001'") === '1');
  r = await post(admin, 'admin/accounts.php', { action: 'deactivate', employee_id: '2025001', left_on: '2025-03-31' });
  check('範囲外の退社日はエラー', r.text.includes('退社日は、2025-04-01 から ' + D + ' までの日を指定してください。') && sql("SELECT is_active FROM login_accounts WHERE login_id='2025001'") === '1');
  // 誤りのとき、ウィンドウを開き直す（日付の入力欄の min を外して送る）
  await admin.click('tr[data-employee="2025001"] >> text=無効化');
  await admin.$eval('#left_on', el => { el.removeAttribute('min'); el.value = '2025-03-31'; });
  await Promise.all([admin.waitForNavigation(), admin.click('#deactivate-dialog [type=submit]')]);
  await admin.waitForSelector('#deactivate-dialog[open]');
  check('誤りはウィンドウを開き直して出す', (await admin.textContent('#deactivate-dialog [data-dialog-error]')).startsWith('退社日は、2025-04-01 から'));
  await admin.fill('#left_on', D);
  await Promise.all([admin.waitForNavigation(), admin.click('#deactivate-dialog [type=submit]')]);
  const ymd = D.replace(/-/g, '/');
  check('無効化の通知・一覧（退職・退社日）', (await toast(admin)) === 'アカウント「2025001」（山田 太郎）を無効にしました。' && (await rowText(admin, 'employee', '2025001')).includes(`退職 退社日 ${ymd}`));
  check('DB：在籍状態・退社日・ログイン不可', sql("SELECT CONCAT(e.employment_status,'|',e.left_on,'|',l.is_active) FROM employees e JOIN login_accounts l ON l.employee_id=e.employee_id WHERE e.employee_id='2025001'") === `retired|${D}|0`);
  check('無効にしたアカウントはログインできない', !(await canLogin('2025001', 'Test1234')));
  await yamada.goto(BASE + 'records/');
  check('ログイン中だった社員も、次の操作でログイン画面へ', yamada.url().endsWith('login.php'));
  await yamada.context().close();
  check('部署の在籍人数は退職を数えない（営業部：山田が退職・佐々木が加わって 2 人）', (await (async () => { await admin.goto(BASE + 'admin/departments.php'); return rowText(admin, 'department', sql("SELECT id FROM departments WHERE name='営業部'")); })()).includes('営業部 2 人'));
  await admin.goto(BASE + 'admin/accounts.php');
  await admin.click('tr[data-employee="2025001"] >> text=有効に戻す'); await admin.waitForSelector('#reactivate-dialog[open]');
  await Promise.all([admin.waitForNavigation(), admin.click('#reactivate-dialog [type=submit]')]);
  check('有効に戻すの通知・一覧', (await toast(admin)) === 'アカウント「2025001」（山田 太郎）を有効に戻しました。' && (await rowText(admin, 'employee', '2025001')).includes('在籍'));
  check('DB：在籍・退社日なし・ログイン可', sql("SELECT CONCAT(e.employment_status,'|',IFNULL(e.left_on,'NULL'),'|',l.is_active) FROM employees e JOIN login_accounts l ON l.employee_id=e.employee_id WHERE e.employee_id='2025001'") === 'active|NULL|1');
  check('ログインできる', await canLogin('2025001', 'Test1234'));
  check('テストデータの 2025004 は「退職」で「有効に戻す」', (await rowText(admin, 'employee', '2025004')).includes('退職') && (await admin.$('tr[data-employee="2025004"] [data-open=reactivate-dialog]')) !== null);
  r = await post(admin, 'admin/accounts.php', { action: 'deactivate', employee_id: 'sysadmin', left_on: D });
  check('システム管理者は対象にできない', r.text.includes('このアカウントは見つかりません。') && sql("SELECT is_active FROM login_accounts WHERE login_id='sysadmin'") === '1');

  console.log('[5] パスワード再設定');
  await admin.click(`tr[data-employee="${first}"] >> text=パスワード再設定`); await admin.waitForSelector('#password-dialog[open]');
  await admin.fill('#new_password', 'NewPass#01'); await admin.fill('#new_password_confirm', 'NewPass#02');
  await Promise.all([admin.waitForNavigation(), admin.click('#password-dialog [type=submit]')]);
  await admin.waitForSelector('#password-dialog[open]');
  check('確認が違う：ウィンドウを開き直して出す・欄は空', (await admin.textContent('#password-dialog [data-dialog-error]')) === '確認用のパスワードが一致しません。' && (await admin.inputValue('#new_password')) === '');
  await admin.screenshot({ path: SHOT + 'password_error.png' });
  await admin.fill('#new_password', 'NewPass#01'); await admin.fill('#new_password_confirm', 'NewPass#01');
  await Promise.all([admin.waitForNavigation(), admin.click('#password-dialog [type=submit]')]);
  check('再設定の通知', (await toast(admin)) === `アカウント「${first}」（<script>alert(1)</script>山田 花子）のパスワードを再設定しました。新しいパスワードを、本人に伝えてください。`);
  check('新しいパスワードでログインできる', await canLogin(first, 'NewPass#01'));
  check('古いパスワードではできない', !(await canLogin(first, 'Abcd1234')));

  console.log('[6] 勤務設定');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at) VALUES ('2025001','${D}','${D} 09:00:00','${D} 19:00:00')`);
  const total = async () => { const p = await login('2025001', 'Test1234'); await p.goto(BASE + 'records/'); const t = await p.textContent('#summary-total'); await p.context().close(); return t; };
  check('変更前の総労働時間（9:00〜19:00、昼休憩 1 時間）', (await total()) === '9時間00分', await total());
  await admin.goto(BASE + 'admin/settings.php');
  check('今の値', (await admin.inputValue('#standard_start')) === '09:00' && (await admin.inputValue('#lunch_end')) === '13:00');
  check('最終更新日時', /^最終更新：\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/.test((await admin.textContent('#settings-updated')).trim()));
  await admin.fill('#lunch_start', '08:30');
  await Promise.all([admin.waitForNavigation(), admin.click('text=保存')]);
  check('昼休憩が始業より前はエラー・入力が残る', (await alertText(admin)).includes('昼休憩は、標準始業時刻から標準終業時刻までの間にしてください。') && (await admin.inputValue('#lunch_start')) === '08:30' && (await admin.getAttribute('#lunch_start', 'aria-invalid')) === 'true');
  check('エラーでは変わらない', sql('SELECT lunch_start FROM work_settings') === '12:00:00');
  await admin.fill('#standard_end', '09:00'); await admin.fill('#lunch_start', '12:00');
  await Promise.all([admin.waitForNavigation(), admin.click('text=保存')]);
  check('終業が始業より前はエラー', (await alertText(admin)).includes('標準終業時刻は、標準始業時刻より後にしてください。'));
  await admin.fill('#standard_end', '17:30'); await admin.fill('#lunch_start', '12:00'); await admin.fill('#lunch_end', '14:00');
  await Promise.all([admin.waitForNavigation(), admin.click('text=保存')]);
  check('保存の通知', (await toast(admin)) === '勤務設定を保存しました。' && sql("SELECT CONCAT(standard_end,'|',lunch_start,'|',lunch_end) FROM work_settings") === '17:30:00|12:00:00|14:00:00');
  await admin.screenshot({ path: SHOT + 'settings.png', fullPage: true });
  check('記録表示の労働時間が変わる（昼休憩 2 時間）', (await total()) === '8時間00分', await total());
  const main = await login('2025001', 'Test1234');
  check('メイン画面の標準終了時刻も変わる', (await main.textContent('.overtime__note')).includes('17:30'));
  await main.context().close();

  console.log('[7] 送信のチェック');
  r = await post(admin, 'admin/settings.php', { token: 'wrong', standard_start: '09:00', standard_end: '18:00', lunch_start: '12:00', lunch_end: '13:00' });
  check('CSRF が違うと 400・変わらない', r.status === 400 && sql('SELECT standard_end FROM work_settings') === '17:30:00');
  check('知らない操作は 400', (await post(admin, 'admin/accounts.php', { action: 'drop' })).status === 400 && (await post(admin, 'admin/departments.php', { action: 'drop' })).status === 400);
  r = await post(admin, 'admin/settings.php', { standard_start: '09:00', standard_end: '18:00', lunch_start: '12:00', lunch_end: '13:00' });
  check('成功はリダイレクト（二重送信の防止）', r.type === 'opaqueredirect');

  console.log('[8] スマホの幅');
  const phone = await login('sysadmin', 'Admin1234', { viewport: { width: 390, height: 844 } });
  for (const p of ['', 'admin/accounts.php', 'admin/departments.php', 'admin/settings.php']) {
    await phone.goto(BASE + p);
    const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`横にはみ出さない：${p || 'メイン画面'}`, overflow <= 0, String(overflow));
  }
  await phone.goto(BASE + 'admin/accounts.php');
  await phone.screenshot({ path: SHOT + 'mobile_accounts.png', fullPage: true });
  await phone.click('tr[data-employee="2025002"] >> text=無効化'); await phone.waitForSelector('#deactivate-dialog[open]');
  await phone.screenshot({ path: SHOT + 'mobile_dialog.png' });

  await browser.close();
  restore();
  console.log('JS・500 のエラー: ' + (errors.length ? errors.join('\n') : 'なし'));
  console.log(`結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail || errors.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
