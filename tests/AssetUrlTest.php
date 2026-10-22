<?php
// CSS・JS の URL（src/functions.php の assetUrl()）のテスト：ファイルの更新日時が付く・ファイルを変えると URL が変わる（D-068）

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';

final class AssetUrlTest extends TestCase {
    // テストのために作る一時的なファイル（リポジトリの中。テストの最後に消す）
    private const TEMP = 'tests/asset_url_test.tmp.css';

    protected function setUp(): void {
        // Web ルートを、リポジトリの1つ上にする（appUrl() が /リポジトリ名/… の URL を作る）
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
    }

    protected function tearDown(): void {
        @unlink(__DIR__ . '/../' . self::TEMP);
    }

    public function testAddsModifiedTime(): void {
        $base = appUrl('css/style.css');
        $this->assertSame('/' . basename(dirname(__DIR__)) . '/css/style.css', $base);
        $this->assertSame($base . '?v=' . filemtime(__DIR__ . '/../css/style.css'), assetUrl('css/style.css'));
        $this->assertSame(assetUrl('js/menu.js'), assetUrl('/js/menu.js')); // 先頭の / があっても同じ
    }

    public function testUrlChangesWhenFileChanges(): void {
        $file = __DIR__ . '/../' . self::TEMP;
        file_put_contents($file, 'a{}');
        touch($file, 1761100000);
        clearstatcache();
        $this->assertSame(appUrl(self::TEMP) . '?v=1761100000', assetUrl(self::TEMP));
        touch($file, 1761200000); // ファイルを変えた（git pull など）
        clearstatcache();
        $this->assertSame(appUrl(self::TEMP) . '?v=1761200000', assetUrl(self::TEMP));
    }

    public function testMissingFileHasNoTime(): void {
        $this->assertSame(appUrl('js/nothing.js'), assetUrl('js/nothing.js')); // ファイルが無ければ、今までどおりの URL
        $this->assertSame(appUrl('js'), assetUrl('js'));                       // フォルダは対象外
    }
}
