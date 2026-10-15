<?php
// メイン画面の判断（src/attendance.php）のテスト。DB は使わない
// 実行：composer test（または vendor/bin/phpunit tests）

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/attendance.php';

final class AttendanceTest extends TestCase {
    // 勤怠の行を作る（省略した列は NULL）
    private static function row(?string $in = null, ?string $out = null, ?string $breakStart = null, ?string $breakEnd = null): array {
        $at = fn (?string $time) => $time === null ? null : '2026-10-15 ' . $time . ':00';
        return [
            'check_in_at' => $at($in),
            'check_out_at' => $at($out),
            'break_start_at' => $at($breakStart),
            'break_end_at' => $at($breakEnd),
        ];
    }

    // 状態ごとの、押したときの扱い（出勤・退勤・休憩開始・休憩終了の順）と、状態バッジ
    public static function states(): array {
        $r = ACTION_RECORD;
        $o = ACTION_OVERWRITE;
        $u = ACTION_UNAVAILABLE;
        return [
            '行が無い' => [null, [$r, $u, $u, $u], 'not_started'],
            '行はあるが全部空' => [self::row(), [$r, $u, $u, $u], 'not_started'],
            '出勤のみ' => [self::row('09:00'), [$o, $r, $r, $u], 'working'],
            '休憩中' => [self::row('09:00', null, '12:30'), [$u, $u, $u, $r], 'on_break'],
            '休憩終了後' => [self::row('09:00', null, '12:30', '12:45'), [$u, $r, $u, $u], 'working'],
            '退勤済み（休憩なし）' => [self::row('09:00', '18:00'), [$u, $o, $u, $u], 'finished'],
            '退勤済み（休憩あり）' => [self::row('09:00', '18:00', '12:30', '12:45'), [$u, $o, $u, $u], 'finished'],
            // 修正申請（PR 5）で作られうる、ボタンでは起きない組み合わせ
            '出勤なしで退勤あり' => [self::row(null, '18:00'), [$u, $u, $u, $u], 'finished'],
            '休憩終了なしで退勤' => [self::row('09:00', '18:00', '12:30'), [$u, $o, $u, $u], 'finished'],
        ];
    }

    #[DataProvider('states')]
    public function testActionModeAndStatus(?array $attendance, array $expectedModes, string $expectedStatus): void {
        $actions = ['check_in', 'check_out', 'break_start', 'break_end'];
        foreach ($actions as $i => $action) {
            $this->assertSame($expectedModes[$i], actionMode($attendance, $action), $action);
        }
        $this->assertSame($expectedStatus, attendanceStatus($attendance));

        $view = attendanceView($attendance);
        $this->assertSame($expectedStatus, $view['status']);
        $this->assertSame(ATTENDANCE_STATUS_LABELS[$expectedStatus], $view['statusLabel']);
        foreach ($actions as $i => $action) {
            $this->assertSame($expectedModes[$i] !== ACTION_UNAVAILABLE, $view['buttons'][$action], $action);
        }
    }

    public function testUnknownActionIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        actionMode(null, 'delete');
    }

    public function testViewLabels(): void {
        $this->assertSame(
            ['checkIn' => '--:--', 'checkOut' => '--:--', 'break' => '--:--'],
            array_intersect_key(attendanceView(null), ['checkIn' => 0, 'checkOut' => 0, 'break' => 0])
        );
        $this->assertSame('12:30–休憩中', attendanceView(self::row('09:00', null, '12:30'))['break']);
        $this->assertSame('12:30–12:45', attendanceView(self::row('09:00', null, '12:30', '12:45'))['break']);
        $this->assertSame('12:30–', attendanceView(self::row('09:00', '18:00', '12:30'))['break']);

        $view = attendanceView(self::row('09:01', '18:02'));
        $this->assertSame('09:01', $view['checkIn']);
        $this->assertSame('18:02', $view['checkOut']);
    }

    // 時刻は分単位（秒は切り捨て）、勤務日は 0時で切り替え（日本時間）
    public function testMinuteTimestampAndWorkDate(): void {
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());

        $t = (new DateTimeImmutable('2026-10-15 09:01:59', new DateTimeZone('Asia/Tokyo')))->getTimestamp();
        $this->assertSame('2026-10-15 09:01:00', minuteTimestamp($t));
        $this->assertSame('2026-10-15', workDateOf($t));

        $beforeMidnight = (new DateTimeImmutable('2026-10-15 23:59:59', new DateTimeZone('Asia/Tokyo')))->getTimestamp();
        $this->assertSame('2026-10-15', workDateOf($beforeMidnight));
        $this->assertSame('2026-10-15 23:59:00', minuteTimestamp($beforeMidnight));
        $this->assertSame('2026-10-16', workDateOf($beforeMidnight + 1));
        $this->assertSame('2026-10-16 00:00:00', minuteTimestamp($beforeMidnight + 1));

        // UTC では前日の 15:00 の瞬間も、日本時間の日付で判定する
        $utc = (new DateTimeImmutable('2026-10-15 15:30:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->assertSame('2026-10-16', workDateOf($utc));
    }
}
