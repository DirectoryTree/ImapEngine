<p align="center">
<img src="https://github.com/directorytree/imapengine/blob/master/art/logo.svg" width="300">
</p>

<p align="center">Working with IMAP doesn't need to be hard.</p>

<p align="center">ImapEngine provides a simple API for managing mailboxes -- without the PHP extension.</p>

<p align="center">
<a href="https://github.com/directorytree/imapengine/actions"><img src="https://img.shields.io/github/actions/workflow/status/directorytree/imapengine/run-tests.yml?branch=master&style=flat-square"></a>
<a href="https://packagist.org/packages/directorytree/imapengine"><img src="https://img.shields.io/packagist/dt/directorytree/imapengine.svg?style=flat-square"></a>
<a href="https://packagist.org/packages/directorytree/imapengine"><img src="https://img.shields.io/packagist/v/directorytree/imapengine.svg?style=flat-square"></a>
<a href="https://packagist.org/packages/directorytree/imapengine"><img src="https://img.shields.io/packagist/l/directorytree/imapengine.svg?style=flat-square"></a>
</p>

## Index

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Connecting](#connecting)
  - [Retrieving Folders](#retrieving-folders)
  - [Retrieving Messages](#retrieving-messages)
  - [Interacting With Messages](#interacting-with-messages)
  - [Idling on Folders](#idling-on-folders)

## Requirements

PHP >= 8.1

## Installation

You can install the package via composer:

```bash
composer require directorytree/imapengine
```

## Usage

### Connecting

To connect to a mailbox, create a new `Mailbox` instance with the configuration options:

```php
$mailbox = new Mailbox([
    'port' => 993,
    'username' => '...',
    'password' => '...',
    'encryption' => 'ssl',
    'host' => 'imap.example.com',
]);
```

To connect using an OAuth token, pass the token as the password, and set the `authentication` method to `oauth`:

```php
$token = '...';

$mailbox = new Mailbox([
    'port' => 993,
    'username' => '...',
    'password' => $token,
    'encryption' => 'ssl',
    'authentication' => 'oauth',
    'host' => 'imap.example.com',
]);
```

To connect using plain (without encryption) or starttls, set the `encryption` option to `null`:

```php
$mailbox = new Mailbox([
    'port' => 143,
    'encryption' => null,
    'username' => '...',
    'password' => '...',
    'host' => 'imap.example.com',
]);
```

There are also many other configuration options available:

```php
$mailbox = new Mailbox([
    'port' => 993,
    'host' => '',
    'timeout' => 30,
    'debug' => false,
    'username' => '',
    'password' => '',
    'encryption' => 'ssl',
    'validate_cert' => true,
    'authentication' => 'plain',
    'proxy' => [
        'socket' => null,
        'username' => null,
        'password' => null,
        'request_fulluri' => false,
    ],
]);
```

#### Debugging

The `debug` configuration option controls the logging behavior during the IMAP connection process. It accepts the following values:

**Boolean:**
- `false` – (Default) Disables debugging output
- `true` – Enables debugging using an `EchoLogger`, which outputs debug messages to the console

```php
// No debug output.
$mailbox = new Mailbox([
    // ...
    'debug' => false,
]);

// Output debug messages to the console.
$mailbox = new Mailbox([
    // ...
    'debug' => true,
]);
```

**String:**
When set to a file path (e.g., `'/path/to/log/file.log'`), a `FileLogger` is instantiated to write debug messages to the specified file.

```php
// Output debug messages to a file.
$mailbox = new Mailbox([
    // ...
    'debug' => '/path/to/log/file.log',
]);
```

**Class Name:**
If provided with a fully-qualified class name (and the class exists), an instance of that logger will be created and used.

The class must implement `DirectoryTree\ImapEngine\Connection\Loggers\LoggerInterface`. 

```php
use DirectoryTree\ImapEngine\Connection\Loggers\LoggerInterface;

class CustomLogger implements LoggerInterface
{
    /**
     * Log when a message is sent.
     */
    public function sent(string $message): void
    {
        // Log the sent message...
    }

    /**
     * Log when a message is received.
     */
    public function received(string $message): void
    {
        // Log the received message...
    }
}
```

```php
$mailbox = new Mailbox([
    // ...
    'debug' => CustomLogger::class,
]);
```

Or, if you use [Spatie Ray](https://spatie.be/products/ray), you may use the built in `RayLogger`:

```php
use DirectoryTree\ImapEngine\Connection\Loggers\RayLogger;

$mailbox = new Mailbox([
    // ...
    'debug' => RayLogger::class,
]);
```

### Retrieving Folders

```php
// Get the mailbox's inbox folder.
$inbox = $mailbox->inbox();

// Get all the mailbox's folders.
$folders = $mailbox->folders()->get();

// Get all mailbox's folders matching a glob pattern.
$folders = $mailbox->folders()->get('*/Subfolder');

// Find a specific folder.
$folder = $mailbox->folders()->find('Folder Name');
```

### Retrieving Messages

ImapEngine provides a fluent, chainable API for building advanced message search queries.

This allows you to combine various search criteria and options to retrieve exactly the messages you need.

For example, you can easily fetch all messages in a folder:

```php
$inbox = $mailbox->folders()->inbox();

// Get all message UIDs in the inbox.
$messages = $inbox->messages()->get();

// Get all messages in the inbox with various content enabled.
$messages = $inbox->messages()
    ->withHeaders() // Enable fetching message headers.
    ->withFlags() // Enable fetching message flags.
    ->withBody() // Enable fetching message bodies (including attachments).
    ->get();
```

> [!important]
> It's paramount to understand that, without any query criteria specified, the `get()` method fetches all messages 
> in the folder by default, and will be slow for large mailboxes. When working with large mailboxes, consider
> using `since($date)`, and other criteria filters to limit the number of messages your IMAP server returns,
> along with pagination or chunking to avoid possible memory issues.
> 
> You may also consider restricting the parts of the message you fetch by omitting the use of `withHeaders()`, 
> `withFlags()`, and `withBody()`. This will reduce the amount of data fetched from the server, 
> and speed up your queries.
> 
> A typical approach when dealing with large mailboxes is to store all messages (either in a cache or DB)
> once, and then only fetch new messages since the last time the mailbox was checked.

####  Filtering By Criteria

The query builder supports many common IMAP search criteria. You can chain methods such as:

- `all()`
- `new()`
- `not()`
- `old()`
- `seen()`
- `recent()`
- `unseen()`
- `deleted()`
- `on($date)`
- `uid($uid)`
- `answered()`
- `cc($value)`
- `to($value)`
- `bcc($value)`
- `undeleted()`
- `unflagged()`
- `body($value)`
- `from($email)`
- `since($date)`
- `text($value)`
- `unanswered()`
- `before($date)`
- `flagged($value)`
- `keyword($value)`
- `subject($value)`
- `unkeyword($value)`
- `messageId($messageId)`
- `inReplyTo($messageId)`
- `language($countryCode)`
- `header($header, $value)`

For example, to retrieve messages from the last 7 days with a specific subject:

```php
$messages = $inbox->messages()
    ->since(now()->subDays(7))
    ->subject('Hello World')
    ->get();
```

If a method doesn't exist for a specific search criteria, you can use the `where()` method to add custom criteria:

```php
$messages = $inbox->messages()
    ->where('CRITERIA', 'value')
    ->get();
```

#### Fetching Additional Message Data

By default, ImapEngine only fetches UIDs. To fetch additional message data, you have to enable it explicitly.

**Message Headers:**
Use `withHeaders()` to include headers in the result, or `withoutHeaders()` to exclude them.

**Message Body:**
Use `withBody()` to fetch the full body content, or `withoutBody()` to skip it.

> [!important]
> The `withBody()` method fetches the full body content of the message, including attachments.
> Keep this in mind when fetching messages, as it can be slow for large messages.

**Message Flags:**
Use `withFlags()` to retrieve flags, or `withoutFlags()` to omit them.

For example, to fetch messages with both their bodies, headers, and flags:

```php
$messages = $inbox->messages()
    ->withHeaders()
    ->withFlags()
    ->withBody()
    ->get();
```

The less data you fetch, the faster your query will be. Only fetch the data you need.

#### Message Pagination

You can paginate messages using the `paginate()` method. This method accepts the number of messages to display per page:

> [!important] 
> IMAP does not support native pagination, as you would typically expect, like a SQL database. Instead,
> ImapEngine retrieves all UID's from the selected folder, takes the slice of the UID's 
> that corresponds to the current page, and fetches the requested email message parts specifically for those UID's.

```php
// Paginate messages with 10 messages per page.
$paginatedMessages = $inbox->messages()->paginate(10);
```

#### Message Chunking

If you need to process a large number of messages without loading them all at once, you can use the chunk() method:

```php
$inbox->messages()->chunk(function ($chunk, $page) {
    /** @var \DirectoryTree\ImapEngine\Message $message */
    foreach ($chunk as $message) {
        // Process each message in the current chunk.
    }
}, chunkSize: 20); // Process 20 messages per chunk.
```

You may also use the `each()` method to iterate over messages in every chunk:

```php
use DirectoryTree\ImapEngine\Message;

$inbox->messages()->each(function (Message $message) {
    // Do something with the message.
}, chunkSize: 20);
```

#### Finding a Specific Message

You can retrieve a single message by its unique identifier using the `find()` method.

The method accepts an ID and an ImapFetchIdentifier (an enum) that specifies whether the ID is a UID or a message sequence number.

For example, to find a message by UID:

```php
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;

$message = $inbox->messages()->find(12345, ImapFetchIdentifier::Uid);
```

Or by message sequence number:

```php
$message = $inbox->messages()->find(1, ImapFetchIdentifier::MessageNumber);
```

### Interacting With Messages

Once you retrieve messages from a folder using methods like `$inbox->messages()->get()`, you'll receive instances of the `DirectoryTree\ImapEngine\Message` class.

This class offers a rich set of helper methods for interacting with individual emails, making it easy to inspect, modify, and manipulate messages.

#### Retrieving Message Information

The `Message` class provides several methods to access basic properties:

**UID and Flags**
- `uid()`: Returns the unique identifier (UID) of the message.
- `flags()`: Returns an array of flags currently set on the message.

**Headers and Contents**
- `headers()`: Returns the raw headers as a string.
- `contents()`: Returns the raw message content.
- `hasHeaders()` / `hasContents()`: Determine whether the message has headers or contents.

**Metadata**
- `subject()`: Returns the subject of the message.
- `date()`: Returns the message’s date as a Carbon instance (if available).
- `messageId()`: Retrieves the Message-ID header (globally unique identifier for the message).

#### Address Handling

To conveniently work with email addresses, the `Message` class includes methods that return addresses as instances of the `DirectoryTree\ImapEngine\Address` class:

- `from()`: The sender’s address.
- `sender()`: The actual sender (if different from "from").
- `replyTo()`: The reply-to address.
- `inReplyTo()`: The In-Reply-To address.
- `to()`: An array of recipient addresses.
- `cc()`: An array of CC addresses.
- `bcc()`: An array of BCC addresses.

#### Content Retrieval

For accessing the message content in different formats:

- `html()`: Returns the HTML version of the message (if available).
- `text()`: Returns the plain text version of the message (if available).

#### Attachment Handling

Messages that include attachments can be inspected with:

- `attachments()`: Returns an array of `Attachment` objects.
- `hasAttachments()`: Checks if the message contains any attachments.
- `attachmentCount()`: Returns the number of attachments in the message.

#### Flag Operations

The class also provides methods to modify message flags, which help you manage the state of a message:

**Marking as Seen/Unseen**
- `markSeen($expunge = true)`: Marks the message as read.
- `unmarkSeen($expunge = true)`: Marks the message as unread.
- *Aliases:* `markRead()` and `markUnread()`.

**Other Flags**
- `markAnswered()` / `unmarkAnswered()`
- `markFlagged()` / `unmarkFlagged()`
- `markDeleted()` / `unmarkDeleted()`
- `markDraft()` / `unmarkDraft()`
- `markRecent()` / `unmarkRecent()`

All these methods work by invoking the underlying IMAP `STORE` command (with the appropriate flag and operation), and optionally expunging the folder afterward.

#### Message Manipulation

Beyond just flagging, you can move or copy messages between folders, or even delete them:

- `copy(string $folder, bool $expunge = true)`: Copies the message to the specified folder.
- `move(string $folder, bool $expunge = true)`: Moves the message to the specified folder.
- `delete(bool $expunge = true)`: Marks the message as deleted and, if desired, expunges it from the folder.

#### Example: Interacting with a Retrieved Message

```php
// Retrieve the first message from the inbox.
$message = $inbox->messages()->first();

// Get the message UID.
$message->uid();

// Get the message subject.
$message->subject();

// Get the message sender.
$message->from(); // Address

// Get the message date.
$message->date(); // Carbon\Carbon

// Get the message's attachments.
foreach ($message->attachments() as $attachment) {
    // Get the attachment's filename.
    $attachment->filename();
    
    // Get the attachment's content type.
    $attachment->contentType();
    
    // Get the attachment's contents.
    $attachment->contents();
    
    // Get the attachment's extension.
    $extension = $attachment->extension();
    
    // Save the attachment to a local file.
    $attachment->save("/path/to/save/attachment.$extension");
}

// Mark the message as read.
$message->markSeen();

// Move the message to an "Archive" folder.
$message->move('Archive');

// Delete the message.
$message->delete();
```

### Idling on Folders

ImapEngine supports real-time monitoring of folders via the IMAP IDLE command. 

This lets you listen for new messages as they arrive without polling repeatedly.

> [!important]
> The `idle()` method is fully blocking (as in, it enters an infinite loop), so consider 
> running it in a background process or a worker when used in a web application.

```php
use DirectoryTree\ImapEngine\Message;

// Get the inbox folder.
$inbox = $mailbox->inbox();

// Begin idling on the inbox folder. The callback will
// be executed whenever a new message is received. 
$inbox->idle(function (Message $message) {
    // Do something with the newly received message.
}, timeout: 300); // Optional timeout in seconds.
```

> [!important]
> Messages received in idle will always be fetched with all their content (flags, headers, and body with attachments).
