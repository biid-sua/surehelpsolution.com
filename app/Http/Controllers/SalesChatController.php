<?php

namespace App\Http\Controllers;

use App\Models\SalesChat;
use App\Services\Sales\SalesAssistant;
use App\Services\Sales\SalesChatDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The website sales chat's endpoints (D78). Same origin only; bodies are JSON sent as text/plain so
 * browsers send no preflight. The visitor holds a random token; only its hash is stored.
 */
class SalesChatController extends Controller
{
    public function __construct(private SalesChatDesk $desk) {}

    public function start(Request $request, SalesAssistant $assistant): JsonResponse
    {
        $body = $this->body($request);
        $existing = SalesChat::forToken($body['token'] ?? null);
        if (! $existing && $this->desk->chatsTodayFrom($request->ip()) >= (int) config('sales.ai.chats_per_ip_per_day', 10)) {
            return $this->json(['error' => 'Too many chats from your network today. Please use the contact form instead.'], 429);
        }
        [$chat, $token] = $this->desk->open($body['token'] ?? null, $body, $request->ip());
        $messages = $chat->messages()->oldest('id')->limit(200)->get()->all();

        return $this->json($this->desk->payload($chat, $messages, $token ?? ($body['token'] ?? null)) + [
            'ai' => $assistant->available($chat),
            'title' => 'SureHelp',
            'assistant' => config('sales.assistant', 'Ava'),
            'disclosure' => 'You\'re chatting with '.config('sales.assistant', 'Ava').', SureHelp\'s AI assistant. Ask for a person any time. '
                .'Please don\'t share payment details here.',
        ] + $this->debug($assistant->unavailableReason($chat)));
    }

    public function send(Request $request, SalesAssistant $assistant): JsonResponse
    {
        $body = $this->body($request);
        $chat = $this->chat($body);
        $this->throttle('sales-chat-msg:'.$chat->id, 8);
        $chat->forceFill(['visitor_seen_at' => now()])->save();

        $why = null;
        if (is_string($body['quick'] ?? null)) {
            $messages = $this->desk->quick($chat, $body['quick']);
        } else {
            $text = trim((string) ($body['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > (int) config('sales.max_message_chars', 600)) {
                return $this->json(['error' => 'Please write a message of up to '.config('sales.max_message_chars', 600).' characters.'], 422);
            }
            $why = $assistant->unavailableReason($chat);
            $messages = $this->desk->say($chat, $text);
            $why = $assistant->lastError ?? $why;
        }

        return $this->json($this->desk->payload($chat, $messages) + $this->debug($why));
    }

    public function lead(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $chat = $this->chat($body);
        // A filled hidden field: a bot. Pretend it worked.
        if (filled($body['website'] ?? null)) {
            return $this->json($this->desk->payload($chat, []));
        }
        $this->throttle('sales-chat-lead:'.$request->ip(), 6, 3600);

        try {
            $messages = $this->desk->saveContact($chat, $body);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors()], 422);
        }

        return $this->json($this->desk->payload($chat, $messages));
    }

    public function book(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $chat = $this->chat($body);
        $this->throttle('sales-chat-book:'.$chat->id, 6);

        return $this->json($this->desk->payload($chat, $this->desk->book($chat, (string) ($body['starts_at'] ?? ''))));
    }

    public function poll(Request $request): JsonResponse
    {
        $chat = $this->chat($request->query());
        $chat->forceFill(['visitor_seen_at' => now()])->save();
        $after = (string) $request->query('after', '');
        $afterId = $after !== '' ? $chat->messages()->where('ulid', $after)->value('id') : null;
        $messages = $chat->messages()->when($afterId, fn ($q) => $q->where('id', '>', $afterId))->oldest('id')->limit(100)->get();

        return $this->json([
            'messages' => $messages->map->toWidget()->all(),
            'staff_joined' => (bool) $chat->staff_user_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $this->sameOrigin($request);
        $data = json_decode($request->getContent() ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $input */
    private function chat(array $input): SalesChat
    {
        $this->sameOrigin(request());

        return SalesChat::forToken(is_string($input['token'] ?? null) ? $input['token'] : null)
            ?? abort($this->json(['error' => 'This chat has ended. Please reload the page.'], 404));
    }

    private function sameOrigin(Request $request): void
    {
        abort_unless(config('sales.enabled'), 404);
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $host = $origin ? parse_url($origin, PHP_URL_HOST) : null;
        $allowed = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), $request->getHost()]);
        abort_unless($host && in_array(strtolower($host), array_map('strtolower', $allowed), true), 403);
    }

    /**
     * With APP_DEBUG on (a developer's own install, never production), say why the AI didn't answer,
     * so keyword answers aren't mistaken for a broken chat. `php artisan sales-chat:check` explains more.
     *
     * @return array{debug?: string}
     */
    private function debug(?string $reason): array
    {
        return $reason !== null && config('app.debug') ? ['debug' => 'Developer note: '.$reason.' Run php artisan sales-chat:check for details.'] : [];
    }

    private function throttle(string $key, int $max, int $decay = 60): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            abort($this->json(['error' => 'You\'re sending messages very quickly. Please wait a moment.'], 429));
        }
        RateLimiter::hit($key, $decay);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }
}
