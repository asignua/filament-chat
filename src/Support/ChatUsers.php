<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the chat needs to know about the host's users: display name,
 * who can be written to, search columns and the private channel key.
 */
final class ChatUsers
{
    /**
     * The signed-in person (the panel guard when a panel is serving).
     */
    public static function current(): ?Model
    {
        $user = Filament::getCurrentPanel() !== null ? Filament::auth()->user() : auth()->user();

        return $user instanceof Model ? $user : null;
    }

    public static function name(Model $user): string
    {
        $resolver = app(ChatManager::class)->userNameUsing;

        if ($resolver !== null) {
            return (string) $resolver($user);
        }

        $attribute = ChatConfig::userNameAttribute();

        if ($attribute !== null) {
            $name = $user->getAttribute($attribute);

            return is_scalar($name) && (string) $name !== '' ? (string) $name : '#'.$user->getKey();
        }

        if ($user instanceof HasName) {
            return $user->getFilamentName();
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : '#'.$user->getKey();
    }

    /**
     * Avatar URL: the plugin's ->avatarUsing(), else Filament's provider
     * (HasAvatar::getFilamentAvatarUrl(), then the panel's default — initials).
     */
    public static function avatar(Model $user): ?string
    {
        $resolver = app(ChatManager::class)->avatarUsing;

        if ($resolver !== null) {
            $url = $resolver($user);

            return is_string($url) && $url !== '' ? $url : null;
        }

        return Filament::getUserAvatarUrl($user);
    }

    /**
     * People one can write to (and add to a group) — all users unless the
     * plugin narrows it with `->users()`.
     *
     * @return Builder<Model>
     */
    public static function query(): Builder
    {
        $model = ChatConfig::userModel();
        $query = $model::query();
        $modifier = app(ChatManager::class)->modifyUsersQueryUsing;

        if ($modifier !== null) {
            $modifier($query);
        }

        return $query;
    }

    /**
     * Options for a select: key → name, alphabetical.
     *
     * @return array<int|string, string>
     */
    public static function choices(?Model $except = null): array
    {
        $options = [];

        foreach (self::query()->when($except !== null, fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()))->get() as $user) {
            $options[$user->getKey()] = self::name($user);
        }

        natcasesort($options);

        return $options;
    }

    /**
     * Search for a select: key → name of at most `$limit` people whose search columns match every
     * word, alphabetical. Without search columns the names are filtered here instead.
     *
     * @return array<int|string, string>
     */
    public static function search(string $term, ?Model $except = null, int $limit = 50): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $columns = self::searchColumns();

        if ($columns === [] && $words !== []) {
            return array_slice(array_filter(
                self::choices($except),
                fn (string $name): bool => collect($words)->every(fn (string $word): bool => mb_stripos($name, $word) !== false),
            ), 0, $limit, preserve_keys: true);
        }

        $query = self::query()->when($except !== null, fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()));

        foreach ($words as $word) {
            $like = '%'.$word.'%';

            $query->where(function (Builder $any) use ($columns, $like): void {
                foreach ($columns as $column) {
                    $any->orWhereLike($any->getModel()->qualifyColumn($column), $like);
                }
            });
        }

        $options = [];

        foreach ($query->limit($limit)->get() as $user) {
            $options[$user->getKey()] = self::name($user);
        }

        natcasesort($options);

        return $options;
    }

    /**
     * Names of the given people (the labels of a select's current value).
     *
     * @param list<int|string> $ids
     *
     * @return array<int|string, string>
     */
    public static function labels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $options = [];

        foreach (self::query()->whereKey($ids)->get() as $user) {
            $options[$user->getKey()] = self::name($user);
        }

        return $options;
    }

    /**
     * The subset of the given keys that belongs to people one can write to.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public static function chattableIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(intval(...), self::query()->whereKey($ids)->pluck((new (ChatConfig::userModel()))->getKeyName())->all()));
    }

    public static function findChattable(mixed $id): ?Model
    {
        return is_numeric($id) ? self::query()->whereKey((int) $id)->first() : null;
    }

    public static function find(mixed $id): ?Model
    {
        $model = ChatConfig::userModel();

        return is_numeric($id) ? $model::query()->find((int) $id) : null;
    }

    /**
     * The key that names a person's private channel.
     */
    public static function broadcastKey(Model $user): string
    {
        $attribute = ChatConfig::userBroadcastKey();

        return (string) ($attribute !== null ? $user->getAttribute($attribute) : $user->getKey());
    }

    /**
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ChatConfig::userSearchColumns();
    }
}
