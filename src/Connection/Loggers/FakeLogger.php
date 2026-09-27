<?php

namespace DirectoryTree\ImapEngine\Connection\Loggers;

use PHPUnit\Framework\Assert;

class FakeLogger implements LoggerInterface
{
    /**
     * The sent messages.
     */
    protected array $sent = [];

    /**
     * The received messages.
     */
    protected array $received = [];

    /**
     * {@inheritDoc}
     */
    public function sent(string $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * {@inheritDoc}
     */
    public function received(string $message): void
    {
        $this->received[] = $message;
    }

    /**
     * Assert that the given message was sent.
     */
    public function assertSent(string $message, int $times = 1): void
    {
        Assert::assertSame(
            $times,
            count(array_keys($this->sent, $message, strict: true)),
            "Failed asserting that the message '{$message}' was sent {$times} times."
        );
    }

    /**
     * Assert that the given message was not sent.
     */
    public function assertNotSent(string $message): void
    {
        $this->assertSent($message, times: 0);
    }

    /**
     * Assert that the given message was received.
     */
    public function assertReceived(string $message, int $times = 1): void
    {
        Assert::assertSame(
            $times,
            count(array_keys($this->received, $message, strict: true)),
            "Failed asserting that the message '{$message}' was received {$times} times."
        );
    }

    /**
     * Assert that the given message was not received.
     */
    public function assertNotReceived(string $message): void
    {
        $this->assertReceived($message, times: 0);
    }
}
