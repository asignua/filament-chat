<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Models;

use Asignua\FilamentChat\Concerns\HasPublicUlid;
use Asignua\FilamentChat\Enums\Reaction;
use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's reaction to a message — at most one per person per message.
 *
 * @property int $id
 * @property string $ulid
 * @property int $chat_message_id
 * @property int $user_id
 * @property Reaction $reaction
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 * @property-read Model|null $user
 */
class MessageReaction extends Model
{
    use HasPublicUlid;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return ChatConfig::table('reactions');
    }

    protected function casts(): array
    {
        return [
            'reaction' => Reaction::class,
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::messageModel(), 'chat_message_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
