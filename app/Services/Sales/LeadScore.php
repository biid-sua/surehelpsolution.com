<?php

namespace App\Services\Sales;

/**
 * Hot, warm or cold, from what the chat learned (D78). Simple, explainable rules rather than AI, so
 * the score costs nothing and the team can trust it: a booked demo or a near-term start is hot.
 */
class LeadScore
{
    /** @param array<string, string> $lead */
    public static function for(array $lead, bool $demoBooked): string
    {
        $points = 0;
        $points += filled($lead['email'] ?? null) || filled($lead['phone'] ?? null) ? 2 : 0;
        $points += filled($lead['company'] ?? null) ? 1 : 0;
        $points += filled($lead['industry'] ?? null) ? 1 : 0;
        $points += filled($lead['needs'] ?? null) ? 1 : 0;
        $points += filled($lead['plan'] ?? null) ? 1 : 0;

        $calls = (int) preg_replace('/\D/', '', (string) ($lead['monthly_calls'] ?? ''));
        $points += $calls >= 300 ? 2 : ($calls >= 50 ? 1 : 0);

        $timeline = strtolower((string) ($lead['timeline'] ?? ''));
        $points += preg_match('/now|asap|today|this week|immediately|urgent|right away/', $timeline) ? 3
            : (preg_match('/month|soon|weeks?/', $timeline) ? 1 : 0);

        if ($demoBooked || $points >= 7) {
            return 'hot';
        }

        return $points >= 4 ? 'warm' : 'cold';
    }
}
