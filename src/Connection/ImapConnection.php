<?php

namespace DirectoryTree\ImapEngine\Connection;

use DateTimeInterface;
use DirectoryTree\ImapEngine\AppendResult;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Loggers\LoggerInterface;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Connection\Streams\StreamInterface;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\ImapResponseException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
use DirectoryTree\ImapEngine\Fetch\ModifierInterface as FetchModifierInterface;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\FetchResult;
use DirectoryTree\ImapEngine\ImapSort;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result as SelectionResult;
use DirectoryTree\ImapEngine\Store\ModifierInterface as StoreModifierInterface;
use DirectoryTree\ImapEngine\StoreResult;
use DirectoryTree\ImapEngine\Support\Str;
use Exception;
use Generator;
use LogicException;
use Throwable;

class ImapConnection implements ConnectionInterface
{
    /**
     * Sequence number used to generate unique command tags.
     */
    protected int $sequence = 0;

    /**
     * The result instance.
     */
    protected ?Result $result = null;

    /**
     * The parser instance.
     */
    protected ?ImapParser $parser = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected StreamInterface $stream,
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * Create a new connection with a fake stream.
     */
    public static function fake(array $responses = []): static
    {
        $stream = new FakeStream;

        $stream->open();

        $stream->feed($responses);

        return new static($stream);
    }

    /**
     * Tear down the connection.
     */
    public function __destruct()
    {
        if (! $this->connected()) {
            return;
        }

        try {
            @$this->logout();
        } catch (Exception $e) {
            // Do nothing.
        }
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $host, ?int $port = null, array $options = []): void
    {
        $transport = strtolower($options['encryption'] ?? '') ?: 'tcp';

        if (in_array($transport, ['ssl', 'tls'])) {
            $port ??= 993;
        } else {
            $port ??= 143;
        }

        $this->setParser(
            $this->newParser($this->stream)
        );

        $this->stream->open(
            $transport === 'starttls' ? 'tcp' : $transport,
            $host,
            $port,
            $options['timeout'] ?? 30,
            $this->getDefaultSocketOptions(
                $transport,
                $options['proxy'] ?? [],
                $options['validate_cert'] ?? true
            )
        );

        $this->assertNextResponse(
            fn (Response $response) => $response instanceof UntaggedResponse,
            fn (UntaggedResponse $response) => $response->type()->is('OK'),
            fn () => new ImapConnectionFailedException("Connection to $host:$port failed")
        );

        if ($transport === 'starttls') {
            $this->startTls();
        }
    }

    /**
     * Get the default socket options for the given transport.
     *
     * @param  'ssl'|'tls'|'starttls'|'tcp'  $transport
     */
    protected function getDefaultSocketOptions(string $transport, array $proxy = [], bool $validateCert = true): array
    {
        $options = [];

        $key = match ($transport) {
            'ssl', 'tls' => 'ssl',
            'starttls', 'tcp' => 'tcp',
        };

        if (in_array($transport, ['ssl', 'tls'])) {
            $options[$key] = [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
            ];
        }

        if (! isset($proxy['socket'])) {
            return $options;
        }

        $options[$key]['proxy'] = $proxy['socket'];
        $options[$key]['request_fulluri'] = $proxy['request_fulluri'] ?? false;

        if (isset($proxy['username'])) {
            $auth = base64_encode($proxy['username'].':'.$proxy['password']);

            $options[$key]['header'] = ["Proxy-Authorization: Basic $auth"];
        }

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(): void
    {
        $this->stream->close();
    }

    /**
     * {@inheritDoc}
     */
    public function connected(): bool
    {
        return $this->stream->opened();
    }

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): TaggedResponse
    {
        $this->send('LOGIN', Str::literal([$user, $password]), $tag);

        return $this->assertTaggedResponse($tag, fn (TaggedResponse $response) => (
            ImapCommandException::make($this->result->command()->redacted(), $response)
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): void
    {
        try {
            $this->send('LOGOUT', tag: $tag);

            $this->assertTaggedResponse($tag);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $mechanism, ?string $initial = null): Generator
    {
        $tokens = [$mechanism];

        if ($initial !== null) {
            $tokens[] = $initial === '' ? '=' : base64_encode($initial);
        }

        $this->send('AUTHENTICATE', $tokens, $tag);

        while ($response = $this->nextResponse(fn (Response $response) => (
            $response instanceof ContinuationResponse
            || ($response instanceof TaggedResponse && $response->tag()->is($tag))
        ))) {
            if ($response instanceof TaggedResponse) {
                if ($response->failed()) {
                    throw ImapCommandException::make($this->result->command()->redacted(), $response);
                }

                return $response;
            }

            yield base64_decode(trim(substr((string) $response, 1)));
        }

        throw new ImapResponseException('No authentication response found');
    }

    /**
     * {@inheritDoc}
     */
    public function respond(?string $response): void
    {
        $this->write($response === null ? '*' : base64_encode($response), sensitive: true);
    }

    /**
     * {@inheritDoc}
     */
    public function startTls(): void
    {
        $this->send('STARTTLS', tag: $tag);

        $this->assertTaggedResponse($tag);

        $this->stream->setSocketSetCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    /**
     * {@inheritDoc}
     */
    public function enable(string ...$capabilities): ResponseCollection
    {
        $this->send('ENABLE', $capabilities, $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('ENABLED')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function select(string $folder = 'INBOX', OptionInterface ...$options): SelectionResult
    {
        return $this->examineOrSelect('SELECT', $folder, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function examine(string $folder = 'INBOX', OptionInterface ...$options): SelectionResult
    {
        return $this->examineOrSelect('EXAMINE', $folder, $options);
    }

    /**
     * Examine and select have the same response.
     */
    protected function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX', array $options = []): SelectionResult
    {
        $tokens = [Str::literal($folder)];

        if ($options) {
            $tokens[] = Str::list(array_map(
                fn (OptionInterface $option) => $option->toImap(),
                $options,
            ));
        }

        $this->send($command, $tokens, $tag);

        $this->assertTaggedResponse($tag);

        return SelectionResult::fromResponses($this->result->responses());
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $folder = 'INBOX', array $items = ['MESSAGES', 'UNSEEN', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse
    {
        $this->send('STATUS', [
            Str::literal($folder),
            Str::list($items),
        ], $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->first(
            fn (UntaggedResponse $response) => $response->type()->is('STATUS')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): ResponseCollection
    {
        $this->send('CREATE', [Str::literal($folder)], $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LIST')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $folder): TaggedResponse
    {
        $this->send('DELETE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function rename(string $oldPath, string $newPath): TaggedResponse
    {
        $this->send('RENAME', Str::literal([$oldPath, $newPath]), tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(string $folder): TaggedResponse
    {
        $this->send('SUBSCRIBE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $folder): TaggedResponse
    {
        $this->send('UNSUBSCRIBE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function getQuota(string $root): UntaggedResponse
    {
        $this->send('GETQUOTA', [Str::literal($root)], tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('QUOTA')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getQuotaRoot(string $mailbox): ResponseCollection
    {
        $this->send('GETQUOTAROOT', [Str::literal($mailbox)], tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('QUOTAROOT') || $response->type()->is('QUOTA')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $reference = '', array|string $pattern = '*', array $selection = [], array $return = []): ResponseCollection
    {
        $tokens = $selection ? [Str::list($selection)] : [];

        $tokens[] = Str::literal($reference);

        array_push($tokens, ...(is_array($pattern) ? Str::literalList($pattern) : [Str::literal($pattern)]));

        if ($return) {
            $tokens[] = 'RETURN';
            $tokens[] = Str::list($return);
        }

        $this->send('LIST', $tokens, $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $folder, string $message, ?array $flags = null, ?DateTimeInterface $date = null): AppendResult
    {
        $tokens = [];

        $tokens[] = Str::literal($folder);

        if ($flags) {
            $tokens[] = Str::list($flags);
        }

        if ($date) {
            $tokens[] = Str::literal($date->format('d-M-Y H:i:s O'));
        }

        $tokens[] = ['{'.strlen($message).'}', $message];

        $this->send('APPEND', $tokens, tag: $tag);

        return AppendResult::fromResponse(
            $this->assertTaggedResponse($tag)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function copy(array|int|string $set, string $folder, ImapIdentifier $identifier = ImapIdentifier::Uid): TaggedResponse
    {
        $this->send($identifier === ImapIdentifier::Uid ? 'UID COPY' : 'COPY', [
            Str::set($set),
            Str::literal($folder),
        ], $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function move(array|int|string $set, string $folder, ImapIdentifier $identifier = ImapIdentifier::Uid): TaggedResponse
    {
        $this->send($identifier === ImapIdentifier::Uid ? 'UID MOVE' : 'MOVE', [
            Str::set($set),
            Str::literal($folder),
        ], $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|int|string $set, array|string $flags, ?string $mode = '+', bool $silent = true, ImapIdentifier $identifier = ImapIdentifier::Uid, StoreModifierInterface ...$modifiers): StoreResult
    {
        $tokens = [Str::set($set)];

        if ($modifiers) {
            $tokens[] = Str::list(array_map(
                fn (StoreModifierInterface $modifier) => $modifier->toImap(),
                $modifiers,
            ));
        }

        $tokens[] = $mode.'FLAGS'.($silent ? '.SILENT' : '');
        $tokens[] = Str::list((array) $flags);

        $this->send($identifier === ImapIdentifier::Uid ? 'UID STORE' : 'STORE', $tokens, $tag);

        $response = $this->taggedResponse($tag);
        $result = StoreResult::fromResponses(
            $this->result->responses(),
            $response,
            fn (FetchedMessageData $data, UntaggedResponse $response) => $this->matchesMessageSet($data, $response, $tokens[0], $identifier),
        );

        if ($response->status()->is('BAD') || ($response->failed() && empty($result->modified()))) {
            throw ImapCommandException::make($this->result->command(), $response);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $criteria, ?string $charset = null, ImapIdentifier $identifier = ImapIdentifier::Uid): UntaggedResponse
    {
        $tokens = $charset === null ? $criteria : ['CHARSET', Str::literal($charset), ...$criteria];

        $this->send($identifier === ImapIdentifier::Uid ? 'UID SEARCH' : 'SEARCH', $tokens, tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('SEARCH')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function sort(ImapSort $sort, array $criteria, string $charset = 'UTF-8', ImapIdentifier $identifier = ImapIdentifier::Uid): UntaggedResponse
    {
        $this->send($identifier === ImapIdentifier::Uid ? 'UID SORT' : 'SORT', ["({$sort->toImap()})", $charset, ...$criteria], tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('SORT')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): UntaggedResponse
    {
        $this->send('CAPABILITY', tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('CAPABILITY')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $parameters = null): UntaggedResponse
    {
        $values = [];

        foreach ($parameters ?? [] as $field => $value) {
            $values[] = $field;
            $values[] = $value;
        }

        $this->send('ID', $parameters === null ? ['NIL'] : Str::literalList($values), tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('ID')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(array|int|string|null $uids = null): ResponseCollection
    {
        $this->send(
            $uids === null ? 'EXPUNGE' : 'UID EXPUNGE',
            $uids === null ? [] : [Str::set($uids)],
            $tag,
        );

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): TaggedResponse
    {
        $this->send('NOOP', tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function idle(int $timeout): Generator
    {
        $this->stream->setTimeout($timeout);

        $this->send('IDLE', tag: $tag);

        $this->assertNextResponse(
            fn (Response $response) => $response instanceof ContinuationResponse,
            fn (ContinuationResponse $response) => true,
            fn (ContinuationResponse $response) => ImapCommandException::make(new ImapCommand('', 'IDLE'), $response),
        );

        while ($response = $this->nextReply()) {
            yield $response;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $this->write('DONE');

        // After sending the DONE continuation, the server must respond with a
        // tagged response to indicate that the IDLE command has been successfully
        // terminated and the server is ready to accept further commands.
        $this->assertNextResponse(
            fn (Response $response) => $response instanceof TaggedResponse,
            fn (TaggedResponse $response) => $response->successful(),
            fn (TaggedResponse $response) => ImapCommandException::make(new ImapCommand('', 'DONE'), $response),
        );
    }

    /**
     * Send an IMAP command.
     *
     * @param-out string $tag
     */
    public function send(string $name, array $tokens = [], ?string &$tag = null): void
    {
        if (! $tag) {
            $tag = 'TAG'.++$this->sequence;
        }

        $command = new ImapCommand($tag, $name, $tokens);

        // After every command, we'll overwrite any previous result
        // with the new command and its responses, so that we can
        // easily access the commands responses for assertion.
        $this->setResult(new Result($command));

        foreach ($command->compile() as $line) {
            $this->write($line->value, sensitive: in_array($name, ['LOGIN', 'AUTHENTICATE']));

            if ($line->synchronizing) {
                $this->assertContinuationResponse($command);
            }
        }
    }

    /**
     * Write data to the connected stream.
     */
    protected function write(string $data, bool $sensitive = false): void
    {
        if ($this->stream->fwrite($data."\r\n") === false) {
            throw new ImapStreamException('Failed to write data to stream');
        }

        $this->logger?->sent($sensitive ? '[redacted]' : $data);
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(array|int|string $set, array|string $items, ImapIdentifier $identifier = ImapIdentifier::Uid, FetchModifierInterface ...$modifiers): FetchResult
    {
        $prefix = ($identifier === ImapIdentifier::Uid) ? 'UID' : '';

        $items = array_merge(...array_map(fn (string $item) => match (strtoupper($item)) {
            'ALL' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE'],
            'FAST' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE'],
            'FULL' => ['FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE', 'BODY'],
            default => [$item],
        }, array_values((array) $items)));

        $tokens = [
            Str::set($set),
            Str::list($items),
        ];

        if ($modifiers) {
            $tokens[] = Str::list(array_map(
                fn (FetchModifierInterface $modifier) => $modifier->toImap(),
                $modifiers,
            ));
        }

        $this->send(trim($prefix.' FETCH'), $tokens, $tag);

        $this->assertTaggedResponse($tag);

        // Some IMAP servers can send unsolicited untagged responses along with fetch
        // requests. We'll need to filter these out so that we can return only the
        // responses that are relevant to the fetch command. For example:
        // >> TAG123 FETCH 123 (UID BODY[TEXT])
        // << * 123 FETCH (UID 456 BODY[TEXT] {14}\nHello, World!)
        // << * 123 FETCH (FLAGS (\Seen)) <-- Unsolicited response
        return FetchResult::fromResponses($this->result->responses(), function (FetchedMessageData $data, UntaggedResponse $response) use ($items, $identifier, $tokens) {
            if (! $this->matchesMessageSet($data, $response, $tokens[0], $identifier)) {
                return false;
            }

            foreach ($items as $item) {
                $key = str_replace(['BODY.PEEK[', 'BINARY.PEEK['], ['BODY[', 'BINARY['], strtoupper($item));
                $key = preg_replace('/<(\\d+)\\.\\d+>$/', '<$1>', $key);

                if (! $data->has($key)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Determine whether a fetched message belongs to the command's message set.
     */
    protected function matchesMessageSet(FetchedMessageData $data, UntaggedResponse $response, string $set, ImapIdentifier $identifier): bool
    {
        if ($identifier === ImapIdentifier::Uid && ! $data->has('UID')) {
            return false;
        }

        // Wildcards and saved searches require server state we do not have.
        // Do not discard potentially requested messages by guessing their bounds.
        if (str_contains($set, '*') || $set === '$') {
            return true;
        }

        $number = $identifier === ImapIdentifier::Uid ? $data->uid() : (int) $response->type()->value;

        foreach (explode(',', $set) as $sequence) {
            [$start, $end] = array_pad(explode(':', $sequence, 2), 2, $sequence);
            $start = (int) $start;
            $end = (int) $end;

            if ($number >= min($start, $end) && $number <= max($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the current result instance.
     */
    protected function setResult(Result $result): void
    {
        $this->result = $result;
    }

    /**
     * Set the current parser instance.
     */
    protected function setParser(ImapParser $parser): void
    {
        $this->parser = $parser;
    }

    /**
     * Create a new parser instance.
     */
    protected function newParser(StreamInterface $stream): ImapParser
    {
        return new ImapParser($this->newTokenizer($stream));
    }

    /**
     * Create a new tokenizer instance.
     */
    protected function newTokenizer(StreamInterface $stream): ImapTokenizer
    {
        return new ImapTokenizer($stream);
    }

    /**
     * Assert the next response is a successful tagged response.
     */
    protected function assertTaggedResponse(string $tag, ?callable $exception = null): TaggedResponse
    {
        /** @var TaggedResponse $response */
        $response = $this->assertNextResponse(
            fn (Response $response) => (
                $response instanceof TaggedResponse && $response->tag()->is($tag)
            ),
            fn (TaggedResponse $response) => (
                $response->successful()
            ),
            $exception ?? fn (TaggedResponse $response) => (
                ImapCommandException::make($this->result->command(), $response)
            ),
        );

        return $response;
    }

    /**
     * Get the tagged response for the given command without asserting its status.
     */
    protected function taggedResponse(string $tag): TaggedResponse
    {
        /** @var TaggedResponse $response */
        $response = $this->assertNextResponse(
            fn (Response $response) => $response instanceof TaggedResponse && $response->tag()->is($tag),
            fn (TaggedResponse $response) => true,
            fn (TaggedResponse $response) => ImapCommandException::make($this->result->command(), $response),
        );

        return $response;
    }

    /**
     * Assert the server is ready to receive literal data.
     */
    protected function assertContinuationResponse(ImapCommand $command): void
    {
        $this->assertNextResponse(
            fn (Response $response) => (
                $response instanceof ContinuationResponse || (
                    $response instanceof TaggedResponse
                    && $response->tag()->is($command->tag())
                )
            ),
            fn (Response $response) => $response instanceof ContinuationResponse,
            fn (Response $response) => ImapCommandException::make($command, $response),
        );
    }

    /**
     * Assert the next response matches the given filter and assertion.
     *
     * @template T of Response
     *
     * @param  callable(Response): bool  $filter
     * @param  callable(T): bool  $assertion
     * @param  callable(T): Throwable  $exception
     * @return T
     *
     * @throws ImapResponseException
     */
    protected function assertNextResponse(callable $filter, callable $assertion, callable $exception): Response
    {
        while ($response = $this->nextResponse($filter)) {
            if ($assertion($response)) {
                return $response;
            }

            throw $exception($response);
        }

        throw new ImapResponseException('No matching response found');
    }

    /**
     * Returns the next response matching the given filter.
     *
     * @template T of Response
     *
     * @param  callable(T): bool  $filter
     * @return T|null
     */
    protected function nextResponse(callable $filter): ?Response
    {
        if (! $this->parser) {
            throw new LogicException('No parser instance set');
        }

        while ($response = $this->nextReply()) {
            if (! $response instanceof Response) {
                continue;
            }

            $this->result?->addResponse($response);

            if ($filter($response)) {
                return $response;
            }
        }

        return null;
    }

    /**
     * Read the next reply from the stream.
     */
    protected function nextReply(): Data|Token|Response|null
    {
        if (! $reply = $this->parser->next()) {
            $meta = $this->stream->meta();

            throw match (true) {
                $meta['timed_out'] ?? false => new ImapConnectionTimedOutException('Stream timed out, no response'),
                $meta['eof'] ?? false => new ImapConnectionClosedException('Server closed the connection (EOF)'),
                default => new ImapConnectionFailedException('Unknown stream error. Metadata: '.json_encode($meta)),
            };
        }

        $this->logger?->received($reply);

        return $reply;
    }
}
