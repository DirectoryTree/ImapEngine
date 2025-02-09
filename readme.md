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

## Requirements

PHP >= 8.1

## Installation

You can install the package via composer:

```bash
composer require directorytree/imapengine
```

## Usage

### Configuration

All default configuration options are shown below:

```php
$config = [
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
];
```

### Connection

To connect to a mailbox, create a new `Mailbox` instance with the above configuration options:

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

### Usage

#### Retrieving Folders

```php
// Get the mailbox's inbox folder.
$inbox = $mailbox->folders()->inbox();

// Get all the mailbox's folders.
$folders = $mailbox->folders()->get();

// Get all mailbox's folders matching a glob pattern.
$folders = $mailbox->folders()->get('*/Subfolder');

// Find a specific folder.
$folder = $mailbox->folders()->find('Folder Name');
```

#### Retrieving Messages

ImapEngine provides a fluent, chainable API for building advanced message search queries.

This allows you to combine various search criteria and options to retrieve exactly the messages you need.

For example, you can easily fetch all messages in a folder:

```php
$inbox = $mailbox->folders()->inbox();

// Get all message UIDs in the inbox.
$messages = $inbox->messages()->get();

// Get all messages in the inbox with various content.
$messages = $inbox->messages()
    ->withHeaders() // Enable fetching message headers.
    ->withFlags() // Enable fetching message flags.
    ->withBody() // Enable fetching message bodies.
    ->get();
```

> [!important]
> It's paramount to understand that the `get()` method fetches all messages in the folder, and will be slow for large mailboxes.
> When working with large mailboxes, consider using since(), and other criteria filters to limit the number of messages
> your IMAP server returns, along with pagination or chunking to avoid memory issues.
> 
> A typical approach when dealing with large mailboxes is to store all messages (either in a cache or DB)
> once, and then only fetch new messages since the last time the mailbox was checked.

#####  Filtering By Criteria

The MessageQuery supports many common IMAP search criteria. You can chain methods such as:

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

##### Fetching Additional Message Data

You can control what parts of the message are fetched by enabling or disabling them on the query builder:

**Message Headers:**
Use `withHeaders()` to include headers in the result, or `withoutHeaders()` to exclude them.

**Message Body:**
Use `withBody()` to fetch the full body content, or `withoutBody()` to skip it.

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

##### Message Pagination

You can paginate messages using the `paginate()` method. This method accepts the number of messages to display per page:

> [!important] 
> IMAP does not support native pagination, as you would expect from a SQL database. Instead,
> ImapEngine retrieves all UID's from the selected folder, takes the slice of the UID's 
> that corresponds to the current page, and fetches the messages for those UID's.

```php
// Paginate messages with 10 messages per page.
$paginatedMessages = $inbox->messages()->paginate(10);
```

##### Message Chunking

If you need to process a large number of messages without loading them all at once, you can use the chunk() method:

```php
$inbox->messages()->chunk(function ($chunk, $page) {
    foreach ($chunk as $message) {
        // Process each message in the current chunk.
    }
}, 20); // Process 20 messages per chunk.
```

##### Finding a Specific Message

You can retrieve a single message by its unique identifier using the `find()` method.

The method accepts an ID and an ImapFetchIdentifier (an enum) that specifies whether the ID is a UID or a message sequence number.

For example, to find a message by UID:

```php
use DirectoryTree\ImapEngine\Connection\ImapFetchIdentifier;

$message = $inbox->messages()->find(12345, ImapFetchIdentifier::Uid);
```

Or by message sequence number:

```php
$message = $inbox->messages()->find(1, ImapFetchIdentifier::MessageNumber);
```
