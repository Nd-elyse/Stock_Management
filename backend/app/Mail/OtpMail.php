<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OtpMail extends Mailable
{
    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function build(): self
    {
        return $this
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Your GarageManager verification code')
            ->html('<p>Your GarageManager verification code is <strong>' . $this->code . '</strong>.</p><p>It expires in ' . $this->ttlMinutes . ' minutes.</p>');
    }
}
