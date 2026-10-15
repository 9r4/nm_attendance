// PR 4 記録表示の E2E 確認（Claude の環境用。コミットしない）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/pr4_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
async function login(page, id, pw) {
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}
const cell = (page, date) => page.locator(`[data-date="${date}"]`);
async function detail(page, date) {
  await cell(page, date).click();
  await page.waitForSelector('#day-dialog[open]');
  const r = await page.evaluate(() => ({
    title: document.getElementById('day-title').textContent,
    holiday: document.getElementById('day-holiday').hidden ? '' : document.getElementById('day-holiday').textContent,
    empty: !document.getElementById('day-empty').hidden, detailShown: !document.getElementById('day-detail').hidden,
    in: document.getElementById('day-check-in').textContent, out: document.getElementById('day-check-out').textContent,
    brk: document.getElementById('day-break').textContent, work: document.getElementById('day-work-time').textContent,
  }));
  await page.click('#day-dialog button[value=close]');
  await page.waitForTimeout(150);
  return r;
}

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const M = D.slice(0, 7);
  const prevM = new Date(Date.UTC(+M.slice(0, 4), +M.slice(5, 7) - 2, 1)).toISOString().slice(0, 7);
  const prevLast = new Date(Date.UTC(+M.slice(0, 4), +M.slice(5, 7) - 1, 0)).toISOString().slice(0, 10);
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
    ('2025001','${M}-01','${M}-01 09:00:00','${M}-01 18:00:00','${M}-01 15:00:00','${M}-01 15:15:00'),
    ('2025001','${M}-02','${M}-02 09:00:00','${M}-02 18:00:00',NULL,NULL),
    ('2025001','${D}','${D} 09:00:00',NULL,NULL,NULL),
    ('2025001','${prevLast}','${prevLast} 08:00:00','${prevLast} 20:00:00',NULL,NULL),
    ('2025002','${M}-02','${M}-02 07:00:00','${M}-02 23:00:00',NULL,NULL)`);

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, locale: 'ja-JP' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[34]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 400 && !r.url().endsWith('favicon.ico') && r.status() !== 403) errors.push(r.status() + ' ' + r.url()); });

  console.log('[1] メニュー');
  await login(page, '2025001', 'Test1234');
  await page.click('#menu-open');
  await page.waitForSelector('#menu[open]');
  let links = await page.$$eval('#menu .menu__link', as => as.map(a => a.textContent + (a.getAttribute('aria-current') ? '*' : '')));
  check('社員：メイン画面（今いる画面）・記録表示', JSON.stringify(links) === JSON.stringify(['メイン画面*', '記録表示']), JSON.stringify(links));
  check('開いたとき aria-expanded=true', (await page.getAttribute('#menu-open', 'aria-expanded')) === 'true');
  await page.screenshot({ path: SHOT + 'menu.png' });
  await page.keyboard.press('Escape'); await page.waitForTimeout(200);
  check('Esc で閉じる', !(await page.isVisible('#menu')) && (await page.getAttribute('#menu-open', 'aria-expanded')) === 'false');
  await page.click('#menu-open'); await page.mouse.click(1000, 500); await page.waitForTimeout(200);
  check('外側を押すと閉じる', !(await page.isVisible('#menu')));
  await page.click('#menu-open'); await page.click('#menu-close'); await page.waitForTimeout(200);
  check('×で閉じる', !(await page.isVisible('#menu')));
  await page.click('#menu-open');
  await Promise.all([page.waitForNavigation(), page.click('#menu >> text=記録表示')]);
  check('記録表示へ移る', page.url() === BASE + 'records/', page.url());

  console.log('[2] 今月');
  const label = `${+M.slice(0, 4)}年${+M.slice(5, 7)}月`;
  check('月の表示', (await page.textContent('.month-nav__label')) === label);
  check('次の月は押せない・前の月は押せる', (await page.$$('a[aria-label="次の月"]')).length === 0 && (await page.getAttribute('a[aria-label="前の月"]', 'href')) === `?month=${prevM}`);
  check('出勤日数 3日・総労働時間 15時間45分', (await page.textContent('#summary-days')) === '3日' && (await page.textContent('#summary-total')) === '15時間45分', await page.textContent('#summary-days') + ' ' + await page.textContent('#summary-total'));
  check('1日の表示', (await cell(page, `${M}-01`).innerText()).replace(/\s+/g, ' ').includes('出 09:00 退 18:00'));
  check('2日：ほかの社員（07:00）の記録が出ない', (await cell(page, `${M}-02`).innerText()).includes('09:00') && !(await page.textContent('#calendar')).includes('07:00'));
  check('今日の印', (await cell(page, D).getAttribute('aria-current')) === 'date' && (await cell(page, D).getAttribute('class')).includes('calendar__day--today'));
  check('記録が無い日は時刻なし', !(await cell(page, `${M}-03`).innerText()).includes('出'));
  check('前月末の記録が混ざらない', !(await page.textContent('#calendar')).includes('08:00'));
  check('メニュー：記録表示が今いる画面', await page.evaluate(() => document.querySelector('#menu .menu__link[aria-current=page]').textContent) === '記録表示');
  await page.screenshot({ path: SHOT + 'pc_month.png', fullPage: true });

  console.log('[3] 日付を押す');
  let d = await detail(page, `${M}-01`);
  check('記録がある日', d.detailShown && !d.empty && d.in === '09:00' && d.out === '18:00' && d.brk === '15:00–15:15' && d.work === '7時間45分' && /^\d+月1日（.）$/.test(d.title), JSON.stringify(d));
  d = await detail(page, D);
  check('今日（退勤なし）', d.in === '09:00' && d.out === '--:--' && d.work === '--', JSON.stringify(d));
  // PR 5 から：記録が無い過去の日は、申請ウィンドウがすぐ開く（D-051）。未来の日は、その日の表示
  const allDates = await page.$$eval('[data-date]', bs => bs.map(b => b.dataset.date));
  const futureNoRec = allDates.find(x => x > D);
  if (futureNoRec) {
    d = await detail(page, futureNoRec);
    check('記録が無い日（未来）', d.empty && !d.detailShown, JSON.stringify(d));
  }
  const pastNoRec = allDates.find(x => x < D && ![`${M}-01`, `${M}-02`].includes(x));
  await cell(page, pastNoRec).click();
  check('記録が無い過去の日 → 申請ウィンドウ（PR 5）', await page.waitForSelector('#request-dialog[open]').then(() => true).catch(() => false));
  await page.keyboard.press('Escape'); await page.waitForTimeout(150);
  await cell(page, `${M}-01`).click(); await page.waitForSelector('#day-dialog[open]');
  await page.screenshot({ path: SHOT + 'pc_detail.png' });
  await page.keyboard.press('Escape'); await page.waitForTimeout(150);
  check('Esc で閉じる', !(await page.isVisible('#day-dialog')));

  console.log('[4] 前の月');
  await Promise.all([page.waitForNavigation(), page.click('a[aria-label="前の月"]')]);
  check('前の月へ', page.url().endsWith(`?month=${prevM}`) && (await page.getAttribute('a[aria-label="次の月"]', 'href')) === `?month=${M}`);
  check('前月末の記録・合計（11時間00分）', (await cell(page, prevLast).innerText()).includes('08:00') && (await page.textContent('#summary-days')) === '1日' && (await page.textContent('#summary-total')) === '11時間00分');

  console.log('[5] 祝日（2025年11月、2026年9月）');
  await page.goto(BASE + 'records/?month=2025-11');
  const t1103 = await cell(page, '2025-11-03').innerText(), t1124 = await cell(page, '2025-11-24').innerText();
  check('文化の日・振替休日', t1103.includes('文化の日') && t1124.includes('振替休日') && (await cell(page, '2025-11-24').getAttribute('class')).includes('rest'));
  check('土曜は休日の色、平日は通常', (await cell(page, '2025-11-01').getAttribute('class')).includes('rest') && !(await cell(page, '2025-11-04').getAttribute('class')).includes('rest'));
  // PR 5 から：記録が無い過去の日は申請ウィンドウが開き、日付と祝日名を出す
  await cell(page, '2025-11-24').click(); await page.waitForSelector('#request-dialog[open]');
  const dayLabel = await page.textContent('#request-day');
  check('ウィンドウに祝日名', dayLabel === '11月24日（月）　振替休日', dayLabel);
  await page.keyboard.press('Escape'); await page.waitForTimeout(150);
  await page.goto(BASE + 'records/?month=2026-09');
  check('敬老の日・国民の休日・秋分の日', ['敬老の日', '国民の休日', '秋分の日'].every((n, i) => true) && (await cell(page, '2026-09-21').innerText()).includes('敬老の日') && (await cell(page, '2026-09-22').innerText()).includes('国民の休日') && (await cell(page, '2026-09-23').innerText()).includes('秋分の日'));

  console.log('[6] 月の範囲');
  await page.goto(BASE + 'records/?month=2025-04');
  check('入社した月：前へ行けない', (await page.textContent('.month-nav__label')) === '2025年4月' && (await page.$$('a[aria-label="前の月"]')).length === 0);
  for (const bad of ['2025-03', '2099-01', 'abc', '2026-13', '<script>alert(1)</script>']) {
    await page.goto(BASE + 'records/?month=' + encodeURIComponent(bad));
    check(`範囲外・不正（${bad}）→ 今月`, (await page.textContent('.month-nav__label')) === label);
  }
  await page.goto(BASE + 'records/?month[]=1');
  check('month が配列 → 今月', (await page.textContent('.month-nav__label')) === label);

  console.log('[7] スマホの幅');
  const mctx = await browser.newContext({ viewport: { width: 375, height: 812 }, locale: 'ja-JP', isMobile: true, hasTouch: true });
  const m = await mctx.newPage();
  await login(m, '2025001', 'Test1234');
  await m.goto(BASE + 'records/');
  check('横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  check('時刻が出ている', (await cell(m, `${M}-01`).innerText()).includes('09:00'));
  await m.screenshot({ path: SHOT + 'sp_month.png', fullPage: true });
  await cell(m, `${M}-01`).click(); await m.waitForSelector('#day-dialog[open]');
  await m.screenshot({ path: SHOT + 'sp_detail.png' });
  await m.keyboard.press('Escape');
  await m.click('#menu-open'); await m.waitForSelector('#menu[open]');
  await m.screenshot({ path: SHOT + 'sp_menu.png' });
  await m.goto(BASE + 'records/?month=2025-11');
  check('スマホ：祝日の日も横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));

  console.log('[8] システム管理者・未ログイン');
  const sctx = await browser.newContext();
  const sp = await sctx.newPage();
  await login(sp, 'sysadmin', 'Admin1234');
  await sp.click('#menu-open');
  links = await sp.$$eval('#menu .menu__link', as => as.map(a => a.textContent));
  check('システム管理者：メイン画面のみ', JSON.stringify(links) === JSON.stringify(['メイン画面']));
  const res = await sp.goto(BASE + 'records/');
  check('records/ は 403', res.status() === 403);
  const nctx = await browser.newContext();
  const np = await nctx.newPage();
  await np.goto(BASE + 'records/');
  check('未ログインはログイン画面へ', np.url().endsWith('login.php'));
  check('ログイン画面にメニューは無い', (await np.$$('#menu-open')).length === 0);

  check('ブラウザのエラーなし', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
