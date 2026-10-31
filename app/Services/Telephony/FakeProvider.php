<?php

namespace App\Services\Telephony;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Calls without a vendor (D60): for local work, demos and tests. Lines are "bought" instantly
 * with 555 numbers and calls are simulated from the agent softphone and texts are only recorded. Webhooks are only accepted
 * outside production, and everything the app asked for is remembered so tests can check it.
 */
class FakeProvider implements TelephonyProvider
{
    /** @var list<array{call: string, url: string}> */
    public array $redirects = [];

    /** @var list<string> */
    public array $released = [];

    /** @var list<array{from: string, to: string, body: string, sid: string}> */
    public array $texts = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function usesBrowserSdk(): bool
    {
        return false;
    }

    public function accessToken(string $identity, int $ttlSeconds = 3600): string
    {
        return 'fake.'.base64_encode($identity);
    }

    public function isGenuine(Request $request): bool
    {
        return ! app()->isProduction();
    }

    public function availableNumbers(string $areaCode): array
    {
        $area = preg_match('/^\d{3}$/', $areaCode) ? $areaCode : '555';

        return array_map(fn (int $i) => ['number' => '+1'.$area.'555'.str_pad((string) (100 + $i * 7), 4, '0', STR_PAD_LEFT), 'locality' => 'Demo', 'region' => null], range(1, 6));
    }

    public function buyNumber(string $number, array $urls): array
    {
        return ['sid' => 'PNfake'.Str::lower(Str::random(26)), 'number' => $number];
    }

    public function connectNumber(string $sid, array $urls): string
    {
        return '+1555555'.str_pad((string) (crc32($sid) % 10000), 4, '0', STR_PAD_LEFT);
    }

    public function releaseNumber(string $sid): void
    {
        $this->released[] = $sid;
    }

    public function redirectCall(string $callSid, string $url): void
    {
        $this->redirects[] = ['call' => $callSid, 'url' => $url];
    }

    /** @var list<array{from: string, to: string, url: string, sid: string}> calls placed (demo: nobody's phone rings) */
    public array $calls = [];

    public function placeCall(string $from, string $to, string $url, string $statusUrl): string
    {
        $sid = 'CAfake'.Str::lower(Str::random(26));
        $this->calls[] = ['from' => $from, 'to' => $to, 'url' => $url, 'sid' => $sid];

        return $sid;
    }

    public function sendSms(string $from, string $to, string $body, string $statusUrl): string
    {
        $sid = 'SMfake'.Str::lower(Str::random(26));
        $this->texts[] = ['from' => $from, 'to' => $to, 'body' => $body, 'sid' => $sid];

        return $sid;
    }

    /** @var array<string, array{body: string, type: string}> media the demo phone "received", by URL */
    public array $media = [];

    public function downloadMedia(string $url): ?array
    {
        return $this->media[$url] ?? null;
    }

    public function recordingAudio(string $recordingSid): ?string
    {
        return null;
    }
}
