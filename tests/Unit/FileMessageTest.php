<?php

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\FileMessage;

test('it throws an exception if the message is empty', function () {
    $message = new FileMessage('');

    $message->parse();
})->throws(RuntimeException::class);

test('it can parse a standard EML message and read basic headers', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    To: "Jane Roe" <jane@example.com>
    Subject: Test Subject
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Message-ID: <unique-message-id@server.example.com>
    MIME-Version: 1.0
    Content-Type: text/plain; charset="UTF-8"
    
    Hello World
    EOT;

    $message = new FileMessage($contents);

    expect($message->subject())->toBe('Test Subject');
    expect($message->messageId())->toBe('unique-message-id@server.example.com');
    expect($message->date()->toDateTimeString())->toBe('2025-02-19 12:34:56');
    expect($message->from()->email())->toBe('john@example.com');

    expect($message->from()->name())->toBe('John Doe');
    expect($message->to())->toHaveCount(1);
    expect($message->to()[0]->email())->toBe('jane@example.com');
    expect($message->to()[0]->name())->toBe('Jane Roe');
    expect($message->text())->toBe('Hello World');
});

test('it can parse HTML content in a multipart message', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    To: "Jane Roe" <jane@example.com>
    Subject: HTML Email
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Message-ID: <html-message@server.example.com>
    MIME-Version: 1.0
    Content-Type: multipart/alternative;
     boundary="----BOUNDARY-ID----"
    
    ------BOUNDARY-ID----
    Content-Type: text/plain; charset="UTF-8"
    
    Hello Plain
    
    ------BOUNDARY-ID----
    Content-Type: text/html; charset="UTF-8"
    
    <html><body><p>Hello <strong>HTML</strong></p></body></html>
    ------BOUNDARY-ID------
    EOT;

    $message = new FileMessage($contents);

    expect($message->text())->toBe("Hello Plain\n");
    expect($message->html())->toBe('<html><body><p>Hello <strong>HTML</strong></p></body></html>');
});

test('it can parse attachments', function () {
    // Simple example with one attachment
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    To: "Jane Roe" <jane@example.com>
    Subject: EML with Attachment
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Message-ID: <attachment-message@server.example.com>
    MIME-Version: 1.0
    Content-Type: multipart/mixed;
     boundary="----BOUNDARY-ID----"
    
    ------BOUNDARY-ID----
    Content-Type: text/plain; charset="UTF-8"
    
    Hello with Attachment
    
    ------BOUNDARY-ID----
    Content-Type: application/pdf; name="file.pdf"
    Content-Disposition: attachment; filename="file.pdf"
    Content-Transfer-Encoding: base64
    
    JVBERi0xLjUKJeLjz9MKMyAwIG9iago8PC9MZW5ndGggNCAgIC9GaWx0ZXIvQXNjaWlIYXgg
    ICAgPj5zdHJlYW0Kc3R1ZmYKZW5kc3RyZWFtCmVuZG9iajAK
    ------BOUNDARY-ID------
    EOT;

    $message = new FileMessage($contents);

    expect($message->hasAttachments())->toBeTrue();
    expect($message->attachmentCount())->toBe(1);

    $attachments = $message->attachments();
    expect($attachments)->toHaveCount(1);

    $attachment = $attachments[0];
    expect($attachment)->toBeInstanceOf(Attachment::class);
    expect($attachment->filename())->toBe('file.pdf');
    expect($attachment->contentType())->toBe('application/pdf');
});

test('it recognizes when there are no attachments', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    To: "Jane Roe" <jane@example.com>
    Subject: No Attachments
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"
    
    Just a plain text email without attachments.
    EOT;

    $message = new FileMessage($contents);

    expect($message->hasAttachments())->toBeFalse();
    expect($message->attachmentCount())->toBe(0);
    expect($message->attachments())->toBe([]);
});

test('it can parse other header fields like IN-REPLY-TO', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    In-Reply-To: <some-other-message@server.example.com>, <another-message@server.example.com>
    Subject: In-Reply-To Check
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"
    
    Check the in-reply-to header
    EOT;

    $message = new FileMessage($contents);

    $address = $message->inReplyTo();
    expect($address)->not->toBeNull();
    expect($address->email())->toBe('some-other-message@server.example.com');
});

test('it can be cast to a string via __toString()', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: Stringable Test
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"
    
    Testing __toString
    EOT;

    $message = new FileMessage($contents);

    expect((string) $message)->toBe($contents);
});

test('it can read inline attachment', function () {
    $contents = <<<'EOT'
    From: "Example Sender" <sender@example.com>
    To: "Example Recipient" <recipient@example.com>
    Subject: Test Email With Inline Image
    MIME-Version: 1.0
    Content-Type: multipart/related; boundary="BOUNDARY_STRING"
    
    --BOUNDARY_STRING
    Content-Type: text/html; charset=UTF-8
    
    <html>
    <body>
    <p>This is a test email with an inline image:</p>
    <img src="cid:inline-image-id" alt="Inline Image" />
    </body>
    </html>
    
    --BOUNDARY_STRING
    Content-Type: image/png; name="inline_image.png"
    Content-Transfer-Encoding: base64
    Content-ID: <inline-image-id>
    Content-Disposition: inline; filename="inline_image.png"
    
    iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8
    z8BQDwABAgEA0xzY2QAAAABJRU5ErkJggg==
    
    --BOUNDARY_STRING--
    EOT;

    $message = new FileMessage($contents);

    $attachments = $message->attachments();

    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->contentType())->toBe('image/png');
    expect($attachments[0]->contentId())->toBe('inline-image-id');
    expect($attachments[0]->filename())->toBe('inline_image.png');
});
