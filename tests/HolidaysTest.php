<?php
// 祝日の計算（src/holidays.php）のテスト
// 答え合わせ：内閣府の祝日一覧を元にした公開データ holiday-jp/holiday_jp（開発時だけ使う。composer.json の require-dev）

use HolidayJp\HolidayJp\Holidays;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/holidays.php';

final class HolidaysTest extends TestCase {
    // 公開データと名前が違う日（日付は一致。表示は、法律の名前・分かりやすい名前にしている）
    private const NAME_EXCEPTIONS = [
        '2019-05-01' => ['即位の日', '休日（祝日扱い）'],
        '2019-10-22' => ['即位礼正殿の儀', '休日（祝日扱い）'],
        '2019-10-14' => ['体育の日', '体育の日（スポーツの日）'],
    ];

    // 2000〜2050 年（公開データがある範囲）のすべての祝日・休日が、日付も名前も一致する
    public function testMatchesPublicData(): void {
        for ($year = 2000; $year <= 2050; $year++) {
            $expected = [];
            foreach (Holidays::$holidays as $date => $holiday) {
                if (str_starts_with($date, $year . '-')) {
                    $expected[$date] = $holiday['name'];
                }
            }
            $actual = japaneseHolidays($year);
            $this->assertSame(array_keys($expected), array_keys($actual), $year . ' 年の日付');

            foreach ($actual as $date => $name) {
                $theirs = $expected[$date];
                if (isset(self::NAME_EXCEPTIONS[$date])) {
                    $this->assertSame(self::NAME_EXCEPTIONS[$date], [$name, $theirs], $date);
                    continue;
                }
                // 公開データの「○○ 振替休日」は「振替休日」、「休日」は「国民の休日」と表示する
                $normalized = str_ends_with($theirs, '振替休日') ? '振替休日' : ($theirs === '休日' ? '国民の休日' : $theirs);
                $this->assertSame($normalized, $name, $date);
            }
        }
    }

    // 主な例（2026 年）
    public function testExamples2026(): void {
        $holidays = japaneseHolidays(2026);
        $this->assertSame('成人の日', $holidays['2026-01-12']);
        $this->assertSame('振替休日', $holidays['2026-05-06']);   // 5/3（日）の振替。5/4・5/5 が祝日なので 5/6
        $this->assertSame('国民の休日', $holidays['2026-09-22']); // 敬老の日（9/21）と秋分の日（9/23）の間
        $this->assertSame('スポーツの日', $holidays['2026-10-12']);
        $this->assertArrayNotHasKey('2026-12-23', $holidays);
        $this->assertCount(18, $holidays);
    }

    public function testOutOfRangeYear(): void {
        $this->expectException(InvalidArgumentException::class);
        japaneseHolidays(1999);
    }
}
