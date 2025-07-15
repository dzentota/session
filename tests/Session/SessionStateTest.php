<?php

declare(strict_types=1);

namespace Dzentota\Session\Tests\Session;

use DateTimeImmutable;
use Dzentota\Session\SessionState;
use Dzentota\Session\Value\SessionId;
use PHPUnit\Framework\TestCase;

class SessionStateTest extends TestCase
{
    private SessionId $sessionId;
    private array $testData;
    private SessionState $state;

    protected function setUp(): void
    {
        $this->sessionId = SessionId::generate();
        $this->testData = [
            'user_id' => 123,
            'username' => 'john_doe',
            'preferences' => ['theme' => 'dark', 'notifications' => true]
        ];
        $this->state = new SessionState(
            $this->sessionId,
            $this->testData,
            new DateTimeImmutable('2025-07-15 10:00:00'),
            new DateTimeImmutable('2025-07-15 10:00:00')
        );
    }

    public function testConstructorAndGetters(): void
    {
        // Test basic constructor and getters
        $this->assertSame($this->sessionId, $this->state->getId());
        $this->assertEquals($this->testData, $this->state->getData());
        $this->assertEquals(SessionState::STATUS_ACTIVE, $this->state->getStatus());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->state->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->state->getLastActivityAt());
        $this->assertFalse($this->state->isDirty());
    }

    public function testGetMethod(): void
    {
        // Test getting existing values
        $this->assertEquals(123, $this->state->get('user_id'));
        $this->assertEquals('john_doe', $this->state->get('username'));
        $this->assertEquals(['theme' => 'dark', 'notifications' => true], $this->state->get('preferences'));

        // Test getting non-existent value with default
        $this->assertNull($this->state->get('non_existent'));
        $this->assertEquals('default_value', $this->state->get('non_existent', 'default_value'));
    }

    public function testHasMethod(): void
    {
        $this->assertTrue($this->state->has('user_id'));
        $this->assertFalse($this->state->has('non_existent'));
    }

    public function testWithMethod(): void
    {
        // Add a new value
        $newState = $this->state->with('new_key', 'new_value');

        // Ensure immutability
        $this->assertNotSame($this->state, $newState);
        $this->assertFalse($this->state->has('new_key'));
        $this->assertTrue($newState->has('new_key'));
        $this->assertEquals('new_value', $newState->get('new_key'));

        // Verify original data is preserved
        $this->assertEquals(123, $newState->get('user_id'));

        // Verify the new state is marked as dirty
        $this->assertTrue($newState->isDirty());
    }

    public function testWithoutMethod(): void
    {
        // Remove an existing value
        $newState = $this->state->without('user_id');

        // Ensure immutability
        $this->assertNotSame($this->state, $newState);
        $this->assertTrue($this->state->has('user_id'));
        $this->assertFalse($newState->has('user_id'));

        // Verify other data is preserved
        $this->assertEquals('john_doe', $newState->get('username'));

        // Verify the new state is marked as dirty
        $this->assertTrue($newState->isDirty());

        // Removing non-existent key should return the same state
        $sameState = $this->state->without('non_existent');
        $this->assertEquals($this->state->getData(), $sameState->getData());
    }

    public function testWithClearedData(): void
    {
        $clearedState = $this->state->withClearedData();

        // Ensure immutability
        $this->assertNotSame($this->state, $clearedState);

        // Verify data is cleared
        $this->assertEmpty($clearedState->getData());
        $this->assertFalse($clearedState->has('user_id'));

        // Verify ID and timestamps are preserved
        $this->assertSame($this->sessionId, $clearedState->getId());
        $this->assertEquals($this->state->getCreatedAt(), $clearedState->getCreatedAt());
        $this->assertEquals($this->state->getLastActivityAt(), $clearedState->getLastActivityAt());

        // Verify status and dirty flag
        $this->assertEquals(SessionState::STATUS_ACTIVE, $clearedState->getStatus());
        $this->assertTrue($clearedState->isDirty());
    }

    public function testWithRegeneratedId(): void
    {
        $regeneratedState = $this->state->withRegeneratedId();

        // Ensure immutability
        $this->assertNotSame($this->state, $regeneratedState);

        // Verify ID is changed
        $this->assertNotSame($this->sessionId, $regeneratedState->getId());
        $this->assertInstanceOf(SessionId::class, $regeneratedState->getId());

        // Verify data is preserved
        $this->assertEquals($this->testData, $regeneratedState->getData());

        // Verify timestamps are preserved
        $this->assertEquals($this->state->getCreatedAt(), $regeneratedState->getCreatedAt());
        $this->assertEquals($this->state->getLastActivityAt(), $regeneratedState->getLastActivityAt());

        // Verify status and dirty flag
        $this->assertEquals(SessionState::STATUS_REGENERATED, $regeneratedState->getStatus());
        $this->assertTrue($regeneratedState->isDirty());
    }

    public function testWithDestroyed(): void
    {
        $destroyedState = $this->state->withDestroyed();

        // Ensure immutability
        $this->assertNotSame($this->state, $destroyedState);

        // Verify data is cleared
        $this->assertEmpty($destroyedState->getData());

        // Verify ID is preserved
        $this->assertSame($this->sessionId, $destroyedState->getId());

        // Verify status and dirty flag
        $this->assertEquals(SessionState::STATUS_DESTROYED, $destroyedState->getStatus());
        $this->assertTrue($destroyedState->isDirty());
    }

    public function testWithRefreshedActivity(): void
    {
        // Create a state with an old activity time for testing
        $oldState = new SessionState(
            $this->sessionId,
            $this->testData,
            new DateTimeImmutable('2025-07-15 10:00:00'),
            new DateTimeImmutable('2025-07-15 10:00:00')
        );

        // Sleep to ensure time difference
        usleep(1000);

        $refreshedState = $oldState->withRefreshedActivity();

        // Ensure immutability
        $this->assertNotSame($oldState, $refreshedState);

        // Verify last activity time is updated
        $this->assertGreaterThan(
            $oldState->getLastActivityAt()->getTimestamp(),
            $refreshedState->getLastActivityAt()->getTimestamp()
        );

        // Verify other properties are preserved
        $this->assertSame($this->sessionId, $refreshedState->getId());
        $this->assertEquals($this->testData, $refreshedState->getData());
        $this->assertEquals($oldState->getCreatedAt(), $refreshedState->getCreatedAt());
        $this->assertEquals($oldState->getStatus(), $refreshedState->getStatus());
    }

    public function testWithCleanState(): void
    {
        // First create a dirty state
        $dirtyState = $this->state->with('key', 'value');
        $this->assertTrue($dirtyState->isDirty());

        // Then clean it
        $cleanState = $dirtyState->withCleanState();

        // Ensure immutability
        $this->assertNotSame($dirtyState, $cleanState);

        // Verify dirty flag is reset
        $this->assertFalse($cleanState->isDirty());

        // Verify other properties are preserved
        $this->assertEquals($dirtyState->getId(), $cleanState->getId());
        $this->assertEquals($dirtyState->getData(), $cleanState->getData());
        $this->assertEquals($dirtyState->getCreatedAt(), $cleanState->getCreatedAt());
        $this->assertEquals($dirtyState->getLastActivityAt(), $cleanState->getLastActivityAt());
        $this->assertEquals($dirtyState->getStatus(), $cleanState->getStatus());
    }
}
