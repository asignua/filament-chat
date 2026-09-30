<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Models;

use Asignua\FilamentChat\Concerns\HasPublicUlid;
use Asignua\FilamentChat\Support\ChatConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat message; may point at a record of the panel (`reference_type` is a
 * key registered with the plugin, `reference_id` the record key). Messages are
 * never deleted; the author may edit the text (`edited_at`). `mentions` holds
 * the keys of members mentioned with "@Name".
 *
 * @property int $id
 * @property string $ulid
 * @property int $conversation_id
 * @property int|null $user_id
 * @property string $body
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property list<int>|null $mentions
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $author
 * @property-read Conversation $conversation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MessageReaction> $reactions
 */
class Message extends Model
{
    use HasPublicUlid;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return ChatConfig::table('messages');
    }

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return list<int>
     */
    public function mentionIds(): array
    {
        return array_map(intval(...), $this->mentions ?? []);
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }

    /**
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(ChatConfig::reactionModel(), 'chat_message_id');
    }
}
