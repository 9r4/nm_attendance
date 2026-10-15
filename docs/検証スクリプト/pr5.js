// PR 5 申請の E2E 確認（Claude の環境用。コミットしない）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/pr5_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
async function login(page, id, pw) {
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
const cell = (page, date) => page.locator(`[data-date="${date}"]`);
const visible = (page, sel) => page.isVisible(sel);
async function formValues(page) {
  return page.evaluate(() => Object.fromEntries(['target_date', 'check_in', 'check_out', 'break_start', 'break_end', 'reason'].map(n => [n, document.getElementById('request-form').elements[n].value])));
}
async function errorsShown(page) {
  return page.evaluate(() => Object.fromEntries([...document.querySelectorAll('#request-dialog [id^=request-error-]')].filter(e => !e.hidden).map(e => [e.id.replace('request-error-', ''), e.textContent])));
}
async function submitAndWaitReload(page) {
  await Promise.all([page.waitForNavigation(), page.click('#request-submit')]);
}

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const M = D.slice(0, 7);
  if (+D.slice(8) < 6) throw new Error('このテストは、月の6日以降に実行する');
  const A = `${M}-01`, B = `${M}-02`, C = `${M}-03`, E = `${M}-04`;
  const future = new Date(Date.parse(D + 'T00:00:00Z') + 86400000).toISOString().slice(0, 10);
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
    ('2025001','${A}','${A} 09:00:00','${A} 18:00:00','${A} 15:00:00','${A} 15:10:00'),
    ('2025001','${D}','${D} 09:00:00','${D} 19:30:00',NULL,NULL)`);
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at) VALUES ('2025002','${A}','correction','${A} 07:00:00')`);
  const attBefore = sql('SELECT * FROM attendances ORDER BY id');

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ja-JP' });
  const page = await ctx.newPage();
  const errors = [];
  let alerted = false;
  page.on('dialog', d => { alerted = true; d.dismiss(); });
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[01345]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 500 || (r.status() === 404 && !r.url().endsWith('favicon.ico'))) errors.push(r.status() + ' ' + r.url()); });

  await login(page, '2025001', 'Test1234');
  await page.goto(BASE + 'records/');

  console.log('[1] 記録がある日の修正');
  await cell(page, A).click(); await page.waitForSelector('#day-dialog[open]');
  check('「修正」ボタン', (await visible(page, '#day-request')) && (await page.textContent('#day-request')) === '修正');
  check('ほかの社員の申請は出ない', !(await visible(page, '#day-requests')));
  await page.click('#day-request'); await page.waitForSelector('#request-dialog[open]');
  let v = await formValues(page);
  check('今の値が入る・対象日は固定', v.target_date === A && v.check_in === '09:00' && v.check_out === '18:00' && v.break_start === '15:00' && v.break_end === '15:10' && await page.$eval('#request-date', e => e.readOnly), JSON.stringify(v));
  await page.screenshot({ path: SHOT + 'pc_form.png' });
  await page.fill('#request-check_out', '08:00');
  await page.click('#request-submit'); await page.waitForSelector('#request-error-check_out:not([hidden])');
  let e = await errorsShown(page); v = await formValues(page);
  check('退勤が休憩終了より前：誤りを表示・値は残る', e.check_out === '退勤時刻は、休憩終了時刻より後にしてください。' && v.check_out === '08:00' && v.check_in === '09:00' && (await page.getAttribute('#request-check_out', 'aria-invalid')) === 'true', JSON.stringify(e));
  await page.screenshot({ path: SHOT + 'pc_form_error.png' });
  await page.fill('#request-check_out', '18:00'); await page.fill('#request-break_end', '');
  await page.click('#request-submit'); await page.waitForSelector('#request-error-break_end:not([hidden])');
  e = await errorsShown(page);
  check('休憩の片方だけ', e.break_end === '休憩は、開始と終了の両方を入力してください。' && !e.check_out, JSON.stringify(e));
  await page.fill('#request-break_end', '15:10');
  await page.click('#request-submit'); await page.waitForSelector('#request-error-form:not([hidden])');
  check('変更なし', (await page.textContent('#request-error-form')) === '今の記録から変更がありません。');
  await page.fill('#request-check_out', '19:00'); await page.fill('#request-break_start', ''); await page.fill('#request-break_end', '');
  await page.fill('#request-reason', '<script>alert(1)</script>打刻漏れ');
  await submitAndWaitReload(page);
  check('受け付けの通知', (await page.textContent('#toast')) === '申請を受け付けました。承認されると勤怠に反映されます。' && await visible(page, '#toast'));
  check('バッジ「申請中」', (await cell(page, A).innerText()).includes('申請中'));
  let r = sql(`SELECT request_type, requested_check_in_at, requested_check_out_at, IFNULL(requested_break_start_at,'NULL'), IFNULL(requested_break_end_at,'NULL'), original_check_in_at, original_check_out_at, original_break_start_at, original_break_end_at, reason, status, IFNULL(requested_overtime_end_at,'NULL') FROM attendance_requests WHERE employee_id='2025001' AND target_date='${A}'`).split('\t');
  check('DB：4つの時刻の全体と控え', r[0] === 'correction' && r[1] === `${A} 09:00:00` && r[2] === `${A} 19:00:00` && r[3] === 'NULL' && r[4] === 'NULL' && r[5] === `${A} 09:00:00` && r[6] === `${A} 18:00:00` && r[7] === `${A} 15:00:00` && r[8] === `${A} 15:10:00` && r[9] === '<script>alert(1)</script>打刻漏れ' && r[10] === 'pending' && r[11] === 'NULL', r.join(' | '));
  check('勤怠は変わらない', sql('SELECT * FROM attendances ORDER BY id') === attBefore);
  await page.reload();
  check('読み込み直すと通知は消える（1回だけ）', !(await visible(page, '#toast')));
  await cell(page, A).click(); await page.waitForSelector('#day-dialog[open]');
  const items = await page.$$eval('#day-request-items .request-item', els => els.map(el => el.innerText.replace(/\s+/g, ' ')));
  check('申請の一覧：変わった項目だけ', items.length === 1 && items[0].includes('修正') && items[0].includes('申請中') && items[0].includes('休憩開始 15:00 → なし') && items[0].includes('休憩終了 15:10 → なし') && items[0].includes('退勤 18:00 → 19:00') && !items[0].includes('出勤') && items[0].includes('理由：<script>alert(1)</script>打刻漏れ'), JSON.stringify(items));
  check('申請中：修正ボタンなし・案内あり', !(await visible(page, '#day-request')) && await visible(page, '#day-pending'));
  check('理由の <script> が実行されない', !alerted);
  await page.screenshot({ path: SHOT + 'pc_day_requests.png' });
  await page.keyboard.press('Escape');

  console.log('[2] 記録が無い日の新規登録');
  await cell(page, B).click(); await page.waitForSelector('#request-dialog[open]');
  v = await formValues(page);
  check('空のウィンドウがすぐ開く', v.target_date === B && v.check_in === '' && v.check_out === '' && !(await visible(page, '#day-dialog')));
  check('日付を表示', /^\d+月2日（.）/.test(await page.textContent('#request-day')));
  await page.fill('#request-check_out', '18:00');
  await page.click('#request-submit'); await page.waitForSelector('#request-error-check_in:not([hidden])');
  check('出勤は必須', (await page.textContent('#request-error-check_in')) === '出勤時刻を入力してください。');
  await page.fill('#request-check_in', '09:00');
  await submitAndWaitReload(page);
  await cell(page, B).click(); await page.waitForSelector('#day-dialog[open]');
  const itemB = await page.$eval('#day-request-items .request-item', el => el.innerText.replace(/\s+/g, ' '));
  check('新規登録：なし → 申請値', itemB.includes('新規登録') && itemB.includes('出勤 なし → 09:00') && itemB.includes('退勤 なし → 18:00') && await visible(page, '#day-empty'), itemB);
  check('理由なしは NULL', sql(`SELECT reason IS NULL FROM attendance_requests WHERE employee_id='2025001' AND target_date='${B}'`) === '1');
  await page.keyboard.press('Escape');

  console.log('[3] 「申請」ボタン');
  await page.click('#request-open'); await page.waitForSelector('#request-dialog[open]');
  check('「申請」ボタンでは日付の表示なし', !(await visible(page, '#request-day')));
  check('対象日を選べる（表示中の月・今日まで）', !(await page.$eval('#request-date', el => el.readOnly)) && (await page.getAttribute('#request-date', 'min')) === `${M}-01` && (await page.getAttribute('#request-date', 'max')) === D && (await formValues(page)).target_date === '');
  await page.fill('#request-date', A); await page.dispatchEvent('#request-date', 'change');
  check('申請中の日を選ぶと、申請できない', (await page.textContent('#request-error-form')).includes('申請中') && await page.$eval('#request-submit', b => b.disabled));
  await page.fill('#request-date', C); await page.dispatchEvent('#request-date', 'change');
  v = await formValues(page);
  check('別の日を選ぶと、その日の値に入れ替わる', v.check_in === '' && !(await page.$eval('#request-submit', b => b.disabled)) && !(await visible(page, '#request-error-form')));
  await page.fill('#request-check_in', '10:00');
  await submitAndWaitReload(page);
  check('退勤なしでも申請できる', sql(`SELECT COUNT(*) FROM attendance_requests WHERE target_date='${C}' AND requested_check_out_at IS NULL`) === '1');

  console.log('[4] 未来の日・今日');
  await cell(page, future).click().catch(() => {});
  if (await cell(page, future).count()) {
    await page.waitForSelector('#day-dialog[open]');
    check('未来の日：申請ボタンなし', !(await visible(page, '#day-request')));
    await page.keyboard.press('Escape');
  }
  await cell(page, D).click(); await page.waitForSelector('#day-dialog[open]');
  check('今日：修正できる', (await page.textContent('#day-request')) === '修正' && await visible(page, '#day-request'));
  await page.click('#day-request'); await page.waitForSelector('#request-dialog[open]');
  await page.fill('#request-check_out', '23:59');
  await page.click('#request-submit'); await page.waitForSelector('#request-error-check_out:not([hidden])');
  check('今日のまだ来ていない時刻', (await page.textContent('#request-error-check_out')) === 'まだ来ていない時刻は申請できません。');
  await page.click('#request-cancel');

  console.log('[5] API を直接');
  const api = (body) => page.evaluate(async (b) => {
    const fd = new FormData(); for (const [k, v] of Object.entries(b)) fd.append(k, v === '@token' ? document.getElementById('calendar').dataset.csrfToken : v);
    const res = await fetch('../api/requests.php', { method: 'POST', body: fd });
    return { status: res.status, json: await res.json().catch(() => null) };
  }, body);
  const count0 = sql('SELECT COUNT(*) FROM attendance_requests');
  let a = await api({ csrf_token: '@token', type: 'correction', target_date: future, check_in: '09:00', check_out: '' });
  check('未来の日 → 誤り', a.json.ok === false && a.json.errors.target_date !== undefined);
  a = await api({ csrf_token: '@token', type: 'correction', target_date: '2025-03-31', check_in: '09:00', check_out: '' });
  check('入社日より前 → 誤り', a.json.ok === false && a.json.errors.target_date !== undefined);
  a = await api({ csrf_token: '@token', type: 'correction', target_date: A, check_in: '08:00', check_out: '18:00' });
  check('申請中の日（BR-002）→ 誤り', a.json.ok === false && a.json.errors.form === 'この日には、申請中の申請があります。新しく申請する場合は、申請中の申請を取り消してください（承認・却下されるまでは、新しい申請はできません）。', JSON.stringify(a.json));
  a = await api({ csrf_token: 'bad', type: 'correction', target_date: E, check_in: '09:00', check_out: '' });
  check('CSRF 違い → 400', a.status === 400);
  a = await api({ csrf_token: '@token', type: 'delete' });
  check('知らない種別 → 400', a.status === 400);
  check('ここまで保存されていない', sql('SELECT COUNT(*) FROM attendance_requests') === count0);
  const both = await page.evaluate(async (date) => {
    const send = () => { const fd = new FormData(); fd.append('csrf_token', document.getElementById('calendar').dataset.csrfToken); fd.append('type', 'correction'); fd.append('target_date', date); fd.append('check_in', '09:00'); fd.append('check_out', '17:00'); return fetch('../api/requests.php', { method: 'POST', body: fd }).then(r => r.json()); };
    return Promise.all([send(), send(), send()]);
  }, E);
  check('同時に3回送っても申請は1件', both.filter(x => x.ok).length === 1 && both.filter(x => !x.ok && x.errors.form.includes('申請中')).length === 2 && sql(`SELECT COUNT(*) FROM attendance_requests WHERE target_date='${E}'`) === '1', JSON.stringify(both));
  const get = await page.evaluate(async () => (await fetch('../api/requests.php')).status);
  check('GET → 405', get === 405);

  console.log('[6] メイン画面の簡単残業申請');
  await page.goto(BASE);
  check('退勤 19:30：ボタンを押せる', !(await page.$eval('#overtime-open', b => b.disabled)));
  await page.click('#overtime-open'); await page.waitForSelector('#overtime-dialog[open]');
  check('標準終了時刻・退勤時刻・初期値', (await page.textContent('#overtime-standard-end')) === '18:00' && (await page.textContent('#overtime-check-out')) === '19:30' && (await page.inputValue('#overtime-end')) === '19:30');
  await page.screenshot({ path: SHOT + 'pc_overtime.png' });
  for (const [val, msg] of [['18:00', '標準終了時刻（18:00）より後の時刻を指定してください。'], ['19:31', '退勤時刻（19:30）を超える時刻は指定できません。'], ['', '残業の終了時刻を入力してください。']]) {
    await page.fill('#overtime-end', val);
    await page.click('#overtime-submit');
    const shown = await page.waitForFunction((m) => document.getElementById('overtime-error-overtime_end').textContent === m, msg, { timeout: 3000 }).then(() => true).catch(() => false);
    check(`残業の終了時刻「${val}」→ 誤り`, shown, await page.textContent('#overtime-error-overtime_end'));
  }
  await page.fill('#overtime-end', '19:00');
  await page.click('#overtime-submit'); await page.waitForSelector('#toast:not([hidden])');
  check('受け付け', (await page.textContent('#toast')) === '残業申請を受け付けました（〜19:00・申請中）' && !(await visible(page, '#overtime-dialog')));
  r = sql(`SELECT request_type, requested_overtime_end_at, IFNULL(original_overtime_end_at,'NULL'), requested_check_in_at IS NULL, reason IS NULL FROM attendance_requests WHERE employee_id='2025001' AND target_date='${D}'`).split('\t');
  check('DB：残業の申請', r.join('|') === `overtime|${D} 19:00:00|NULL|1|1`, r.join('|'));
  await page.click('#overtime-open'); await page.fill('#overtime-end', '19:10');
  await page.click('#overtime-submit'); await page.waitForSelector('#overtime-error-form:not([hidden])');
  check('2回目（BR-002）', (await page.textContent('#overtime-error-form')) === '本日分の残業申請は、すでに申請中です。');
  await page.click('#overtime-cancel');
  sql(`UPDATE attendances SET overtime_end_at='${D} 18:30:00', check_out_at='${D} 17:00:00' WHERE employee_id='2025001' AND work_date='${D}'`);
  await page.reload();
  check('退勤 17:00：押せない', await page.$eval('#overtime-open', b => b.disabled));
  sql(`UPDATE attendances SET check_out_at=NULL WHERE employee_id='2025001' AND work_date='${D}'`);
  await page.reload();
  check('退勤前：押せない', await page.$eval('#overtime-open', b => b.disabled));
  const ov = await page.evaluate(async () => { const fd = new FormData(); fd.append('csrf_token', document.getElementById('attendance').dataset.csrfToken); fd.append('type', 'overtime'); fd.append('overtime_end', '19:00'); return (await fetch('api/requests.php', { method: 'POST', body: fd })).json(); });
  check('退勤前に API で送っても受け付けない', ov.ok === false && ov.errors.form !== undefined);
  sql(`UPDATE attendances SET check_out_at='${D} 19:30:00' WHERE employee_id='2025001' AND work_date='${D}'`);
  sql(`DELETE FROM attendance_requests WHERE request_type='overtime'`);
  await page.reload(); await page.click('#overtime-open'); await page.fill('#overtime-end', '19:15');
  await page.click('#overtime-submit'); await page.waitForSelector('#toast:not([hidden])');
  check('控え：承認済みの残業終了時刻', sql(`SELECT original_overtime_end_at FROM attendance_requests WHERE request_type='overtime'`) === `${D} 18:30:00`);

  console.log('[7] 記録表示：今日の残業申請');
  await page.goto(BASE + 'records/');
  check('今日のバッジ', (await cell(page, D).innerText()).includes('申請中'));
  await cell(page, D).click(); await page.waitForSelector('#day-dialog[open]');
  const itemD = await page.$eval('#day-request-items .request-item', el => el.innerText.replace(/\s+/g, ' '));
  check('残業：残業終了 18:30 → 19:15・修正はできる', itemD.includes('残業') && itemD.includes('残業終了 18:30 → 19:15') && await visible(page, '#day-request') && !(await visible(page, '#day-pending')), itemD);
  await page.keyboard.press('Escape');

  console.log('[8] スマホの幅');
  const mctx = await browser.newContext({ viewport: { width: 375, height: 812 }, locale: 'ja-JP', isMobile: true, hasTouch: true });
  const m = await mctx.newPage();
  await login(m, '2025001', 'Test1234');
  await m.goto(BASE + 'records/');
  check('横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  await m.screenshot({ path: SHOT + 'sp_records.png', fullPage: true });
  await cell(m, A).click(); await m.waitForSelector('#day-dialog[open]');
  await m.screenshot({ path: SHOT + 'sp_day.png' });
  await m.keyboard.press('Escape');
  await m.click('#request-open'); await m.waitForSelector('#request-dialog[open]');
  check('申請ウィンドウが画面に収まる', await m.evaluate(() => { const r = document.querySelector('#request-dialog').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; }));
  await m.screenshot({ path: SHOT + 'sp_form.png' });
  await m.goto(BASE);
  check('メイン画面：横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  await m.screenshot({ path: SHOT + 'sp_main.png', fullPage: true });
  const header = await m.evaluate(() => { const r = document.querySelector('.app-header form').getBoundingClientRect(); return r.right; });
  check('メイン画面：ヘッダーのログアウトが画面に収まる', header <= 375, String(header));

  console.log('[9] 権限');
  const sctx = await browser.newContext(); const sp = await sctx.newPage();
  await login(sp, 'sysadmin', 'Admin1234');
  const sa = await sp.evaluate(async () => { const fd = new FormData(); fd.append('csrf_token', document.querySelector('input[name=csrf_token]').value); fd.append('type', 'correction'); return (await fetch('api/requests.php', { method: 'POST', body: fd })).status; });
  check('システム管理者 → 403', sa === 403);
  check('システム管理者のメイン画面に残業申請なし', (await sp.$$('#overtime-open')).length === 0);
  const nctx = await browser.newContext(); const np = await nctx.newPage();
  const un = await np.request.post(BASE + 'api/requests.php', { form: { type: 'correction' } });
  check('未ログイン → 401', un.status() === 401);

  check('ブラウザのエラーなし', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
