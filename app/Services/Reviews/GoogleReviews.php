<?php

namespace App\Services\Reviews;

use App\Models\SocialAccount;
use App\Services\Social\Connectors\GoogleBusinessConnector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Reviews of one Google Business Profile location (D83), through the Business Profile API (v4).
 */
class GoogleReviews
{
    private const API = 'https://mybusiness.googleapis.com/v4/';

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /**
     * The newest reviews first, up to $max.
     *
     * @return array{reviews: list<array{id: string, author: ?string, rating: int, comment: ?string, at: CarbonImmutable, reply: ?string, replied_at: ?CarbonImmutable}>, average: ?float, total: ?int}
     */
    public function fetch(SocialAccount $account, string $token, int $max = 50): array
    {
        $reviews = [];
        $average = $total = null;
        $page = null;
        do {
            $json = GoogleBusinessConnector::ok(Http::withToken($token)->acceptJson()->timeout(30)
                ->get(self::API.$account->external_id.'/reviews', array_filter(['pageSize' => 50, 'orderBy' => 'updateTime desc', 'pageToken' => $page])))->json();
            $average ??= isset($json['averageRating']) ? (float) $json['averageRating'] : null;
            $total ??= isset($json['totalReviewCount']) ? (int) $json['totalReviewCount'] : null;
            foreach ((array) ($json['reviews'] ?? []) as $r) {
                $rating = self::STARS[$r['starRating'] ?? ''] ?? 0;
                if ($rating === 0 || empty($r['name'])) {
                    continue;
                }
                $reviews[] = [
                    'id' => (string) $r['name'],
                    'author' => isset($r['reviewer']['displayName']) ? mb_substr((string) $r['reviewer']['displayName'], 0, 120) : null,
                    'rating' => $rating,
                    'comment' => isset($r['comment']) ? self::clean((string) $r['comment']) : null,
                    'at' => CarbonImmutable::parse($r['createTime'] ?? $r['updateTime'] ?? 'now'),
                    'reply' => $r['reviewReply']['comment'] ?? null,
                    'replied_at' => isset($r['reviewReply']['updateTime']) ? CarbonImmutable::parse($r['reviewReply']['updateTime']) : null,
                ];
            }
            $page = $json['nextPageToken'] ?? null;
        } while ($page && count($reviews) < $max);

        return ['reviews' => array_slice($reviews, 0, $max), 'average' => $average, 'total' => $total];
    }

    public function reply(SocialAccount $account, string $token, string $reviewId, string $comment): void
    {
        GoogleBusinessConnector::ok(Http::withToken($token)->acceptJson()->timeout(30)
            ->put(self::API.$reviewId.'/reply', ['comment' => $comment]));
    }

    /** Google adds "(Translated by Google)" blocks; keep the reviewer's own words. */
    private static function clean(string $comment): string
    {
        $original = str_contains($comment, '(Original)') ? trim((string) preg_replace('/^.*\(Original\)\s*/s', '', $comment)) : $comment;

        return trim((string) preg_replace('/\s*\(Translated by Google\).*$/s', '', $original));
    }
}
