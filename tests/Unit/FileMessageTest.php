<?php

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\FileMessage;

test('it throws an exception if the message is empty', function () {
    $message = new FileMessage('');

    $message->parse();
})->throws(RuntimeException::class);

test('it throws exception when uid is called', function () {
    $message = new FileMessage('test');

    $message->uid();
})->throws(BadMethodCallException::class);

test('it throws exception when flag is called', function () {
    $message = new FileMessage('test');

    $message->flag(ImapFlag::Seen, '+');
})->throws(BadMethodCallException::class);

test('it returns empty flags', function () {
    $message = new FileMessage('test');

    expect($message->flags())->toBe([]);
});

test('it handles invalid date header that returns null from getDateTime', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    To: "Jane Roe" <jane@example.com>
    Subject: Test Subject
    Date: Invalid Date String
    Message-ID: <unique-message-id@server.example.com>
    MIME-Version: 1.0
    Content-Type: text/plain; charset="UTF-8"

    Hello World
    EOT;

    $message = new FileMessage($contents);

    expect($message->date())->toBeNull();
});

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

test('it can parse In-Reply-To header', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    In-Reply-To: <some-other-message@server.example.com>
    Subject: In-Reply-To Check
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Check the in-reply-to header
    EOT;

    $message = new FileMessage($contents);

    expect($message->inReplyTo())->toBe(['some-other-message@server.example.com']);
});

test('it can parse In-Reply-To header with multiple values', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    In-Reply-To: <first-message@server.example.com> <second-message@server.example.com> <third-message@server.example.com>
    Subject: In-Reply-To Check
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Check the in-reply-to header
    EOT;

    $message = new FileMessage($contents);

    expect($message->inReplyTo())->toBe([
        'first-message@server.example.com',
        'second-message@server.example.com',
        'third-message@server.example.com',
    ]);
});

test('it returns empty array when In-Reply-To header is missing', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: No In-Reply-To
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    No in-reply-to header
    EOT;

    $message = new FileMessage($contents);

    expect($message->inReplyTo())->toBe([]);
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

test('it merges attachments from an inline forwarded message', function () {
    // Create a forwarded message that contains an attachment
    $forwardedMessage = <<<'EOT'
    From: "Original Sender" <original@example.com>
    To: "Original Recipient" <original-recipient@example.com>
    Subject: Original Message with Attachment
    Date: Tue, 18 Feb 2025 10:00:00 -0500
    Message-ID: <original-message@example.com>
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="ORIGINAL_BOUNDARY"

    --ORIGINAL_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    This is the original message with an attachment.

    --ORIGINAL_BOUNDARY
    Content-Type: application/pdf; name="original-document.pdf"
    Content-Disposition: attachment; filename="original-document.pdf"
    Content-Transfer-Encoding: base64

    JVBERi0xLjUKJeLjz9MKMyAwIG9iago8PC9MZW5ndGggNCAgIC9GaWx0ZXIvQXNjaWlIYXgg
    ICAgPj5zdHJlYW0Kc3R1ZmYKZW5kc3RyZWFtCmVuZG9iajAK
    --ORIGINAL_BOUNDARY--
    EOT;

    // Create the main message that forwards the above message inline (no filename/disposition on message/rfc822)
    $contents = <<<EOT
    From: "Forwarder" <forwarder@example.com>
    To: "Final Recipient" <final@example.com>
    Subject: Fwd: Original Message with Attachment
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Message-ID: <forwarded-message@example.com>
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="FORWARD_BOUNDARY"

    --FORWARD_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    Here is the forwarded message with its attachment.

    --FORWARD_BOUNDARY
    Content-Type: message/rfc822

    $forwardedMessage
    --FORWARD_BOUNDARY
    Content-Type: application/zip; name="additional-file.zip"
    Content-Disposition: attachment; filename="additional-file.zip"
    Content-Transfer-Encoding: base64

    UEsDBAoAAAAAAKxVVVMAAAAAAAAAAAAAAAAJAAAAdGVzdC50eHRQSwECFAAKAAAAAACs
    VVVTAAAAAAAAAAAAAAAACQAAAAAAAAAAAAAAAAAAAHRlc3QudHh0UEsFBgAAAAABAAEA
    NwAAAB8AAAAAAA==
    --FORWARD_BOUNDARY--
    EOT;

    $message = new FileMessage($contents);

    // Should find attachments from both the main message and the forwarded message
    $attachments = $message->attachments();

    expect($attachments)->toHaveCount(2);

    // First attachment should be from the forwarded message
    expect($attachments[0]->filename())->toBe('original-document.pdf');
    expect($attachments[0]->contentType())->toBe('application/pdf');

    // Second attachment should be from the main message
    expect($attachments[1]->filename())->toBe('additional-file.zip');
    expect($attachments[1]->contentType())->toBe('application/zip');
});

test('it does not merge attached .eml files', function () {
    // Forwarded message that itself contains an attachment
    $forwardedMessage = <<<'EOT'
    From: "Original Sender" <original@example.com>
    To: "Original Recipient" <original-recipient@example.com>
    Subject: Original Message with Attachment
    Date: Tue, 18 Feb 2025 10:00:00 -0500
    Message-ID: <original-message@example.com>
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="ORIGINAL_BOUNDARY"

    --ORIGINAL_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    This is the original message with an attachment.

    --ORIGINAL_BOUNDARY
    Content-Type: application/pdf; name="original-document.pdf"
    Content-Disposition: attachment; filename="original-document.pdf"
    Content-Transfer-Encoding: base64

    JVBERi0xLjUKJeLjz9MKMyAwIG9iago8PC9MZW5ndGggNCAgIC9GaWx0ZXIvQXNjaWlIYXgg
    ICAgPj5zdHJlYW0Kc3R1ZmYKZW5kc3RyZWFtCmVuZG9iajAK
    --ORIGINAL_BOUNDARY--
    EOT;

    // Top-level message that attaches the forwarded message as an .eml (should not merge)
    $contents = <<<EOT
    From: "Forwarder" <forwarder@example.com>
    To: "Final Recipient" <final@example.com>
    Subject: Fwd: Original Message with Attachment (as .eml)
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Message-ID: <forwarded-as-eml@example.com>
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="FORWARD_BOUNDARY"

    --FORWARD_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    Here is the forwarded message attached as an .eml file.

    --FORWARD_BOUNDARY
    Content-Type: message/rfc822; name="forwarded-message.eml"
    Content-Disposition: attachment; filename="forwarded-message.eml"

    $forwardedMessage
    --FORWARD_BOUNDARY
    Content-Type: application/zip; name="additional-file.zip"
    Content-Disposition: attachment; filename="additional-file.zip"
    Content-Transfer-Encoding: base64

    UEsDBAoAAAAAAKxVVVMAAAAAAAAAAAAAAAAJAAAAdGVzdC50eHRQSwECFAAKAAAAAACs
    VVVTAAAAAAAAAAAAAAAACQAAAAAAAAAAAAAAAAAAAHRlc3QudHh0UEsFBgAAAAABAAEA
    NwAAAB8AAAAAAA==
    --FORWARD_BOUNDARY--
    EOT;

    $message = new FileMessage($contents);

    $attachments = $message->attachments();

    // Expect exactly two top-level attachments: the attached .eml itself and the zip
    expect($attachments)->toHaveCount(2);

    // .eml is preserved as-is (no merging of its inner PDF)
    expect($attachments[0]->contentType())->toBe('message/rfc822');
    expect($attachments[0]->filename())->toBe('forwarded-message.eml');

    // The top-level zip attachment remains
    expect($attachments[1]->contentType())->toBe('application/zip');
    expect($attachments[1]->filename())->toBe('additional-file.zip');
});

test('it can handle multiple levels of forwarded messages with attachments', function () {
    // Create the deepest nested message with an attachment
    $deepestMessage = <<<'EOT'
    From: "Deep Sender" <deep@example.com>
    To: "Deep Recipient" <deep-recipient@example.com>
    Subject: Deep Message
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="DEEP_BOUNDARY"

    --DEEP_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    This is the deepest message.

    --DEEP_BOUNDARY
    Content-Type: text/plain; name="deep-file.txt"
    Content-Disposition: attachment; filename="deep-file.txt"

    Deep file content
    --DEEP_BOUNDARY--
    EOT;

    // Create a middle forwarded message that forwards the deep message
    $middleMessage = <<<EOT
    From: "Middle Sender" <middle@example.com>
    To: "Middle Recipient" <middle-recipient@example.com>
    Subject: Fwd: Deep Message
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="MIDDLE_BOUNDARY"

    --MIDDLE_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    Forwarding the deep message.

    --MIDDLE_BOUNDARY
    Content-Type: message/rfc822

    $deepestMessage
    --MIDDLE_BOUNDARY
    Content-Type: image/jpeg; name="middle-image.jpg"
    Content-Disposition: attachment; filename="middle-image.jpg"
    Content-Transfer-Encoding: base64

    /9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB
    --MIDDLE_BOUNDARY--
    EOT;

    // Create the top-level message
    $contents = <<<EOT
    From: "Top Sender" <top@example.com>
    To: "Top Recipient" <top-recipient@example.com>
    Subject: Fwd: Fwd: Deep Message
    MIME-Version: 1.0
    Content-Type: multipart/mixed; boundary="TOP_BOUNDARY"

    --TOP_BOUNDARY
    Content-Type: text/plain; charset="UTF-8"

    Multiple levels of forwarding.

    --TOP_BOUNDARY
    Content-Type: message/rfc822

    $middleMessage
    --TOP_BOUNDARY--
    EOT;

    $message = new FileMessage($contents);

    $attachments = $message->attachments();

    // Should find attachments from all levels: deep-file.txt and middle-image.jpg
    expect($attachments)->toHaveCount(2);

    // Verify we get attachments from nested messages
    $filenames = array_map(fn ($attachment) => $attachment->filename(), $attachments);
    expect($filenames)->toContain('deep-file.txt');
    expect($filenames)->toContain('middle-image.jpg');
});

test('it can determine if two messages are the same', function () {
    $contents1 = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: Test Subject
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Test content
    EOT;

    $contents2 = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: Test Subject
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Test content
    EOT;

    $contents3 = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: Different Subject
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Different content
    EOT;

    $message1 = new FileMessage($contents1);
    $message2 = new FileMessage($contents2);
    $message3 = new FileMessage($contents3);

    // Same content
    expect($message1->is($message2))->toBeTrue();

    // Different content
    expect($message1->is($message3))->toBeFalse();
});

test('it can determine size from contents', function () {
    $contents = <<<'EOT'
    From: "John Doe" <john@example.com>
    Subject: Test Subject
    Date: Wed, 19 Feb 2025 12:34:56 -0500
    Content-Type: text/plain; charset="UTF-8"

    Test content
    EOT;

    $message = new FileMessage($contents);

    expect($message->size())->toBe(strlen($contents));
});

test('it decodes base64 encoded from name', function () {
    $contents = <<<'EOT'
Return-Path: <from@example.com>
Delivered-To: to@example.com
Received: from mail.example.com
	by mail.example.com with LMTP id kB9HBDDVNWiAaAAABEj0/g
	for <to@example.com>; Tue, 27 May 2025 17:07:28 +0200
Received: from exmaple.com (exmaple.com [1.1.1.1])
	by mail.example.com (Postfix) with ESMTPS id E15722D749
	for <to@example.com>; Tue, 27 May 2025 17:07:27 +0200 (CEST)
DKIM-Signature: v=1; a=rsa-sha256; q=dns/txt; c=relaxed/relaxed; d=example.com;
	s=default; h=Content-Transfer-Encoding:Content-Type:Subject:MIME-Version:
	Message-Id:To:From:Date:Sender:Reply-To:Cc:Content-ID:Content-Description:
	Resent-Date:Resent-From:Resent-Sender:Resent-To:Resent-Cc:Resent-Message-ID:
	In-Reply-To:References:List-Id:List-Help:List-Unsubscribe:List-Subscribe:
	List-Post:List-Owner:List-Archive;
	bh=v6FVS3RZyBFj+9PZnaE1NGLHzvCYTYn6axfEJnK9FUQ=; b=ab91Cc8m1EOAClyNW066JVKy7p
	Kq6C6Kg84UvJ2CqW6He0BJY1SuK7s9HfzzQA+32N9YVn8HS9tNyynJ3MP0tUa/zf2YR4H7FRO/afz
	hpxcpJYVJGaH2rCgWzubZsKPJQN5wzd5g5pRiT4LbNchTr1XfwlVX3ycRxun+hGXNSNVJC8cSQuul
	tdZnKp69+ngVh0fFzzuywpj29Lmeh3hplsF3KlP23UMZq+5ZSp9WNEBPyB/dXG3XNuqqNQGqlHGoy
	xVOEHWA6r11YJxw+6W2vU3qVg6iW/Uy8g+bdp/AkULoqlsiBGR9of+/zozaobt7EkR0A7JTMKRUKw
	Xw5WUunA==;
Received: from 1.1.1.1.example.com ([1.1.1.1]:65442 helo=SERVER1)
	by exmaple.com with esmtpsa  (TLS1.2) tls TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384
	(Exim 4.98.1)
	(envelope-from <from@example.com>)
	id 1uJvto-000000000fL-1qpV;
	Tue, 27 May 2025 17:07:27 +0200
Date: Tue, 27 May 2025 17:07:27 +0200
From: "=?UTF-8?B?REFQIHwgU0VSVkVSMQ==?=" <from@example.com>
To: <to@example.com>
Message-Id: <6835d52f5e2e1.b6543809db00ba63b80c39bfcc81d88a@example.com>
MIME-Version: 1.0
Subject: =?UTF-8?B?W0RBUCB8IFNFUlZFUjFdICBDb250YWluZXIgdmF1bHR3YXJkZW4gaW4gQ29udGFpbmVyIE1hbmFnZXIgc3RvcHBlZCB1bmV4cGVjdGVkbHk=?=
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: 8bit
X-AntiAbuse: This header was added to track abuse, please include it with any abuse report
X-AntiAbuse: Primary Hostname - exmaple.com
X-AntiAbuse: Original Domain - example.com
X-AntiAbuse: Originator/Caller UID/GID - [47 12] / [47 12]
X-AntiAbuse: Sender Address Domain - example.com
X-Get-Message-Sender-Via: exmaple.com: authenticated_id: from@example.com
X-Authenticated-Sender: exmaple.com: from@example.com
X-Rspamd-Server: mail.example.com
X-Spamd-Result: default: False [-0.91 / 6.00];
	SUBJ_EXCESS_BASE64(1.50)[];
	FROM_EXCESS_BASE64(1.50)[];
	IP_REPUTATION_HAM(-1.32)[asn: 20857(-0.38), country: NL(-0.01), ip: 1.1.1.1(-0.93)];
	GENERIC_REPUTATION(-0.95)[-0.94652652518558];
	DKIM_REPUTATION(-0.92)[-0.91631218832379];
	SPF_REPUTATION_HAM(-0.89)[-0.88873676028191];
	R_DKIM_ALLOW(0.29)[example.com:s=default];
	R_SPF_ALLOW(-0.20)[+mx];
	MIME_HTML_ONLY(0.20)[];
	BAYES_HAM(-0.12)[66.55%];
	MX_GOOD(-0.01)[];
	TO_MATCH_ENVRCPT_SOME(0.00)[];
	FROM_HAS_DN(0.00)[];
	NEURAL_HAM(-0.00)[-1.000];
	HAS_X_GMSV(0.00)[from@example.com];
	DMARC_NA(0.00)[example.com];
	RCVD_VIA_SMTP_AUTH(0.00)[];
	MIME_TRACE(0.00)[0:~];
	MID_RHS_MATCH_FROM(0.00)[];
	DKIM_TRACE(0.00)[example.com:+];
	RCPT_COUNT_TWO(0.00)[2];
	TO_DN_NONE(0.00)[];
	HAS_X_AS(0.00)[from@example.com];
	HAS_X_ANTIABUSE(0.00)[];
	RCVD_COUNT_TWO(0.00)[2];
	FROM_EQ_ENVFROM(0.00)[];
	ASN(0.00)[asn:20857, ipnet:37.97.128.0/17, country:NL];
	RCVD_TLS_ALL(0.00)[]
X-Rspamd-Queue-Id: E15722D749
X-EsetId: 37303A29E962D55F607264

Container vaultwarden in Container Manager stopped unexpectedly. Please select vaultwarden on the <b>Container</b> page, click the <b>Details</b> button, and go to the <b>Log</b> tab for details.<BR><BR>From SERVER1<BR><BR><BR>
EOT;

    $message = new FileMessage($contents);

    expect($message->from()->email())->toBe('from@example.com');
    expect($message->from()->name())->toBe('DAP | SERVER1');
    expect($message->subject())->toBe('[DAP | SERVER1]  Container vaultwarden in Container Manager stopped unexpectedly');
});
