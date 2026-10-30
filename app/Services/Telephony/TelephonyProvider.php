<?php

namespace App\Services\Telephony;

use App\Exceptions\TelephonyUnavailable;
use Illuminate\Http\Request;

/**
 * One telephony vendor (D10). Routing, presence and call records are vendor-neutral; only this
 * sits between them and the vendor's API. Call instructions are TwiML (see TwiML), which the
 * fake provider also speaks so local runs exercise the same code.
 */
interface TelephonyProvider
{
    /** 'twilio', 'fake' — stored on numbers and calls. */
    public function name(): string;

    /** Credentials are set, so lines can be bought and calls taken. */
    public function isConfigured(): bool;

    /** Calls ring a real browser phone (the vendor's SDK), not the in-app simulator. */
    public function usesBrowserSdk(): bool;

    /** A short-lived token that lets this browser receive calls as `$identity`. */
    public function accessToken(string $identity, int $ttlSeconds = 3600): string;

    /** The request really comes from the vendor (signed with our credentials). */
    public function isGenuine(Request $request): bool;

    /**
     * Numbers that can be bought, e.g. in an area code.
     *
     * @return list<array{number: string, locality: ?string, region: ?string}>
     *
     * @throws TelephonyUnavailable
     */
    public function availableNumbers(string $areaCode): array;

    /**
     * Buy a number and point its calls at us.
     *
     * @param  array{voice: string, status: string, sms?: string}  $urls
     * @return array{sid: string, number: string}
     *
     * @throws TelephonyUnavailable
     */
    public function buyNumber(string $number, array $urls): array;

    /**
     * Point a number already in the account at us; returns its E.164 number.
     *
     * @param  array{voice: string, status: string, sms?: string}  $urls
     *
     * @throws TelephonyUnavailable
     */
    public function connectNumber(string $sid, array $urls): string;

    /** @throws TelephonyUnavailable */
    public function releaseNumber(string $sid): void;

    /**
     * Send a live call to new instructions (used to transfer it).
     *
     * @throws TelephonyUnavailable
     */
    public function redirectCall(string $callSid, string $url): void;

    /**
     * Ring a phone from one of our numbers; when it's answered, the call follows the instructions
     * at $url (D109: a new lead rings the owner, who presses 1 to be connected). Returns the call id.
     *
     * @throws TelephonyUnavailable
     */
    public function placeCall(string $from, string $to, string $url, string $statusUrl): string;

    /**
     * Send a text from one of our numbers (D61); returns the vendor's message id.
     *
     * @throws TelephonyUnavailable
     */
    public function sendSms(string $from, string $to, string $body, string $statusUrl): string;

    /**
     * A picture someone texted (MMS), from the vendor's media URL.
     *
     * @return array{body: string, type: string}|null
     */
    public function downloadMedia(string $url): ?array;

    /** The recording as MP3, or null when it no longer exists. */
    public function recordingAudio(string $recordingSid): ?string;
}
