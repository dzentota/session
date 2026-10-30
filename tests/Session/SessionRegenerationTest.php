<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session;

use Dzentota\Session\Cookie\CookieManager;
use Dzentota\Session\SessionManager;
use Dzentota\Session\Tests\Session\Support\ArrayStorage;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class SessionRegenerationTest extends TestCase
{
    private function request(?string $sid = null): ServerRequest
    {
        $r = new ServerRequest('GET', '/', ['User-Agent' => 'UA'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);
        return $sid === null ? $r : $r->withCookieParams(['__Host-id' => $sid]);
    }

    /** @return array{0:string,1:string} [oldId, newId] */
    private function loginAndRegenerate(ArrayStorage $storage, int $grace): array
    {
        $m = new SessionManager($storage, new CookieManager(), regenerateGracePeriod: $grace);
        $old = $m->start($this->request())->getId()->toNative();
        $m->set('user_id', 42);
        $m->commit(new Response());

        $m2 = new SessionManager($storage, new CookieManager(), regenerateGracePeriod: $grace);
        $m2->start($this->request($old));
        $m2->regenerateId();
        $m2->commit(new Response());
        return [$old, $m2->getState()->getId()->toNative()];
    }

    /** @dataProvider graceProvider */
    public function testOldIdCannotReadDataAfterRegeneration(int $grace): void
    {
        $storage = new ArrayStorage();
        [$old, $new] = $this->loginAndRegenerate($storage, $grace);

        $attacker = new SessionManager($storage, new CookieManager(), regenerateGracePeriod: $grace);
        $state = $attacker->start($this->request($old));
        $this->assertNotSame($old, $state->getId()->toNative());
        $this->assertNull($attacker->get('user_id'));

        if (isset($storage->data[$old])) {
            $this->assertStringNotContainsString('user_id', $storage->data[$old]);
        }

        $legit = new SessionManager($storage, new CookieManager(), regenerateGracePeriod: $grace);
        $legit->start($this->request($new));
        $this->assertSame(42, $legit->get('user_id'));
    }

    public static function graceProvider(): array
    {
        return ['no grace' => [0], 'grace 10s' => [10]];
    }
}
