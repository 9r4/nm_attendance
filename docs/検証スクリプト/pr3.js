// PR 3 メイン画面の E2E 確認（Claude の環境用。コミットしない）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', 'attendance_training', '-e', q]).toString().trim();
const today = () => sql("SELECT CURDATE()"); // DB は JST（SET time_zone は接続ごと。CLI は system=JST? 下で確認）
let pass = 0, fail = 0;
function check(name, cond, detail = '') {
  if (cond) { pass++; console.log('  OK  ' + name); }
  else { fail++; console.log('  NG  ' + name + ' ' + detail); }
}
async function login(page, id, pw) {
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id);
  await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
async function state(page) {
  return page.evaluate(() => ({
    badge: document.getElementById('status-badge')?.textContent,
    in: document.getElementById('today-check-in').textContent,
    out: document.getElementById('today-check-out').textContent,
    brk: document.getElementById('today-break').textContent,
    btn: [...document.querySelectorAll('[data-action]')].map(b => b.dataset.action + ':' + (b.disabled ? 0 : 1)).join(','),
  }));
}
async function toastText(page) {
  await page.waitForSelector('#toast:not([hidden])');
  const t = await page.textContent('#toast');
  await page.evaluate(() => { document.getElementById('toast').hidden = true; });
  return t;
}
const row = (emp, d) => sql(`SELECT IFNULL(check_in_at,'-'), IFNULL(check_out_at,'-'), IFNULL(break_start_at,'-'), IFNULL(break_end_at,'-') FROM attendances WHERE employee_id='${emp}' AND work_date='${d}'`);
const hm = () => new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date());

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  sql('DELETE FROM attendances');
  // ほかの社員・ほかの日の行（変わらないことの確認用）
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at) VALUES ('2025002', '${D}', '${D} 08:30:00'), ('2025001', DATE_SUB('${D}', INTERVAL 1 DAY), '2000-01-01 09:00:00')`);
  const others0 = sql("SELECT employee_id, work_date, check_in_at, IFNULL(check_out_at,'-'), IFNULL(break_start_at,'-'), updated_at FROM attendances ORDER BY id");

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ja-JP', timezoneId: 'America/New_York' }); // パソコンの時間帯が違っても日本時間で出ること
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[014]/.test(m.text())) consoleErrors.push(m.text()); }); // CDN（外部）・API テストの 400/401 は想定どおり
  page.on('pageerror', e => consoleErrors.push(String(e)));
  page.on('response', r => { if (r.status() === 404 && !r.url().endsWith('/favicon.ico')) consoleErrors.push('404 ' + r.url()); });

  console.log('[1] 初期表示');
  await login(page, '2025001', 'Test1234');
  let s = await state(page);
  check('未出勤・出勤だけ押せる', s.badge === '未出勤' && s.in === '--:--' && s.out === '--:--' && s.brk === '--:--' && s.btn === 'check_in:1,check_out:0,break_start:0,break_end:0', JSON.stringify(s));
  const t1 = await page.textContent('#clock-time');
  await page.waitForTimeout(1500);
  const t2 = await page.textContent('#clock-time');
  const dateLabel = await page.textContent('#clock-date');
  check('時計が動く（秒）', /^\d\d:\d\d:\d\d$/.test(t2) && t1 !== t2, t1 + ' ' + t2);
  const hmNow = hm();
  check('時計は日本時間（パソコンは NY）', t2.slice(0, 5) === hmNow || t2.slice(0, 5) === new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(Date.now() - 60000)), t2 + ' vs ' + hmNow);
  check('日付の表示', /^\d{4}年\d{1,2}月\d{1,2}日（[日月火水木金土]）$/.test(dateLabel), dateLabel);
  await page.screenshot({ path: SHOT + 'pc_not_started.png' });

  console.log('[2] 出勤');
  await page.click('[data-action=check_in]');
  let msg = await toastText(page);
  let r = row('2025001', D).split('\t');
  check('通知', /^出勤を記録しました（\d\d:\d\d）$/.test(msg), msg);
  check('DB：分単位（秒は 00）', /:00$/.test(r[0]) && r[1] === '-', r.join(' '));
  s = await state(page);
  check('勤務中・出勤/退勤/休憩開始が押せる', s.badge === '勤務中' && s.in === r[0].slice(11, 16) && s.btn === 'check_in:1,check_out:1,break_start:1,break_end:0', JSON.stringify(s));
  check('通知の時刻＝記録', msg.includes(r[0].slice(11, 16)));

  console.log('[3] 出勤の上書き（キャンセル・上書き）');
  sql(`UPDATE attendances SET check_in_at='${D} 08:00:00' WHERE employee_id='2025001' AND work_date='${D}'`);
  await page.click('[data-action=check_in]');
  await page.waitForSelector('#confirm-dialog[open]');
  check('確認の文', (await page.textContent('#confirm-title')) === '出勤を上書きしますか？' && (await page.textContent('#confirm-text')).includes('すでに 08:00 で記録されています'), await page.textContent('#confirm-text'));
  s = await state(page);
  check('確認中に、画面が最新（08:00）になる', s.in === '08:00', s.in);
  await page.click('#confirm-dialog button[value=cancel]');
  await page.waitForTimeout(300);
  check('キャンセルで変わらない', row('2025001', D).startsWith(`${D} 08:00:00`) && !(await page.isVisible('#confirm-dialog')));
  await page.click('[data-action=check_in]');
  await page.waitForSelector('#confirm-dialog[open]');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  check('Esc でも変わらない', row('2025001', D).startsWith(`${D} 08:00:00`));
  await page.click('[data-action=check_in]');
  await page.waitForSelector('#confirm-dialog[open]');
  await page.click('#confirm-dialog button[value=ok]');
  msg = await toastText(page);
  r = row('2025001', D).split('\t');
  check('上書きされる', r[0] !== `${D} 08:00:00` && /:00$/.test(r[0]) && msg === `出勤を ${r[0].slice(11, 16)} に上書きしました`, msg + ' ' + r[0]);
  await page.screenshot({ path: SHOT + 'pc_working.png' });

  console.log('[4] 休憩');
  await page.click('[data-action=break_start]');
  msg = await toastText(page);
  s = await state(page);
  check('休憩中・休憩終了だけ押せる', /^休憩を開始しました（\d\d:\d\d）$/.test(msg) && s.badge === '休憩中' && /^\d\d:\d\d–休憩中$/.test(s.brk) && s.btn === 'check_in:0,check_out:0,break_start:0,break_end:1', msg + JSON.stringify(s));
  await page.screenshot({ path: SHOT + 'pc_on_break.png' });
  await page.click('[data-action=break_end]');
  msg = await toastText(page);
  s = await state(page);
  check('休憩終了後：休憩開始・出勤は押せない（D-042・D-059）', /^休憩を終了しました/.test(msg) && s.badge === '勤務中' && /^\d\d:\d\d–\d\d:\d\d$/.test(s.brk) && s.btn === 'check_in:0,check_out:1,break_start:0,break_end:0', JSON.stringify(s));

  console.log('[5] 退勤と上書き');
  await page.click('[data-action=check_out]');
  msg = await toastText(page);
  s = await state(page);
  check('退勤済み・退勤だけ押せる', /^退勤を記録しました（\d\d:\d\d）$/.test(msg) && s.badge === '退勤済み' && s.btn === 'check_in:0,check_out:1,break_start:0,break_end:0', JSON.stringify(s));
  sql(`UPDATE attendances SET check_out_at='${D} 17:00:00' WHERE employee_id='2025001' AND work_date='${D}'`);
  await page.click('[data-action=check_out]');
  await page.waitForSelector('#confirm-dialog[open]');
  check('退勤の確認の文', (await page.textContent('#confirm-title')) === '退勤を上書きしますか？' && (await page.textContent('#confirm-text')).includes('17:00'));
  await page.click('#confirm-dialog button[value=ok]');
  msg = await toastText(page);
  r = row('2025001', D).split('\t');
  check('退勤が上書きされる', msg === `退勤を ${r[1].slice(11, 16)} に上書きしました` && r[1] !== `${D} 17:00:00`, msg + r[1]);
  await page.screenshot({ path: SHOT + 'pc_finished.png' });

  console.log('[6] 読み込み直し');
  const before = await state(page);
  await page.reload();
  check('読み込み直しても同じ', JSON.stringify(await state(page)) === JSON.stringify(before));

  console.log('[7] API を直接');
  const api = (body) => page.evaluate(async (b) => {
    const fd = new FormData(); for (const [k, v] of Object.entries(b)) fd.append(k, v === '@token' ? document.getElementById('attendance').dataset.csrfToken : v);
    const res = await fetch('api/attendance.php', { method: 'POST', body: fd });
    return { status: res.status, text: await res.text() };
  }, body);
  const snap = row('2025001', D);
  let a = await api({ csrf_token: 'bad', action: 'check_out', overwrite: '1' });
  check('CSRF 違い → 400', a.status === 400 && row('2025001', D) === snap, JSON.stringify(a));
  a = await api({ action: 'check_out', overwrite: '1' });
  check('CSRF なし → 400', a.status === 400);
  a = await api({ csrf_token: '@token', action: 'delete' });
  check('知らない操作 → 400', a.status === 400);
  a = await api({ csrf_token: '@token' });
  check('操作なし → 400', a.status === 400);
  a = await api({ csrf_token: '@token', action: 'break_start' });
  let j = JSON.parse(a.text);
  check('押せない操作 → unavailable・変わらない', a.status === 200 && j.result === 'unavailable' && row('2025001', D) === snap, a.text);
  a = await api({ csrf_token: '@token', action: 'check_in', overwrite: '1' });
  check('退勤後の出勤（上書き指定でも）→ unavailable', JSON.parse(a.text).result === 'unavailable' && row('2025001', D) === snap);
  a = await api({ csrf_token: '@token', action: 'check_out' });
  check('上書き指定なし → confirm・変わらない', JSON.parse(a.text).result === 'confirm' && row('2025001', D) === snap);

  console.log('[8] 同時に出勤（画面を2つ）');
  let okRace = true;
  const seen = new Set();
  for (let i = 0; i < 20; i++) {
    sql(`DELETE FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`);
    const res = await page.evaluate(async () => {
      const send = () => { const fd = new FormData(); fd.append('csrf_token', document.getElementById('attendance').dataset.csrfToken); fd.append('action', 'check_in'); return fetch('api/attendance.php', { method: 'POST', body: fd }).then(async r => ({ s: r.status, j: await r.json() })); };
      return Promise.all([send(), send(), send()]);
    });
    const results = res.map(x => x.s + ':' + x.j.result).sort().join(',');
    const n = sql(`SELECT COUNT(*) FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`);
    if (n !== '1' || res.some(x => x.s !== 200) || res.filter(x => x.j.result === 'recorded').length !== 1) okRace = false;
    res.forEach(x => seen.add(x.j.result));
  }
  console.log('     結果の種類: ' + [...seen].join(','));
  check('行は1つ・エラーなし・記録は1回', okRace);

  console.log('[9] ほかの画面で記録済み → 確認が出る');
  sql(`DELETE FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`);
  await page.reload();
  const page2 = await ctx.newPage();
  await page2.goto(BASE);
  await page2.click('[data-action=check_in]');
  await toastText(page2);
  await page.click('[data-action=check_in]'); // 古い表示（未出勤）のまま押す
  await page.waitForSelector('#confirm-dialog[open]');
  check('古い画面から押すと、上書き確認', (await page.textContent('#confirm-title')) === '出勤を上書きしますか？');
  await page.click('#confirm-dialog button[value=cancel]');
  await page2.close();

  console.log('[10] ログインが切れたとき');
  await ctx.clearCookies();
  await Promise.all([page.waitForURL(/login\.php/), page.click('[data-action=check_in]')]);
  check('ログイン画面へ移る', page.url().endsWith('login.php'));

  console.log('[11] 日付が変わったら読み込み直す');
  await login(page, '2025001', 'Test1234');
  let loads = 0;
  page.on('load', () => loads++);
  await page.route(BASE, async (route) => {
    const res = await route.fetch();
    let body = await res.text();
    const jst2359 = new Date(D + 'T23:59:58+09:00').getTime();
    if (loads === 0) body = body.replace(/data-server-time="\d+"/, `data-server-time="${jst2359}"`);
    await route.fulfill({ response: res, body });
  });
  await page.goto(BASE);
  const c1 = await page.textContent('#clock-time');
  await page.waitForTimeout(4500);
  check('23:59:58 から日付が変わると1回だけ読み込み直す', c1 === '23:59:58' && loads === 2, `c1=${c1} loads=${loads}`);
  await page.unroute(BASE);

  console.log('[12] スマホの幅');
  const mctx = await browser.newContext({ viewport: { width: 375, height: 812 }, locale: 'ja-JP', isMobile: true, hasTouch: true });
  const m = await mctx.newPage();
  await login(m, '2025001', 'Test1234');
  const overflow = await m.evaluate(() => document.documentElement.scrollWidth > 375);
  check('横にはみ出さない', !overflow);
  await m.screenshot({ path: SHOT + 'sp_main.png', fullPage: true });
  await m.click('[data-action=check_in]');
  await m.waitForSelector('#confirm-dialog[open]');
  await m.screenshot({ path: SHOT + 'sp_confirm.png' });
  await m.click('#confirm-dialog button[value=cancel]');

  console.log('[13] システム管理者');
  const sctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const sp = await sctx.newPage();
  await login(sp, 'sysadmin', 'Admin1234');
  // PR 7（D-067）から、案内の代わりに管理の画面へのボタンを出す
  check('ボタン・状態バッジなし、管理の画面へのボタンあり', (await sp.$$('[data-action]')).length === 0 && !(await sp.$('#status-badge')) && (await sp.$$('.admin-links__item')).length === 3);
  const t3 = await sp.textContent('#clock-time'); await sp.waitForTimeout(1200);
  check('時計は動く', t3 !== await sp.textContent('#clock-time'));
  const sa = await sp.evaluate(async () => {
    const token = document.querySelector('input[name=csrf_token]').value;
    const fd = new FormData(); fd.append('csrf_token', token); fd.append('action', 'check_in');
    const r = await fetch('api/attendance.php', { method: 'POST', body: fd }); return r.status;
  });
  check('API は 403', sa === 403);
  await sp.screenshot({ path: SHOT + 'pc_sysadmin.png' });

  console.log('[14] ほかの社員・ほかの日の行');
  check('変わっていない', sql(`SELECT employee_id, work_date, check_in_at, IFNULL(check_out_at,'-'), IFNULL(break_start_at,'-'), updated_at FROM attendances WHERE NOT (employee_id='2025001' AND work_date='${D}') ORDER BY id`) === others0, '');
  check('ブラウザのエラーなし', consoleErrors.length === 0, consoleErrors.join(' | '));

  await browser.close();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
