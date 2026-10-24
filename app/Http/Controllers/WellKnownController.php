<?php

namespace App\Http\Controllers;

/** /.well-known/security.txt (RFC 9116): where to send a vulnerability report. Hidden until a contact is set. */
class WellKnownController extends Controller
{
    public function securityTxt()
    {
        $contact = trim((string) config('panel.security.contact'));
        abort_unless($contact !== '' && preg_match('#^(mailto:|https://)#i', $contact), 404);

        $body = "Contact: {$contact}\n"
            .'Expires: '.now()->addYear()->startOfDay()->format('Y-m-d\TH:i:s.000\Z')."\n"
            .'Canonical: '.rtrim((string) config('app.url'), '/')."/.well-known/security.txt\n"
            ."Preferred-Languages: en, de\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
