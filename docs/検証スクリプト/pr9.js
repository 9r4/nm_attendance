// PR 9 小さな修正の E2E 確認（Claude の環境用。WSL と同じ版の環境＝ docker の attendance-wsl で動かす）
// 自動ログアウト後のログアウト、ログイン画面の有効期限、残業申請が申請中のボタン、アカウント作成の確認の順番、承認のコメント
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
// コンテナの中のセッションのファイルを書き換える（自動ログアウト・有効期限切れを、待たずに起こす）
const inContainer = (cmd) => execFileSync('docker', ['exec', 'attendance-wsl', 'bash', '-c', cmd]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
const errors = [];
let browser;
async function open() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ja-JP' });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
  return page;
}
async function login(id, pw) {
  const page = await open();
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
const sessionId = async (page) => (await page.context().cookies()).find(c => c.name === 'ATTENDANCE_SESSID').value;

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  console.log('[1] 自動ログアウトのあとに「ログアウト」を押す（2-3）');
  let page = await login('2025001', 'Test1234');
  let sid = await sessionId(page);
  // 最後の操作を 3 時間前にする（2 時間で自動ログアウト）
  inContainer(`f=/tmp/attendance_sessions/sess_${sid}; sed -i -E "s/last_activity\\|i:[0-9]+;/last_activity|i:$(( $(date +%s) - 10800 ));/" $f && grep -o 'last_activity|i:[0-9]*' $f`);
  let res = null;
  page.on('response', r => { if (r.url().endsWith('logout.php')) res = r.status(); });
  await Promise.all([page.waitForNavigation(), page.click('.app-header form button[type=submit]')]);
  check('400 にならず、ログイン画面へ（302）', res === 302 && page.url().endsWith('login.php'), `${res} ${page.url()}`);
  check('「一定時間操作がなかったため…」が出る', (await page.textContent('.alert--info')).includes('一定時間操作がなかったため、ログアウトしました。'));
  await page.context().close();

  console.log('[2] ログイン中のログアウトは、今までどおり CSRF を確かめる');
  page = await login('2025001', 'Test1234');
  let r = await page.evaluate(async () => { const fd = new FormData(); fd.append('csrf_token', 'wrong'); return (await fetch('/nm_attendance/logout.php', { method: 'POST', body: fd })).status; });
  check('トークンが違うと 400', r === 400);
  check('ログインしたまま', !(await (await page.goto(BASE)).url()).endsWith('login.php'));
  await Promise.all([page.waitForNavigation(), page.click('.app-header form button[type=submit]')]);
  check('正しいログアウトはログイン画面へ（通知なし）', page.url().endsWith('login.php') && !(await page.$('.alert--info')));
  await page.context().close();

  console.log('[3] ログイン画面の有効期限が切れたあとにログイン（2-4）');
  page = await open();
  await page.goto(BASE + 'login.php');
  sid = await sessionId(page);
  inContainer(`rm -f /tmp/attendance_sessions/sess_${sid}`); // 長く置いて、セッションが消えたのと同じ
  await page.fill('#login_id', '2025001'); await page.fill('#password', 'Test1234');
  res = null;
  page.on('response', x => { if (x.url().endsWith('login.php') && x.request().method() === 'POST') res = x.status(); });
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  check('400 にならず、同じ画面に「画面の有効期限が切れました…」', res === 200 && (await page.textContent('.alert--error')) === '画面の有効期限が切れました。もう一度ログインしてください。', `${res}`);
  check('ログインはしていない・ID は残り、パスワードは空', page.url().endsWith('login.php') && (await page.inputValue('#login_id')) === '2025001' && (await page.inputValue('#password')) === '');
  await page.fill('#password', 'Test1234');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  check('もう一度押すとログインできる', !page.url().endsWith('login.php'));
  await page.context().close();
  page = await open();
  await page.goto(BASE + 'login.php');
  r = await page.evaluate(async () => { const fd = new FormData(); fd.append('csrf_token', 'wrong'); fd.append('login_id', '2025001'); fd.append('password', 'Test1234'); const x = await fetch('/nm_attendance/login.php', { method: 'POST', body: fd, redirect: 'manual' }); return [x.status, x.type, await x.text().catch(() => '')]; });
  check('トークンが違う送信では、ログインしない（リダイレクトしない）', r[0] === 200 && r[1] !== 'opaqueredirect' && r[2].includes('画面の有効期限が切れました'), JSON.stringify(r.slice(0, 2)));
  await page.context().close();

  console.log('[4] 簡単残業申請が申請中のあいだ、ボタンを押せない（2-5）');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at) VALUES ('2025001','${D}','${D} 09:00:00','${D} 19:30:00')`);
  page = await login('2025001', 'Test1234');
  check('最初はボタンを押せる・いつもの案内', !(await page.isDisabled('#overtime-open')) && await page.isVisible('#overtime-note') && !(await page.isVisible('#overtime-pending')));
  await page.click('#overtime-open'); await page.fill('#overtime-end', '19:00');
  await page.click('#overtime-submit');
  await page.waitForFunction(() => !document.getElementById('toast').hidden);
  check('申請の通知', (await page.textContent('#toast')).includes('残業申請を受け付けました'));
  check('すぐにボタンを押せなくなり、「本日分の残業申請は申請中です。」', await page.isDisabled('#overtime-open') && await page.isVisible('#overtime-pending') && (await page.textContent('#overtime-pending')) === '本日分の残業申請は申請中です。' && !(await page.isVisible('#overtime-note')));
  await page.reload();
  check('読み込み直しても押せない', await page.isDisabled('#overtime-open') && await page.isVisible('#overtime-pending'));
  // 退勤を押す（上書きの確認 → 上書きする）。返ってくる表示でも、押せないまま
  await page.click('[data-action=check_out]'); await page.waitForSelector('#confirm-dialog[open]');
  await page.click('#confirm-dialog button[value=ok]');
  await page.waitForFunction(() => document.getElementById('toast').textContent.includes('上書き'));
  check('退勤を上書きしたあとも押せない・案内も申請中', await page.isDisabled('#overtime-open') && await page.isVisible('#overtime-pending'));
  check('DB の残業申請は1件', sql("SELECT COUNT(*) FROM attendance_requests WHERE request_type='overtime'") === '1');
  sql(`UPDATE attendances SET check_out_at = '${D} 19:30:00' WHERE employee_id='2025001' AND work_date='${D}'`);
  sql("UPDATE attendance_requests SET status='rejected', reviewer_id='2025002', reviewed_at=NOW() WHERE request_type='overtime'");
  await page.reload();
  check('却下されたら、また押せる・いつもの案内', !(await page.isDisabled('#overtime-open')) && await page.isVisible('#overtime-note') && !(await page.isVisible('#overtime-pending')));
  await page.context().close();

  console.log('[5] アカウント作成：部署とメールアドレスの両方が誤り → 部署が先（2-2）');
  const admin = await login('sysadmin', 'Admin1234');
  await admin.goto(BASE + 'admin/accounts.php');
  await admin.fill('#name', '確認 太郎'); await admin.fill('#email', 'not-mail');
  await admin.fill('#password', 'Abcd1234'); await admin.fill('#password_confirm', 'Abcd1234');
  await Promise.all([admin.waitForNavigation(), admin.click('text=アカウントを作成')]);
  const alerts = await admin.$$eval('.alert--error', els => els.filter(e => !e.hidden && e.offsetParent !== null).map(e => e.textContent.trim()));
  check('先に出るのは部署の誤り', alerts.length === 1 && alerts[0] === '部署を選んでください。', JSON.stringify(alerts));
  check('部署とメールアドレスの両方に誤りの印', (await admin.getAttribute('#department_id', 'aria-invalid')) === 'true' && (await admin.getAttribute('#email', 'aria-invalid')) === 'true');
  await admin.context().close();

  console.log('[6] 承認のときは、コメントの長さを確かめない（2-6）');
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at) VALUES ('2025001', DATE_SUB('${D}', INTERVAL 1 DAY), 'correction', CONCAT(DATE_SUB('${D}', INTERVAL 1 DAY), ' 09:00:00'))`);
  const id = sql("SELECT id FROM attendance_requests WHERE status='pending' AND request_type='correction'");
  const sato = await login('2025002', 'Test1234');
  await sato.goto(BASE + 'approvals/');
  const send = (decision, comment) => sato.evaluate(async ([id, decision, comment]) => {
    const fd = new FormData(); fd.append('csrf_token', document.getElementById('approvals').dataset.csrfToken);
    fd.append('request_id', id); fd.append('decision', decision); fd.append('comment', comment);
    return (await fetch('/nm_attendance/api/approvals.php', { method: 'POST', body: fd })).json();
  }, [id, decision, comment]);
  const long = 'あ'.repeat(501);
  r = await send('reject', long);
  check('却下は長いコメントで断られる', r.ok === false && r.errors.comment === 'コメントは 500 文字までにしてください。');
  r = await send('approve', long);
  check('承認は長いコメントでも通る（コメントは保存しない）', r.ok === true && sql(`SELECT CONCAT(status,'|',IFNULL(review_comment,'NULL')) FROM attendance_requests WHERE id=${id}`) === 'approved|NULL');
  await sato.context().close();

  await browser.close();
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  console.log('JS・500 のエラー: ' + (errors.length ? errors.join('\n') : 'なし'));
  console.log(`結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail || errors.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
