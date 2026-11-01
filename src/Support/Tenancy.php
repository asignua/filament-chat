<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Support;

use Asignua\FilamentChat\Models\Conversation;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Throwable;

/**
 * Multi-tenancy. A conversation belongs to one tenant (`tenant_type` + `tenant_id` on the conversation, a morph
 * pair), and everything the chat reads — the list, unread counters, people to write to, presence, the broadcast
 * channels — is narrowed to the CURRENT tenant, so a person in two tenants has two separate chats.
 *
 * Scoping is "active" when it is turned on (the panel has tenancy, `->tenantScoped()` or the config), the
 * migration has been run and there is a tenant right now. Without a tenant (a queued job, a console command, the
 * broadcast auth request) nothing is narrowed — those paths act on a conversation they already hold, whose
 * membership and tenant they check themselves.
 */
final class Tenancy
{
    /** Prefix of the private channel of a tenant: `filament-chat.tenant.{tenant}.user.{key}`. */
    public const string CHANNEL = 'filament-chat.tenant.';

    /**
     * Scoping is wanted: `tenancy.enabled` (plugin `->tenantScoped()`) when set, otherwise automatically for a panel
     * with tenancy, or when a tenant resolver is given.
     */
    public static function enabled(): bool
    {
        $configured = config('filament-chat.tenancy.enabled');

        if ($configured !== null) {
            return (bool) $configured;
        }

        $manager = app(ChatManager::class);

        return $manager->resolveTenantUsing !== null || ($manager->panel()?->hasTenancy() ?? false);
    }

    /**
     * Scoping is wanted and the columns are there.
     */
    public static function ready(): bool
    {
        return self::enabled() && self::columnsAvailable();
    }

    /**
     * Narrowing is in force right now: wanted, migrated and a tenant is known.
     */
    public static function active(): bool
    {
        return self::ready() && self::reference() !== null;
    }

    /**
     * The tenant of the moment: the plugin's resolver, else the panel's (`Filament::getTenant()`).
     */
    public static function tenant(): mixed
    {
        $resolver = app(ChatManager::class)->resolveTenantUsing;

        return $resolver !== null ? $resolver() : Filament::getTenant();
    }

    /**
     * [type, key] of the current tenant; null — scoping is off or there is no tenant.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function reference(): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        return self::referenceOf(self::tenant());
    }

    /**
     * [type, key] of a tenant: a model (its morph class + key) or a plain key.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function referenceOf(mixed $tenant): ?array
    {
        if ($tenant instanceof Model) {
            return [$tenant->getMorphClass(), (string) $tenant->getKey()];
        }

        return is_scalar($tenant) && (string) $tenant !== '' ? ['tenant', (string) $tenant] : null;
    }

    /**
     * Whether the conversations table has the tenant columns (1.8) — checked once per application instance.
     */
    public static function columnsAvailable(): bool
    {
        $manager = app(ChatManager::class);

        if ($manager->tenantColumns === null) {
            $model = ChatConfig::conversationModel();
            $schema = (new $model)->getConnection()->getSchemaBuilder();
            $manager->tenantColumns = $schema->hasColumns(ChatConfig::table('conversations'), ['tenant_type', 'tenant_id']);
        }

        return $manager->tenantColumns;
    }

    /**
     * Only the current tenant's conversations. Untouched when scoping is not active.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     *
     * @return Builder<TModel>
     */
    public static function scope(Builder $query): Builder
    {
        $reference = self::reference();

        if ($reference === null || !self::columnsAvailable()) {
            return $query;
        }

        return $query
            ->where($query->qualifyColumn('tenant_type'), $reference[0])
            ->where($query->qualifyColumn('tenant_id'), $reference[1]);
    }

    /**
     * The same for a raw query over the conversations table (aliased `$alias`).
     */
    public static function scopeTable(QueryBuilder $query, string $alias): QueryBuilder
    {
        $reference = self::reference();

        if ($reference === null || !self::columnsAvailable()) {
            return $query;
        }

        return $query
            ->where($alias.'.tenant_type', $reference[0])
            ->where($alias.'.tenant_id', $reference[1]);
    }

    /**
     * Write the current tenant onto a new conversation.
     */
    public static function stamp(Conversation $conversation): void
    {
        $reference = self::reference();

        if ($reference !== null && self::columnsAvailable()) {
            $conversation->tenant_type = $reference[0];
            $conversation->tenant_id = $reference[1];
        }
    }

    /**
     * Whether the conversation may be seen from the current tenant: always when scoping is not active.
     */
    public static function owns(Conversation $conversation): bool
    {
        $reference = self::reference();

        if ($reference === null || !self::columnsAvailable()) {
            return true;
        }

        return $conversation->tenant_type === $reference[0] && (string) $conversation->tenant_id === $reference[1];
    }

    /**
     * Segment of a broadcast channel name for a tenant: a short hash of the type + the key. Null — no tenant.
     *
     * @param array{0: string, 1: string}|null $reference
     */
    public static function channelKey(?array $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        return substr(md5($reference[0]), 0, 8).'-'.(preg_replace('/[^A-Za-z0-9_-]/', '_', $reference[1]) ?? $reference[1]);
    }

    /**
     * The channel segment of the tenant a conversation belongs to; null — it has none (or the columns are missing).
     */
    public static function channelKeyOf(Conversation $conversation): ?string
    {
        if ($conversation->tenant_type === null || $conversation->tenant_id === null) {
            return null;
        }

        return self::channelKey([(string) $conversation->tenant_type, (string) $conversation->tenant_id]);
    }

    /**
     * The segment for the current tenant — what a listener subscribes with.
     */
    public static function currentChannelKey(): ?string
    {
        return self::ready() ? self::channelKey(self::reference()) : null;
    }

    /**
     * Narrow a query of USERS to the current tenant's people. The plugin's `->tenantUsers()` closure decides; else the
     * tenant's `members` (or `users`) relation (`tenancy.members_relation`); else Filament's own
     * `HasTenants::canAccessTenant()` is asked of every user — correct, but it reads the whole table, so give the
     * relation or the closure on a big one.
     *
     * @param Builder<Model> $query
     */
    public static function limitUsers(Builder $query): void
    {
        if (!self::active()) {
            return;
        }

        $tenant = self::tenant();
        $custom = app(ChatManager::class)->tenantUsersUsing;

        if ($custom !== null) {
            $custom($query, $tenant);

            return;
        }

        $model = $query->getModel();
        $key = $model->qualifyColumn($model->getKeyName());

        if ($tenant instanceof Model) {
            foreach (array_unique([(string) config('filament-chat.tenancy.members_relation', 'members'), 'members', 'users']) as $name) {
                if ($name === '' || !method_exists($tenant, $name)) {
                    continue;
                }

                $relation = $tenant->{$name}();

                if ($relation instanceof Relation) {
                    $related = $relation->getRelated();
                    $query->whereIn($key, $relation->getQuery()->select($related->qualifyColumn($related->getKeyName())));

                    return;
                }
            }
        }

        // A plain tenant key with no closure: nothing to ask — people are not narrowed (give ->tenantUsers()).
        if (!$tenant instanceof Model) {
            return;
        }

        // No relation to ask: Filament's own rule, user by user.
        $allowed = [];

        foreach ($model::query()->cursor() as $user) {
            if ($user instanceof HasTenants && $user->canAccessTenant($tenant)) {
                $allowed[] = $user->getKey();
            }
        }

        $query->whereIn($key, $allowed);
    }

    /**
     * Whether `$user` may listen on the tenant channel `$segment` (see {@see self::channelKey()}). The plugin's
     * `->authorizeTenantChannelUsing()` closure decides; else the panel's tenant model is looked up and
     * `HasTenants::canAccessTenant()` asked. When the tenant cannot be found: no; with no panel tenancy and no closure the
     * tenant cannot be verified and the channel stays open (it carries only the person's own events).
     */
    public static function mayListen(Model $user, string $segment): bool
    {
        [$hash, $key] = array_pad(explode('-', $segment, 2), 2, '');

        $custom = app(ChatManager::class)->authorizeTenantChannelUsing;

        if ($custom !== null) {
            return (bool) $custom($user, $segment);
        }

        $model = app(ChatManager::class)->panel()?->getTenantModel();

        // Not a Filament tenancy and no closure: the tenant cannot be verified here. The channel only carries events
        // addressed to this very person (the key is checked by the caller), so it is left open rather than silenced.
        if ($model === null) {
            return true;
        }

        if (!$user instanceof HasTenants || $hash !== substr(md5((new $model)->getMorphClass()), 0, 8)) {
            return false;
        }

        try {
            $tenant = $model::query()->find($key);
        } catch (Throwable) {
            return false;
        }

        return $tenant instanceof Model && $user->canAccessTenant($tenant);
    }

    /**
     * The tenant MODEL of a conversation (for a link into its tenant); null — none or not a model.
     */
    public static function modelOf(Conversation $conversation): ?Model
    {
        if ($conversation->tenant_type === null || $conversation->tenant_id === null) {
            return null;
        }

        $class = Relation::getMorphedModel((string) $conversation->tenant_type) ?? (string) $conversation->tenant_type;

        if (!is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find($conversation->tenant_id);
    }
}
