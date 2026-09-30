<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Actions;

use Asignua\FilamentChat\Data\MessageData;
use Asignua\FilamentChat\Models\Conversation;
use Asignua\FilamentChat\Pages\Chat;
use Asignua\FilamentChat\Policies\ConversationPolicy;
use Asignua\FilamentChat\Repositories\ConversationRepository;
use Asignua\FilamentChat\Services\ChatService;
use Asignua\FilamentChat\Support\ChatManager;
use Asignua\FilamentChat\Support\ChatUsers;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * "Discuss in chat" for a record page: to whom (a colleague or a group I am
 * in) and the text; the record is attached to the message. Visible only for
 * records of a registered reference type.
 *
 *     protected function getHeaderActions(): array
 *     {
 *         return [DiscussInChatAction::make()];
 *     }
 */
class DiscussInChatAction extends Action
{
    /** Prefixes of the "To" values: a person (key) or a group (ulid). */
    private const string TO_USER = 'user:';

    private const string TO_GROUP = 'group:';

    public static function getDefaultName(): ?string
    {
        return 'discussInChat';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament-chat::chat.discuss'))
            ->icon(Chat::icon())
            ->color('gray')
            ->modalHeading(__('filament-chat::chat.discuss'))
            ->modalWidth('lg')
            ->visible(fn (?Model $record): bool => $record !== null && app(ChatManager::class)->references->forRecord($record) !== null)
            ->schema([
                Select::make('to')
                    ->label(__('filament-chat::chat.discuss_to'))
                    ->options(fn (): array => self::destinations())
                    ->searchable()
                    ->native(false)
                    ->required(),
                Textarea::make('body')
                    ->label(__('filament-chat::chat.message'))
                    ->rows(3)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('filament-chat::chat.send'))
            ->action(function (array $data, Model $record): void {
                $me = ChatUsers::current();
                $type = app(ChatManager::class)->references->forRecord($record);

                if ($me === null || $type === null) {
                    return;
                }

                try {
                    $conversation = self::conversation($me, (string) ($data['to'] ?? ''));

                    app(ChatService::class)->send($conversation, $me, MessageData::fromArray([
                        'body' => $data['body'] ?? null,
                        'reference_type' => $type->getKey(),
                        'reference_id' => $record->getKey(),
                    ]));
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('filament-chat::chat.sent'))
                    ->success()
                    ->actions([
                        Action::make('openChat')
                            ->label(__('filament-chat::chat.open'))
                            ->url(Chat::urlFor($conversation)),
                    ])
                    ->send();
            });
    }

    /**
     * Groups I am in and colleagues — as separate sections of the list.
     *
     * @return array<string, array<string, string>>
     */
    private static function destinations(): array
    {
        $me = ChatUsers::current();

        if ($me === null) {
            return [];
        }

        $groups = [];

        foreach (app(ConversationRepository::class)->activeGroupsFor($me) as $group) {
            $groups[self::TO_GROUP.$group->ulid] = (string) $group->title;
        }

        $people = [];

        foreach (ChatUsers::choices($me) as $id => $name) {
            $people[self::TO_USER.$id] = $name;
        }

        return array_filter([
            __('filament-chat::chat.discuss_groups') => $groups,
            __('filament-chat::chat.discuss_people') => $people,
        ]);
    }

    /**
     * The conversation behind the "To" value: a direct one is found or
     * created, a group only if I may write into it.
     */
    private static function conversation(Model $me, string $to): Conversation
    {
        if (str_starts_with($to, self::TO_GROUP)) {
            $group = app(ConversationRepository::class)->findByUlid(substr($to, strlen(self::TO_GROUP)));

            if ($group === null || !Gate::allows(ConversationPolicy::SEND, $group)) {
                throw new InvalidArgumentException(__('filament-chat::chat.error_not_member'));
            }

            return $group;
        }

        $other = str_starts_with($to, self::TO_USER)
            ? ChatUsers::findChattable(substr($to, strlen(self::TO_USER)))
            : null;

        if ($other === null) {
            throw new InvalidArgumentException(__('filament-chat::chat.error_not_member'));
        }

        return app(ChatService::class)->startDirect($me, $other);
    }
}
