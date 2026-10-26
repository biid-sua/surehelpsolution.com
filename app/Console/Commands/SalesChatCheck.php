<?php

namespace App\Console\Commands;

use App\Exceptions\AiUnavailable;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Why the website sales chat (D78) isn't answering on this install: switched off, tables missing,
 * cache not working, no API key, or the AI can't be reached (often a missing CA bundle on Windows).
 */
class SalesChatCheck extends Command
{
    protected $signature = 'sales-chat:check {--no-ai : Skip the live test call to the AI}';

    protected $description = 'Check the website sales chat works on this installation, including a live AI test';

    private bool $failed = false;

    public function handle(AiProvider $ai): int
    {
        $this->failed = false;
        $this->row(config('sales.enabled'), 'Chat switched on', 'Set SALES_CHAT_ENABLED=true (or remove it) in .env, then php artisan config:clear.');

        $missing = array_values(array_filter(['sales_chats', 'sales_chat_messages', 'contact_submissions'], fn ($t) => ! rescue(fn () => Schema::hasTable($t), false, report: false)));
        $this->row($missing === [], 'Database tables', 'Missing: '.implode(', ', $missing).'. Run php artisan migrate.');

        $cache = rescue(function () {
            Cache::put('sales-chat-check', 'ok', 10);

            return Cache::pull('sales-chat-check') === 'ok';
        }, false, report: false);
        $this->row($cache, 'Cache (rate limits and the AI budget)', 'The '.config('cache.default').' cache store fails. With CACHE_STORE=database run php artisan migrate, or set CACHE_STORE=file.');

        $this->row(filled(config('app.url')), 'APP_URL', 'Set APP_URL to the address you open the site on, e.g. http://127.0.0.1:8000.');

        if (! $ai->isConfigured()) {
            $this->warn('  ! No ANTHROPIC_API_KEY: buttons, forms and demo booking work; typed questions get keyword answers only.');
            $this->line('      → Add ANTHROPIC_API_KEY to .env (and SALES_CHAT_MODEL=claude-haiku-5-5 to keep it cheap), then php artisan config:clear.');
        } elseif ($this->option('no-ai')) {
            $this->line('  <fg=gray>○ Live AI test skipped</>');
        } else {
            $this->liveTest($ai);
        }

        $this->newLine();
        if ($this->failed) {
            $this->error('The chat has a problem: fix the ✗ items above.');

            return self::FAILURE;
        }
        $this->info('The chat should work. Still stuck? Open the browser console (F12) on the home page and look for errors from /api/sales-chat.');

        return self::SUCCESS;
    }

    private function liveTest(AiProvider $ai): void
    {
        $model = config('sales.ai.model') ?: $ai->model();
        try {
            $reply = $ai->complete(new AiRequest(
                instructions: 'You are a connectivity check. Reply with the single word OK.',
                context: '',
                messages: [['role' => 'user', 'content' => 'Say OK.']],
                effort: 'low',
                model: $model,
                maxTokens: 50,
            ));
            $this->row(true, "Live AI answer from {$model}", '', '"'.trim($reply->text).'"');
        } catch (AiUnavailable $e) {
            $message = $e->getMessage();
            $hint = match (true) {
                (bool) preg_match('/SSL|certificate|cURL error 60|cURL error 77/i', $message) => 'PHP can\'t verify HTTPS certificates. Download cacert.pem from curl.se/docs/caextract.html and set curl.cainfo and openssl.cafile to it in php.ini, then restart the server.',
                (bool) preg_match('/401|authentication|api.key|x-api-key/i', $message) => 'The API key was refused. Copy it again from platform.claude.com and check it has no spaces or quotes.',
                (bool) preg_match('/model/i', $message) => "The model {$model} isn't available to this key. Set SALES_CHAT_MODEL (or AI_MODEL) to one it can use.",
                (bool) preg_match('/credit|billing|balance/i', $message) => 'The Anthropic account has no credit. Add some under Billing in the Claude Console.',
                (bool) preg_match('/timed out|resolve|connect/i', $message) => 'This computer can\'t reach api.anthropic.com. Check the internet connection, firewall or proxy.',
                default => 'See the error above and storage/logs/laravel.log.',
            };
            $this->row(false, 'Live AI answer', $hint, Str::limit((string) preg_replace('/\s+/', ' ', $message), 240));
        }
    }

    private function row(bool $ok, string $label, string $fix, string $detail = ''): void
    {
        $this->line('  '.($ok ? '<info>✓</info>' : '<error>✗</error>').' '.$label.($detail !== '' ? ' <fg=gray>('.$detail.')</>' : ''));
        if (! $ok) {
            $this->failed = true;
            $this->line('      → '.$fix);
        }
    }
}
