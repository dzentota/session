<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session;

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Tests\Session\Support\ArrayStorage;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class CsrfTokenPoolTest extends TestCase
{
    private function manager(int $pool = 10, int $ttl = 7200): SessionManager
    {
        $m = new SessionManager(new ArrayStorage(), new CookieManager(), csrfTokenPoolSize: $pool, csrfTokenTtl: $ttl);
        $m->start(new ServerRequest('GET', '/', ['User-Agent' => 'UA'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']));
        return $m;
    }

    public function testMultipleOutstandingTokensAreAllValid(): void
    {
        $m = $this->manager();
        $a = $m->generateCsrfToken()->toNative();
        $b = $m->generateCsrfToken()->toNative();
        $this->assertTrue($m->isCsrfTokenValid($a));
        $this->assertTrue($m->isCsrfTokenValid($b));
        $this->assertFalse($m->isCsrfTokenValid(str_repeat('a', strlen($a))));
    }

    public function testPoolIsBounded(): void
    {
        $m = $this->manager(3);
        $first = $m->generateCsrfToken()->toNative();
        for ($i = 0; $i < 3; $i++) {
            $last = $m->generateCsrfToken()->toNative();
        }
        $this->assertFalse($m->isCsrfTokenValid($first));
        $this->assertTrue($m->isCsrfTokenValid($last));
    }

    public function testExpiredTokensAreRejected(): void
    {
        $m = $this->manager(10, 1);
        $t = $m->generateCsrfToken()->toNative();
        $pool = $m->getState()->get('_csrf_tokens');
        foreach ($pool as $h => $exp) {
            $pool[$h] = time() - 1;
        }
        $m->set('_csrf_tokens', $pool);
        $this->assertFalse($m->isCsrfTokenValid($t));
    }

    public function testConsumeIsSingleUse(): void
    {
        $m = $this->manager();
        $a = $m->generateCsrfToken()->toNative();
        $b = $m->generateCsrfToken()->toNative();
        $this->assertTrue($m->consumeCsrfToken($a));
        $this->assertFalse($m->consumeCsrfToken($a));
        $this->assertFalse($m->isCsrfTokenValid($a));
        $this->assertTrue($m->isCsrfTokenValid($b));
    }

    public function testLegacySingleHashStillValidates(): void
    {
        $m = $this->manager();
        $t = \Dzentota\Session\Value\CsrfToken::generate();
        $m->set('_csrf_token', $t->getHash());
        $this->assertTrue($m->isCsrfTokenValid($t->toNative()));
    }
}
