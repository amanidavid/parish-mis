<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;
require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$recipient = $argv[1] ?? 'amanidavid96@gmail.com';
$subject = $argv[2] ?? 'ZABA SMTP Test';
$body = $argv[3] ?? 'ZABA SMTP test email from parish-mis.';

echo "Starting mail test...\n";
echo 'Mailer: '.config('mail.default')."\n";
echo 'Host: '.config('mail.mailers.smtp.host')."\n";
echo 'Port: '.config('mail.mailers.smtp.port')."\n";
echo 'Username: '.config('mail.mailers.smtp.username')."\n";
echo 'From: '.config('mail.from.address')."\n";
echo 'To: '.$recipient."\n";
echo 'Subject: '.$subject."\n";

try {
    Mail::raw($body, function ($message) use ($recipient, $subject): void {
        $message->to($recipient)->subject($subject);
    });

    echo "RESULT: SUCCESS\n";
    echo "Message accepted by Laravel mail transport.\n";
} catch (Throwable $exception) {
    echo "RESULT: FAILED\n";
    echo 'Exception: '.$exception::class."\n";
    echo 'Message: '.$exception->getMessage()."\n";

    if ($exception->getPrevious() !== null) {
        echo 'Previous: '.$exception->getPrevious()::class."\n";
        echo 'Previous message: '.$exception->getPrevious()->getMessage()."\n";
    }
}
