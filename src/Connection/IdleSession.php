<?php

namespace DirectoryTree\ImapEngine\Connection;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use Generator;
use LogicException;

class IdleSession
{
    /**
     * Whether the session still owns the connection.
     */
    protected bool $active = true;

    /**
     * Whether the server has acknowledged IDLE with a continuation.
     */
    protected bool $ready = false;

    /**
     * Whether response consumption has started.
     */
    protected bool $started = false;

    /**
     * Updates not yet delivered to the caller.
     *
     * @var UntaggedResponse[]
     */
    protected array $pending = [];

    /**
     * The deadline for receiving updates or finishing the IDLE exchange.
     */
    protected ?CarbonInterface $deadline = null;

    /**
     * Create an IDLE session on the connection.
     */
    public function __construct(
        /**
         * The IDLE command used to match completion and report errors.
         */
        protected ImapCommand $command,
        /**
         * The connection used for the IDLE exchange.
         */
        protected ConnectionInterface $connection,
    ) {}

    /**
     * Determine whether this session still owns the connection.
     */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Invalidate the session when its connection is closed.
     */
    public function invalidate(): void
    {
        $this->active = false;
        $this->pending = [];
    }

    /**
     * Yield unsolicited updates until completion or the renewal deadline.
     *
     * Finish the session or disconnect before sending another command.
     *
     * @param  int  $timeout  The renewal interval in seconds.
     * @return Generator<int, UntaggedResponse>
     */
    public function responses(int $timeout = 300): Generator
    {
        if ($this->started) {
            throw new LogicException('IDLE responses may only be consumed once.');
        }

        $this->started = true;
        $this->deadline = Carbon::now()->addSeconds(max($timeout, 1));

        while ($this->active) {
            if ($this->ready) {
                if (Carbon::now()->greaterThanOrEqualTo($this->deadline)) {
                    return;
                }

                if ($this->pending) {
                    yield array_shift($this->pending);

                    continue;
                }
            }

            $response = $this->receive();

            if ($response instanceof UntaggedResponse) {
                $this->pending[] = $response;
            }
        }
    }

    /**
     * Finish IDLE and return updates not yet delivered to the caller.
     *
     * @param  int  $timeout  The time allowed to complete the exchange, in seconds.
     */
    public function finish(int $timeout = 30): ResponseCollection
    {
        if (! $this->active) {
            return new ResponseCollection;
        }

        // Completion has its own deadline, separate from the renewal interval.
        $this->deadline = Carbon::now()->addSeconds($timeout);

        try {
            while ($this->active && ! $this->ready) {
                if (($response = $this->receive()) instanceof UntaggedResponse) {
                    $this->pending[] = $response;
                }
            }

            if ($this->active) {
                $this->connection->write('DONE');
            }

            $responses = new ResponseCollection($this->pending);

            $this->pending = [];

            while ($this->active) {
                if (($response = $this->receive()) instanceof UntaggedResponse) {
                    $responses->push($response);
                }
            }

            return $responses;
        } catch (ImapConnectionTimedOutException $e) {
            // A missing acknowledgement leaves the connection unusable.
            $this->connection->disconnect();

            throw $e;
        }
    }

    /**
     * Read a response and advance the IDLE protocol state.
     */
    protected function receive(): Response
    {
        $remaining = (int) ceil(Carbon::now()->diffInSeconds($this->deadline, false));

        $this->connection->stream()->setTimeout(max(1, $remaining));

        try {
            if ($remaining <= 0) {
                throw new ImapConnectionTimedOutException('IDLE session timed out');
            }

            $response = $this->connection->read();
        } catch (ImapConnectionTimedOutException $e) {
            if (! $this->ready) {
                $this->connection->disconnect();

                throw new ImapConnectionClosedException('Timed out waiting for the IDLE continuation', previous: $e);
            }

            throw $e;
        } catch (ImapConnectionClosedException $e) {
            $this->connection->disconnect();

            throw $e;
        }

        if ($response instanceof ContinuationResponse) {
            $this->ready = true;
        } elseif ($response instanceof TaggedResponse && $response->tag()->value === $this->command->tag()) {
            $this->active = false;

            if (! $response->successful()) {
                throw ImapCommandException::make($this->command, $response);
            }
        } elseif ($response instanceof UntaggedResponse && $response->type()->is('BYE')) {
            $this->connection->disconnect();

            throw new ImapConnectionClosedException((string) $response);
        }

        return $response;
    }
}
