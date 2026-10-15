<?php
// 記録表示（src/records.php）のテスト：月の決め方、1日の労働時間、カレンダーの組み立て。DB は使わない

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/records.php';

final class RecordsTest extends TestCase {
    private const SETTINGS = ['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00'];

    private static function row(?string $in, ?string $out, ?string $breakStart = null, ?string $breakEnd = null, string $date = '2026-10-15'): array {
        $at = fn (?string $time) => $time === null ? null : $date . ' ' . $time . ':00';
        return ['work_date' => $date, 'check_in_at' => $at($in), 'check_out_at' => $at($out), 'break_start_at' => $at($breakStart), 'break_end_at' => $at($breakEnd)];
    }

    // ---- 月の決め方（前は入社月まで、次は今月まで。D-061） ----
    public static function months(): array {
        return [
            '指定なし → 今月' => [null, '2026-10', '2026-09', null],
            '過去の月' => ['2026-09', '2026-09', '2026-08', '2026-10'],
            '入社した月（前へは行けない）' => ['2025-04', '2025-04', null, '2025-05'],
            '入社より前 → 今月' => ['2025-03', '2026-10', '2026-09', null],
            '未来の月 → 今月' => ['2026-11', '2026-10', '2026-09', null],
            '月が 13 → 今月' => ['2026-13', '2026-10', '2026-09', null],
            '月が 00 → 今月' => ['2026-00', '2026-10', '2026-09', null],
            '形が違う → 今月' => ['2026-9', '2026-10', '2026-09', null],
            '文字 → 今月' => ['<script>', '2026-10', '2026-09', null],
            '年またぎ' => ['2026-01', '2026-01', '2025-12', '2026-02'],
        ];
    }

    #[DataProvider('months')]
    public function testResolveMonth(?string $requested, string $month, ?string $prev, ?string $next): void {
        $result = resolveMonth($requested, '2026-10-15', '2025-04-01');
        $this->assertSame([$month, $prev, $next], [$result['month'], $result['prev'], $result['next']]);
    }

    public function testResolveMonthDetails(): void {
        $this->assertSame(['2026年9月', '2026-09-01', '2026-09-30'], array_values(array_intersect_key(resolveMonth('2026-09', '2026-10-15', '2025-04-01'), array_flip(['label', 'first', 'last']))));
        $this->assertSame('2024-02-29', resolveMonth('2024-02', '2026-10-15', '2020-01-01')['last']); // うるう年
        $this->assertSame('2025-02-28', resolveMonth('2025-02', '2026-10-15', '2020-01-01')['last']);
        // 今月に入社した人：前にも次にも行けない
        $this->assertSame(['2026-10', null, null], array_values(array_intersect_key(resolveMonth(null, '2026-10-15', '2026-10-01'), array_flip(['month', 'prev', 'next']))));
        // 入社日が未来でも、今月を表示する
        $this->assertSame(['2026-10', null, null], array_values(array_intersect_key(resolveMonth(null, '2026-10-15', '2027-04-01'), array_flip(['month', 'prev', 'next']))));
        // 祝日を計算できない 2000 年より前には戻れない
        $this->assertNull(resolveMonth('2000-01', '2026-10-15', '1995-04-01')['prev']);
    }

    // ---- 1日の労働時間（D-049・D-061） ----
    public static function workTimes(): array {
        return [
            '標準（9-18、昼休憩あり）' => [self::row('09:00', '18:00'), 480],
            '休憩 15 分' => [self::row('09:00', '18:00', '15:00', '15:15'), 465],
            '昼休憩の前に退勤' => [self::row('09:00', '11:30'), 150],
            '昼休憩の途中で退勤' => [self::row('09:00', '12:30'), 180],
            '昼休憩の途中から出勤' => [self::row('12:30', '18:00'), 300],
            '昼休憩のあとに出勤' => [self::row('13:30', '18:00'), 270],
            '休憩終了なし → 休憩は引かない' => [self::row('09:00', '18:00', '15:00'), 480],
            '休憩が昼休憩と全部重なる → 1回だけ引く' => [self::row('09:00', '18:00', '12:10', '12:40'), 480],
            '休憩が昼休憩と一部重なる' => [self::row('09:00', '18:00', '12:30', '13:30'), 450],
            '休憩が勤務の外にはみ出す' => [self::row('09:00', '18:00', '17:30', '18:30'), 450],
            '退勤なし' => [self::row('09:00', null), null],
            '出勤なし' => [self::row(null, '18:00'), null],
            '退勤が出勤より前（ありえない値）→ 0' => [self::row('18:00', '09:00'), 0],
        ];
    }

    #[DataProvider('workTimes')]
    public function testDailyWorkMinutes(array $row, ?int $expected): void {
        $this->assertSame($expected, dailyWorkMinutes($row, '2026-10-15', self::SETTINGS));
    }

    public function testDailyWorkMinutesUsesSettings(): void {
        $settings = ['lunch_start' => '11:30:00', 'lunch_end' => '12:15:00'] + self::SETTINGS;
        $this->assertSame(495, dailyWorkMinutes(self::row('09:00', '18:00'), '2026-10-15', $settings));
    }

    public function testFormatMinutes(): void {
        $this->assertSame('0時間00分', formatMinutes(0));
        $this->assertSame('8時間05分', formatMinutes(485));
        $this->assertSame('160時間30分', formatMinutes(9630));
    }

    // ---- カレンダー ----
    public function testBuildCalendar(): void {
        $month = resolveMonth('2026-09', '2026-10-15', '2025-04-01');
        $rows = [
            self::row('09:00', '18:00', null, null, '2026-09-01'),              // 480 分
            self::row('09:00', '18:00', '15:00', '15:30', '2026-09-02'),        // 450 分
            self::row('09:00', null, null, null, '2026-09-03'),                 // 退勤なし：日数には数え、時間には入れない
            self::row('09:00', '18:00', '15:00', null, '2026-09-04'),           // 休憩終了なし
        ];
        $calendar = buildCalendar($month, $rows, self::SETTINGS, '2026-10-15');

        $this->assertSame(4, $calendar['workDays']);
        $this->assertSame(480 + 450 + 480, $calendar['totalMinutes']);

        // 2026-09-01 は火曜日：最初の週は、日・月が空き
        $this->assertCount(5, $calendar['weeks']);
        $this->assertNull($calendar['weeks'][0][0]);
        $this->assertNull($calendar['weeks'][0][1]);
        $this->assertSame('2026-09-01', $calendar['weeks'][0][2]['date']);
        $this->assertSame('2026-09-30', $calendar['weeks'][4][3]['date']);
        $this->assertNull($calendar['weeks'][4][4]);

        $cells = [];
        foreach ($calendar['weeks'] as $week) {
            foreach (array_filter($week) as $cell) {
                $cells[$cell['date']] = $cell;
            }
        }
        $this->assertCount(30, $cells);
        $this->assertSame(['9月1日（火）', '09:00', '18:00', '8時間00分', true], [$cells['2026-09-01']['label'], $cells['2026-09-01']['checkIn'], $cells['2026-09-01']['checkOut'], $cells['2026-09-01']['workTime'], $cells['2026-09-01']['hasRecord']]);
        $this->assertSame(['--:--', '--'], [$cells['2026-09-03']['checkOut'], $cells['2026-09-03']['workTime']]);
        $this->assertSame('15:00–', $cells['2026-09-04']['break']); // 過去の日は「休憩中」と出さない
        $this->assertFalse($cells['2026-09-05']['hasRecord']);

        // 休日：土日・祝日（9/21 敬老の日、9/22 国民の休日、9/23 秋分の日）
        $this->assertTrue($cells['2026-09-05']['isRestDay']); // 土
        $this->assertTrue($cells['2026-09-06']['isRestDay']); // 日
        $this->assertFalse($cells['2026-09-07']['isRestDay']);
        $this->assertSame(['敬老の日', '国民の休日', '秋分の日'], [$cells['2026-09-21']['holiday'], $cells['2026-09-22']['holiday'], $cells['2026-09-23']['holiday']]);
        $this->assertTrue($cells['2026-09-22']['isRestDay']);
        $this->assertFalse($cells['2026-09-01']['isToday']);
    }

    public function testBuildCalendarToday(): void {
        $month = resolveMonth(null, '2026-10-15', '2025-04-01');
        $calendar = buildCalendar($month, [self::row('09:00', null, '12:30')], self::SETTINGS, '2026-10-15');
        $today = array_values(array_filter(array_merge(...$calendar['weeks']), fn ($c) => $c !== null && $c['isToday']));
        $this->assertCount(1, $today);
        $this->assertSame(['2026-10-15', '12:30–休憩中'], [$today[0]['date'], $today[0]['break']]);
        $this->assertSame(1, $calendar['workDays']);
        $this->assertSame(0, $calendar['totalMinutes']);
    }
}
