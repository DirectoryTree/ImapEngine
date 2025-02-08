<p align="center">
<img src="https://github.com/directorytree/imapengine/blob/master/art/logo.svg" width="300">
</p>

<p align="center">Working with IMAP doesn't need to be hard.</p>

<p align="center">ImapEngine provides a simple API for managing mailboxes, messages, and attachments, without the extension.</p>

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
$folders = $mailbox->folders('*/Subfolder')->get();

// Find a specific folder.
$folder = $mailbox->folders()->find('Folder Name');
```

#### Retrieving Messages

```php
$inbox = $mailbox->folders()->inbox();

// Get all the folder's messages.
$messages = $inbox->messages()->get();

// Get all the folder's messages with their bodies.
$messages = $inbox->messages()->withBody()->get();

// Get messages since a certain date.
$messages = $inbox->messages()->since(now()->subDays(7))->get();

// Get messages with a certain subject.
$messages = $inbox->messages()->subject('Hello World')->get();

// Listen for new messages on the inbox.
$inbox->idle(function (Message $message) {
    // Handle the new message.
});
```
