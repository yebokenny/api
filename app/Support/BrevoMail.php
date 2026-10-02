<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoMail
{
    public static function configured(): bool
    {
        $key = config('services.brevo.key');

        return is_string($key) && $key !== '';
    }

    public static function send(string $to, string $name, string $subject, string $html): bool
    {
        if (! self::configured()) {
            Log::warning('Brevo API key is missing. The message was not sent.', ['to' => $to, 'subject' => $subject]);

            return false;
        }

        $response = Http::timeout(20)
            ->withHeaders([
                'api-key' => config('services.brevo.key'),
                'accept' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => config('services.brevo.sender_name'),
                    'email' => config('services.brevo.sender_email'),
                ],
                'to' => [[
                    'email' => $to,
                    'name' => $name !== '' ? $name : $to,
                ]],
                'subject' => $subject,
                'htmlContent' => $html,
            ]);

        if (! $response->successful()) {
            Log::warning('Brevo rejected a message.', ['status' => $response->status(), 'to' => $to]);

            return false;
        }

        return true;
    }

    public static function html(string $heading, string $body): string
    {
        return '<div style="font-family:Segoe UI,sans-serif;color:#121212">'
            .'<h1 style="color:#008B39;font-size:20px">'.e($heading).'</h1>'
            .'<p style="line-height:1.5">'.nl2br(e($body)).'</p>'
            .'</div>';
    }
}
