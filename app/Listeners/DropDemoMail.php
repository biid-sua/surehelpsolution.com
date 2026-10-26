<?php

namespace App\Listeners;

use App\Support\DemoGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * While demo data is loaded (D94), mail to the demo's people never leaves: every demo address is at a
 * reserved example domain, so those recipients are dropped, and a message left with nobody isn't sent.
 * Without demo data, mail is untouched.
 */
class DropDemoMail
{
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        if (! DemoGuard::present()) {
            return null;
        }

        $keep = fn (array $addresses) => array_values(array_filter($addresses, fn (Address $a) => ! DemoGuard::reservedEmail($a->getAddress())));
        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());
        if (count($to) + count($cc) + count($bcc) === count($message->getTo()) + count($message->getCc()) + count($message->getBcc())) {
            return null;
        }
        if ($to === [] && $cc === [] && $bcc === []) {
            Log::debug('Demo data: email to a demo address not sent.', ['subject' => $message->getSubject()]);

            return false;
        }
        $message->to(...$to)->cc(...$cc)->bcc(...$bcc);

        return null;
    }
}
