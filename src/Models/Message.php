<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Models;

use Asignua\FilamentChat\Concerns\HasPublicUlid;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property int|null $reply_to_id
 * @property list<int>|null $mentions
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $author
 * @property-read Conversation $conversation
 * @property-read Message|null $replyTo
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

    /**
     * One line for lists, toasts and the bell: the text, or — for a message that is just a
     * reference — "📎 Tour". Only the type: the recipient may not be allowed to see the record.
     */
    public function preview(int $limit = 140): string
    {
        if (trim($this->body) !== '') {
            return Str::limit($this->body, $limit);
        }

        $type = app(ChatManager::class)->references->get($this->reference_type);

        return '📎 '.($type?->getLabel() ?? __('filament-chat::chat.attachment'));
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
     * The message this one answers (same conversation; ChatService checks it).
     *
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::messageModel(), 'reply_to_id');
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
