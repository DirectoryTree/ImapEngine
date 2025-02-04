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

```php
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;

$mailbox = new Mailbox([
    'port' => 993,
    'username' => '...',
    'password' => '...',
    'encryption' => 'ssl',
    'host' => 'imap.example.com',
]);

// Get all the mailbox's folders.
$folders = $mailbox->folders()->get();

// Get the first folder.
$folder = $folders->first();

// Get all the folder's messages.
$messages = $folder->messages()->get();

// Get all the folder's messages with their bodies.
$messages = $folder->messages()->withBody()->get();

// Get messages since a certain date.
$messages = $folder->messages()->since(now()->subDays(7))->get();

// Get messages with a certain subject.
$messages = $folder->messages()->subject('Hello World')->get();

// Listen for new messages.
$folder->idle(function (Message $message) {
    // Handle the new message.
});
```
