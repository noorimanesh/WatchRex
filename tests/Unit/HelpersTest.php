<?php

namespace Tests\Unit;

use App\Services\Checks\Checkers\HttpChecker;
use App\Services\Checks\TargetGuard;
use App\Services\Domain\DomainInspector;
use App\Services\Format;
use App\Services\Totp;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function test_status_code_rules(): void
    {
        $this->assertTrue(HttpChecker::statusMatches(204, '200-299'));
        $this->assertTrue(HttpChecker::statusMatches(301, '200,301'));
        $this->assertFalse(HttpChecker::statusMatches(500, '200-399'));
    }

    public function test_totp_matches_rfc6238_vector(): void
    {
        // RFC 6238 test secret "12345678901234567890" in base32, T = 59s → 287082 (6 digits).
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
    }

    public function test_jalali_conversion(): void
    {
        $this->assertSame([1405, 7, 5], Format::toJalali(2026, 9, 27));
        $this->assertSame([1403, 1, 1], Format::toJalali(2024, 3, 20));
    }

    public function test_registrable_domain(): void
    {
        $this->assertSame('example.com', DomainInspector::registrable('mail.example.com'));
        $this->assertSame('fabapars.co.ir', DomainInspector::registrable('www.fabapars.co.ir'));
    }

    public function test_private_address_detection(): void
    {
        $this->assertFalse(TargetGuard::isPublic('10.0.0.5'));
        $this->assertFalse(TargetGuard::isPublic('127.0.0.1'));
        $this->assertFalse(TargetGuard::isPublic('169.254.169.254'));
        $this->assertTrue(TargetGuard::isPublic('1.1.1.1'));
    }

    public function test_formatting(): void
    {
        $this->assertSame('3m 21s', Format::duration(201));
        $this->assertSame('1.5 KB', Format::bytes(1536));
        $this->assertSame('99.98%', Format::uptime(99.98));
    }
}
