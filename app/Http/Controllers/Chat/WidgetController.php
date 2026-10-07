<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Inbox\ReceiveMessage;
use App\Enums\InboxChannel;
use App\Http\Controllers\Controller;
use App\Models\AiAssistant;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\Contracts\AiProvider;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The website chat widget's public endpoints (D37). No sign-in: a visitor holds a random token
 * (only its hash is stored), each business's widget may be limited to its own websites, and
 * everything is rate limited. Requests are "simple" (text/plain JSON), so browsers send no preflight.
 */
class WidgetController extends Controller
{
    public function script(): Response
    {
        return response(view('chat.widget-js')->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function options(Request $request, string $key): Response
    {
        $widget = $this->widget($key);

        return $this->cors(response('', 204), $request, $widget)
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type')
            ->header('Access-Control-Max-Age', '3600');
    }

    public function config(Request $request, AiProvider $ai, string $key): JsonResponse
    {
        $widget = $this->allowed($request, $key);
        $organization = $widget->organization;
        $assistant = AiAssistant::for($organization);
        $aiOn = $ai->isConfigured() && $assistant->modeFor(InboxChannel::WebChat) === 'auto';
        $business = BusinessProfile::query()->forOrganization($organization)->value('display_name') ?? $organization->name;

        $phone = $widget->offers('call') ? Phone::normalize(BusinessProfile::query()->forOrganization($organization)->value('phone')) : null;

        return $this->cors(response()->json([
            'features' => $widget->enabledFeatures(),
            'phone' => $phone ? ['tel' => $phone, 'label' => Phone::display($phone)] : null,
            'booking_confirm' => $widget->offers('booking') ? $widget->bookings_need_confirmation : null,
            'business' => $business,
            'title' => $widget->title,
            'greeting' => $widget->greeting ?: "Hi! How can we help? We'll reply right here.",
            'color' => $widget->color,
            'assistant' => $aiOn ? $assistant->name : null,
            'disclosure' => $aiOn ? "You're chatting with {$assistant->name}, {$business}'s AI assistant. Ask for a person any time." : null,
        ]), $request, $widget);
    }

    public function send(Request $request, ReceiveMessage $receive, string $key): JsonResponse
    {
        $widget = $this->allowed($request, $key);
        abort_unless($widget->offers('chat'), 404);
        $data = $this->payload($request);

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 2000) {
            return $this->cors(response()->json(['message' => 'Write a message of up to 2,000 characters.'], 422), $request, $widget);
        }
        $email = filter_var($data['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;

        $token = $this->validToken($data['token'] ?? null) ?? Str::random(48);
        $receive->handle($widget->organization, InboxChannel::WebChat, [
            'channel_key' => 'web',
            'thread' => hash('sha256', $token),
            'body' => $body,
            'name' => Str::limit(trim((string) ($data['name'] ?? '')), 100, '') ?: null,
            'email' => $email,
            'phone' => Str::limit(trim((string) ($data['phone'] ?? '')), 40, '') ?: null,
        ]);

        return $this->cors(response()->json(['token' => $token, 'messages' => $this->messages($widget, $token, null)]), $request, $widget);
    }

    public function poll(Request $request, string $key): JsonResponse
    {
        $widget = $this->allowed($request, $key);
        abort_unless($widget->offers('chat'), 404);
        $token = $this->validToken($request->query('token'));

        return $this->cors(response()->json([
            'messages' => $token ? $this->messages($widget, $token, (string) $request->query('after', '')) : [],
        ]), $request, $widget);
    }

    /**
     * What the visitor sees: their messages and the business's sent replies. Never notes, drafts or failed sends.
     *
     * @return list<array{id: string, from: string, text: ?string, at: ?string}>
     */
    private function messages(ChatWidget $widget, string $token, ?string $after): array
    {
        $conversation = Conversation::withoutGlobalScopes()->where('organization_id', $widget->organization_id)
            ->where('channel', InboxChannel::WebChat->value)->where('channel_key', 'web')
            ->where('external_thread_id', hash('sha256', $token))->first();
        if (! $conversation) {
            return [];
        }

        return Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)
            ->where('is_note', false)->whereIn('status', ['received', 'sent'])
            ->when($after !== null && $after !== '', fn ($q) => $q->where('ulid', '>', $after))
            ->orderBy('id')->limit(200)->get()
            ->map(fn (Message $m) => [
                'id' => $m->ulid,
                'from' => $m->direction === Message::IN ? 'you' : ($m->author_type === 'ai' ? 'assistant' : 'business'),
                'text' => $m->body,
                'at' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
            ])->all();
    }

    private function widget(string $key): ChatWidget
    {
        $widget = ChatWidget::withoutGlobalScopes()->where('public_key', $key)->where('is_enabled', true)->with('organization')->firstOrFail();
        abort_unless($widget->organization->isServing(), 404);   // paused or cancelled service: the snippet stays hidden (D45)

        return $widget;
    }

    private function allowed(Request $request, string $key): ChatWidget
    {
        $widget = $this->widget($key);
        abort_unless($widget->allowsOrigin($request->headers->get('Origin')), 403, 'This website may not use this chat.');

        return $widget;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $json = json_decode((string) $request->getContent(), true);

        return is_array($json) ? $json : $request->all();
    }

    private function validToken(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{48}$/', $token) ? $token : null;
    }

    /**
     * @template T of \Symfony\Component\HttpFoundation\Response
     *
     * @param  T  $response
     * @return T
     */
    private function cors($response, Request $request, ChatWidget $widget)
    {
        $origin = $request->headers->get('Origin');
        if ($origin && $widget->allowsOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
