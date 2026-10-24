<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Mail;

class WelcomeMailer
{
    public const DEFAULT_TEMPLATE = "Hello,\n\nYour new mailbox {address} is ready.\n\nWebmail: {webmail}\nIncoming (IMAP): {imap_host}, port {imap_port}, SSL/TLS\nIncoming (POP3): {imap_host}, port {pop_port}, SSL/TLS\nOutgoing (SMTP): {smtp_host}, port {smtp_port}, SSL/TLS (or {smtp_starttls_port} with STARTTLS)\nUsername: {address}\n\nYour password is sent to you separately.";

    public function __construct(private CpanelClient $cpanel)
    {
    }

    /** Client settings for a domain; also shown on the settings page. */
    public function settings(Domain $domain, string $address): array
    {
        $c = config('cpanel.client');
        $mail = 'mail.'.$domain->name;

        return [
            'address' => $address,
            'webmail' => $this->cpanel->webmailUrl(),
            'imap_host' => $mail, 'imap_port' => $c['imap_port'],
            'pop_port' => $c['pop_port'],
            'smtp_host' => $mail, 'smtp_port' => $c['smtp_port'], 'smtp_starttls_port' => $c['smtp_starttls_port'],
        ];
    }

    public function render(Domain $domain, string $address): string
    {
        $text = $domain->welcome_template ?: self::DEFAULT_TEMPLATE;

        foreach ($this->settings($domain, $address) as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }

    /** Send client settings (never the password) to an address the new user can read. */
    public function send(Domain $domain, string $address, string $to): void
    {
        $body = $this->render($domain, $address);

        Mail::raw($body, fn ($m) => $m->to($to)->subject("Your new mailbox {$address}"));
    }
}
