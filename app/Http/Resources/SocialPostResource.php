<?php

namespace App\Http\Resources;

use App\Models\SocialPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialPost
 */
class SocialPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'body' => $this->body,
            'link_url' => $this->link_url,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'source' => $this->source,
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'review_note' => $this->review_note,
            'accounts' => $this->whenLoaded('targets', fn () => $this->targets->filter(fn ($t) => $t->account !== null)->map(fn ($t) => [
                'network' => $t->account->network->value,
                'name' => $t->account->displayName(),
                'status' => $t->status,
                'url' => $t->external_url,
                'error' => $t->status === 'failed' ? $t->last_error : null,
            ])->values()),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => ['url' => $m->publicUrl(), 'alt' => $m->alt_text])->values()),
            'web_url' => route('app.social.posts.edit', $this->resource),
        ];
    }
}
