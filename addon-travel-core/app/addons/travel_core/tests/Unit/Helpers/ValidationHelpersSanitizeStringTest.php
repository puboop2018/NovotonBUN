<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Helpers\ValidationHelpers;

/**
 * Coverage for ValidationHelpers::sanitizeString — the shared HTML-strip +
 * truncate helper both providers' SecurityService now use for free-text input.
 */
#[CoversClass(ValidationHelpers::class)]
class ValidationHelpersSanitizeStringTest extends TestCase
{
    public function testStripsHtmlTagsKeepingInnerText(): void
    {
        $this->assertSame('hello world', ValidationHelpers::sanitizeString('hello <b>world</b>'));
        $this->assertSame('alert(1)', ValidationHelpers::sanitizeString('<script>alert(1)</script>'));
    }

    public function testTruncatesToMaxLength(): void
    {
        $this->assertSame('abcde', ValidationHelpers::sanitizeString('abcdefghij', 5));
    }

    public function testDefaultsToPassThroughWhenNoTagsAndUnderLimit(): void
    {
        $this->assertSame('Plain text', ValidationHelpers::sanitizeString('Plain text'));
    }
}
