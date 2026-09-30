<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Models;

use Asignua\FilamentChat\Concerns\HasPublicUlid;
use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member of a conversation: since when, whether they left, how far they read.
 *
 * @property int $id
 * @property string $ulid
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 * @property-read Model|null $user
 */
class Participant extends Model
{
    use HasPublicUlid;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return ChatConfig::table('participants');
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::conversationModel(), 'conversation_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
