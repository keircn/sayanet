<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Simple autoload for legacy global classes without namespace
require_once __DIR__ . '/../../src/_sayanet/private/php/core/class-util.php';
require_once __DIR__ . '/../../src/_sayanet/private/php/core/class-json.php';

final class ContextTest extends TestCase
{
    public function testNormalizePath(): void
    {
        $this->assertSame('/a/b', Util::normalize_path('/a//b/', false));
        $this->assertSame('/a/b/', Util::normalize_path('/a/b', true));
        $this->assertSame('/a/b/c', Util::normalize_path('/a/./b/../b/c', false));
    }

    public function testWrapPattern(): void
    {
        $re = Util::wrap_pattern('^\\.hidden');
        $this->assertSame(1, preg_match($re, '.hidden'));
        $this->assertSame(0, preg_match($re, 'visible'));
    }

    public function testArrayQuery(): void
    {
        $arr = ['view' => ['hidden' => ['^\\.']]];
        $this->assertSame(['^\\.'], Util::array_query($arr, 'view.hidden', []));
        $this->assertSame('default', Util::array_query($arr, 'missing.key', 'default'));
    }

    public function testPasswordHashHelper(): void
    {
        // ensure Context::hash_password exists and verifies
        if (class_exists('Context')) {
            $hash = Context::hash_password('test123');
            $this->assertTrue(password_verify('test123', $hash));
            $this->assertStringStartsWith('$', $hash);
        } else {
            $this->markTestSkipped('Context class not loaded');
        }
    }
}
