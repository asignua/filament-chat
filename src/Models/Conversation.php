<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Models;

use Asignua\FilamentChat\Concerns\HasPublicUlid;
use Asignua\FilamentChat\Enums\ConversationType;
use Asignua\FilamentChat\Support\ChatConfig;
use Asignua\FilamentChat\Support\ChatUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A direct conversation (two people; `direct_key` keeps one per pair) or a
 * titled group. Written only through ConversationRepository.
 *
 * @property int $id
 * @property string $ulid
 * @property ConversationType $type
 * @property string|null $title
 * @property int|null $created_by_user_id
 * @property string|null $direct_key
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $creator
 * @property-read Message|null $latestMessage
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Message> $messages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Participant> $participants
 */
class Conversation extends Model
{
    use HasPublicUlid;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return ChatConfig::table('conversations');
    }

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function isGroup(): bool
    {
        return $this->type === ConversationType::Group;
    }

    /**
     * How a viewer sees the conversation: a group by its title, a direct one by
     * the other person's name. Expects `participants.user` to be loaded.
     */
    public function titleFor(Model $viewer): string
    {
        if ($this->isGroup()) {
            return (string) $this->title;
        }

        $counterpart = $this->counterpartFor($viewer);

        return $counterpart !== null
            ? ChatUsers::name($counterpart)
            : __('filament-chat::chat.unknown_user');
    }

    /**
     * The other person of a direct conversation; null for a group.
     */
    public function counterpartFor(Model $viewer): ?Model
    {
        if ($this->isGroup()) {
            return null;
        }

        return $this->participants
            ->first(fn (Participant $participant): bool => $participant->user_id !== $viewer->getKey())
            ?->user;
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'created_by_user_id');
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatConfig::participantModel(), 'conversation_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatConfig::messageModel(), 'conversation_id');
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatConfig::messageModel(), 'conversation_id')->latestOfMany();
    }
}
