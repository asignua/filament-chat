## Filament Chat (asignua/filament-chat)

- Team chat plugin for a Filament 5 panel: `->plugin(FilamentChatPlugin::make())`. Options: `config/filament-chat.php` (every key commented); plugin setters override it; closures (`->users()`, `->userName()`, per-type `->visibleUsing()`) live on the plugin only.
- Write messages only through `Asignua\FilamentChat\Services\ChatService` (`startDirect`, `createGroup`, `send`, `edit`, `react`) — never create models directly.
- Records become attachable via `->references([ReferenceType::resource(XResource::class)])` or `references.all_resources`. With the default `references.authorize = 'policy'` a model without a policy cannot be attached — add a policy or use `'resource'`.
- Real-time: `FILAMENT_CHAT_REALTIME=true` + a Reverb/Pusher broadcasting connection; the plugin creates Echo itself. `false` = polling.
- Full guide: the `filament-chat` skill (`php artisan filament-chat:install --skill`).
