<p align="center">
<img src="https://github.com/directorytree/imapengine/blob/master/art/logo.svg" width="300">
</p>

<p align="center">Working with IMAP doesn't need to be hard.</p>

<p align="center">ImapEngine provides a simple API for managing mailboxes -- without using the PHP extension.</p>

<p align="center">
<a href="https://github.com/directorytree/imapengine/actions"><img src="https://img.shields.io/github/actions/workflow/status/directorytree/imapengine/run-tests.yml?branch=master&style=flat-square"></a>
<a href="https://packagist.org/packages/DirectoryTree/ImapEngine"><img src="https://img.shields.io/packagist/dt/DirectoryTree/ImapEngine.svg?style=flat-square"></a>
<a href="https://packagist.org/packages/DirectoryTree/ImapEngine"><img src="https://img.shields.io/packagist/v/DirectoryTree/ImapEngine.svg?style=flat-square"></a>
<a href="https://packagist.org/packages/DirectoryTree/ImapEngine"><img src="https://img.shields.io/github/license/DirectoryTree/ImapEngine?style=flat-square"/></a>
</p>

<p align="center">
  <a href="https://imapengine.com">View Documentation</a>
</p>

### Partial body fetching

Fetch a byte range of a message section without downloading the whole section:

```php
$messages = $folder->messages()
    ->with(MessageData::text()->partial(0, 4096)->peek())
    ->get();

$preview = $messages->first()->data()->get('BODY[TEXT]<0>');

$chunk = $message->bodyPart('2', offset: 65536, length: 65536);
```

Offsets are zero-based and refer to transfer-encoded bytes. The server may return
fewer bytes at the end of a section, or an empty string beyond its end. A missing
message or section returns `null`. Partial data does not replace the complete body.

Attachments obtained through `attachments(fetch: true)` download in 64 KiB chunks
as their streams are read. Base64 and quoted-printable decoding continues across
chunk boundaries. Decoded content is cached in a temporary stream that spills to
disk beyond 2 MiB, allowing seeks and repeated reads without fetching again.

```php
$attachment = $message->attachments(fetch: true)[0];
$attachment->save('/path/to/attachment.pdf');
```

`save()` copies stream chunks directly to the destination. Calling `contents()` or
casting a stream to a string still allocates the requested contents in memory.
The decoded stream size is unknown (`null`) until the section has been fully read.
