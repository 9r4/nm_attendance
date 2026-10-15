// 申請中の打刻の制限と申請の取り消し（D-063）の E2E 確認（Claude の環境用。コミットしない）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/fix_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
async function login(page, id, pw) {
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
const buttons = (page) => page.$$eval('[data-action]', bs => bs.map(b => b.dataset.action + ':' + (b.disabled ? 0 : 1)).join(','));
const visible = (page, sel) => page.isVisible(sel);

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const M = D.slice(0, 7);
  const P = `${M}-01`;
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at) VALUES ('2025001','${D}','${D} 09:00:00'), ('2025001','${P}','${P} 09:00:00')`);

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ja-JP' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[134]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
  await login(page, '2025001', 'Test1234');

  console.log('[1] 申請が無いとき');
  check('打刻できる・案内なし', (await buttons(page)) === 'check_in:1,check_out:1,break_start:1,break_end:0' && !(await visible(page, '#punch-lock')));

  console.log('[2] 過去の日の修正の申請中・今日の残業の申請中は、止めない');
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at, original_check_in_at) VALUES ('2025001','${P}','correction','${P} 08:30:00','${P} 09:00:00')`);
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_overtime_end_at) VALUES ('2025001','${D}','overtime','${D} 19:00:00')`);
  await page.reload();
  check('打刻できる', (await buttons(page)) === 'check_in:1,check_out:1,break_start:1,break_end:0' && !(await visible(page, '#punch-lock')));

  console.log('[3] 今日の修正の申請中は、止める');
  await page.goto(BASE + 'records/');
  await page.locator(`[data-date="${D}"]`).click(); await page.waitForSelector('#day-dialog[open]');
  await page.click('#day-request'); await page.waitForSelector('#request-dialog[open]');
  await page.fill('#request-check_in', '08:45');
  await Promise.all([page.waitForNavigation(), page.click('#request-submit')]);
  await page.goto(BASE);
  check('ボタンはすべて押せない・案内あり', (await buttons(page)) === 'check_in:0,check_out:0,break_start:0,break_end:0' && await visible(page, '#punch-lock') && (await page.textContent('#punch-lock')).includes('本日の修正申請が申請中のため、打刻できません。'));
  check('案内に記録表示へのリンク', (await page.getAttribute('#punch-lock a', 'href')) === '/nm_attendance/records/');
  await page.screenshot({ path: SHOT + 'main_locked.png' });
  const api = await page.evaluate(async () => {
    const fd = new FormData(); fd.append('csrf_token', document.getElementById('attendance').dataset.csrfToken); fd.append('action', 'check_out');
    return (await fetch('api/attendance.php', { method: 'POST', body: fd })).json();
  });
  check('API で退勤を送っても記録しない', api.result === 'unavailable' && api.view.locked === true && sql(`SELECT IFNULL(check_out_at,'NULL') FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`) === 'NULL', JSON.stringify(api));

  console.log('[4] 開いていた画面から押した場合');
  const page2 = await ctx.newPage();
  sql(`DELETE FROM attendance_requests WHERE request_type='correction' AND target_date='${D}'`);
  await page2.goto(BASE);
  check('（取り消し後に開いた画面は打刻できる）', (await buttons(page2)) !== 'check_in:0,check_out:0,break_start:0,break_end:0');
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at) VALUES ('2025001','${D}','correction','${D} 08:45:00')`);
  await page2.click('[data-action=break_start]');
  await page2.waitForSelector('#toast:not([hidden])');
  check('押すと止められ、表示が「打刻できない」に変わる', (await page2.textContent('#toast')).includes('打刻できません') && (await buttons(page2)) === 'check_in:0,check_out:0,break_start:0,break_end:0' && await visible(page2, '#punch-lock') && sql(`SELECT IFNULL(break_start_at,'NULL') FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`) === 'NULL');
  await page2.close();

  console.log('[5] 取り消し');
  await page.goto(BASE + 'records/');
  await page.locator(`[data-date="${D}"]`).click(); await page.waitForSelector('#day-dialog[open]');
  const cancelButtons = await page.$$eval('#day-request-items .request-item__cancel', bs => bs.length);
  check('申請中の2件（修正・残業）に「取り消す」', cancelButtons === 2, String(cancelButtons));
  await page.screenshot({ path: SHOT + 'day_cancel.png' });
  const correctionItem = page.locator('#day-request-items .request-item', { hasNotText: '残業' });
  await correctionItem.locator('.request-item__cancel').click();
  await page.waitForSelector('#cancel-dialog[open]');
  check('確認のウィンドウ', (await page.textContent('#cancel-text')).includes('新規登録の申請を取り消します'));
  await page.click('#cancel-back'); await page.waitForTimeout(150);
  check('戻ると取り消さない', !(await visible(page, '#cancel-dialog')) && sql(`SELECT COUNT(*) FROM attendance_requests WHERE request_type='correction' AND target_date='${D}'`) === '1');
  await page.locator(`[data-date="${D}"]`).click(); await page.waitForSelector('#day-dialog[open]');
  await page.locator('#day-request-items .request-item', { hasNotText: '残業' }).locator('.request-item__cancel').click();
  await page.waitForSelector('#cancel-dialog[open]');
  await page.screenshot({ path: SHOT + 'cancel_dialog.png' });
  await Promise.all([page.waitForNavigation(), page.click('#cancel-submit')]);
  check('取り消しの通知・申請は削除', (await page.textContent('#toast')) === '申請を取り消しました。' && sql(`SELECT COUNT(*) FROM attendance_requests WHERE request_type='correction' AND target_date='${D}'`) === '0');
  check('残業の申請は残る', sql(`SELECT COUNT(*) FROM attendance_requests WHERE request_type='overtime'`) === '1');
  await page.goto(BASE);
  check('取り消すと打刻できる', (await buttons(page)) === 'check_in:1,check_out:1,break_start:1,break_end:0' && !(await visible(page, '#punch-lock')));

  console.log('[6] 取り消せないもの（API を直接）');
  await page.goto(BASE + 'records/');
  const cancel = (id) => page.evaluate(async (rid) => {
    const fd = new FormData(); fd.append('csrf_token', document.getElementById('calendar').dataset.csrfToken); fd.append('type', 'cancel'); fd.append('request_id', rid);
    return (await fetch('../api/requests.php', { method: 'POST', body: fd })).json();
  }, String(id));
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at) VALUES ('2025002','${P}','correction','${P} 09:00:00')`);
  const others = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025002'`);
  let r = await cancel(others);
  check('ほかの人の申請は取り消せない', r.ok === false && sql(`SELECT COUNT(*) FROM attendance_requests WHERE employee_id='2025002'`) === '1', JSON.stringify(r));
  sql(`UPDATE attendance_requests SET status='approved', reviewer_id='2025002', reviewed_at=NOW() WHERE employee_id='2025001' AND target_date='${P}'`);
  const approved = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025001' AND target_date='${P}'`);
  r = await cancel(approved);
  check('承認済みは取り消せない', r.ok === false && sql(`SELECT status FROM attendance_requests WHERE id=${approved}`) === 'approved');
  r = await cancel('abc');
  check('正しくない ID', r.ok === false);
  r = await cancel('99999999');
  check('存在しない ID', r.ok === false);
  await page.reload();
  await page.locator(`[data-date="${P}"]`).click(); await page.waitForSelector('#day-dialog[open]');
  check('承認済みには「取り消す」が出ない', (await page.$$eval('#day-request-items .request-item__cancel', bs => bs.length)) === 0);
  await page.keyboard.press('Escape');

  console.log('[7] スマホの幅');
  const m = await (await browser.newContext({ viewport: { width: 375, height: 812 }, locale: 'ja-JP', isMobile: true, hasTouch: true })).newPage();
  await login(m, '2025001', 'Test1234');
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at) VALUES ('2025001','${D}','correction','${D} 08:45:00')`);
  await m.reload();
  check('はみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  await m.screenshot({ path: SHOT + 'sp_main_locked.png', fullPage: true });

  check('ブラウザのエラーなし', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
