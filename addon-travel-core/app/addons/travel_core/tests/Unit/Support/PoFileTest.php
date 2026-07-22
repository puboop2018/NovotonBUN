<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Support\PoFile;

final class PoFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'po') ?: '/tmp/po-test';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    public function testParsesLanguagesEntriesOnly(): void
    {
        file_put_contents($this->tmp, <<<'PO'
msgctxt "Addons::name::travel_core"
msgid "Travel Core"
msgstr "Travel Core"

msgctxt "Languages::travel_core.hello"
msgid "Hello"
msgstr "Salut"

msgctxt "SettingsOptions::travel_core::thing"
msgid "Thing"
msgstr "Thing"
PO);

        self::assertSame(['travel_core.hello' => 'Salut'], PoFile::languages($this->tmp));
    }

    public function testConcatenatesContinuationLinesAndUnescapes(): void
    {
        file_put_contents($this->tmp, <<<'PO'
msgctxt "Languages::k.multi"
msgid "x"
msgstr "first "
"second \"quoted\""
PO);

        self::assertSame(['k.multi' => 'first second "quoted"'], PoFile::languages($this->tmp));
    }

    public function testMissingFileYieldsEmptyMap(): void
    {
        self::assertSame([], PoFile::languages('/nonexistent/file.po'));
    }
}
