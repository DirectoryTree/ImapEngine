<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use Throwable;

class Authentication
{
    /**
     * Constructor.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected AuthenticatorInterface $authenticator,
    ) {}

    /**
     * Authenticate the connection.
     */
    public function authenticate(bool $initial = false): TaggedResponse
    {
        $response = $this->authenticator->initial();
        $sent = $initial && $response !== null;

        $exchange = $this->connection->authenticate(
            $this->authenticator->mechanism(),
            $sent ? $response : null,
        );

        while ($exchange->valid()) {
            $challenge = $exchange->current();

            try {
                if (! $sent && $response !== null) {
                    $answer = $response;
                    $sent = true;
                } else {
                    $answer = $this->authenticator->respond($challenge);
                }
            } catch (Throwable $e) {
                $this->connection->disconnect();

                throw $e;
            }

            $exchange->send($answer);
        }

        return $exchange->getReturn();
    }
}
