<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MessageSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $campaign_id
 * @property Channel $channel
 * @property string|null $subject
 * @property string $body
 * @property MessageSource $source
 */
class CampaignMessage extends Model
{
    /** @var list<string> */
    protected $fillable = ['campaign_id', 'channel', 'subject', 'body', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'source' => MessageSource::class,
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
