<?php

declare(strict_types=1);

namespace Asignua\FilamentChat\Tests\Feature;

use Asignua\FilamentChat\Livewire\ChatDock;
use Asignua\FilamentChat\Notifications\MentionNotification;
use Asignua\FilamentChat\Notifications\NewMessageNotification;
use Asignua\FilamentChat\Tests\TestCase;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * Filament renders a notification's title and body as (sanitized) HTML, which
 * still keeps links, images and inline styles. Chat text is plain text
 * everywhere else, so the bell and the toast must show it as text too.
 */
class NotificationEscapingTest extends TestCase
{
    private const string BODY = '<a href="https://evil.example/login" style="position:fixed;inset:0">x</a><img src="https://evil.example/t.gif">';

    private const string NAME = '<img src="https://evil.example/n.gif">Eve';

    public function test_bell_shows_the_message_and_names_as_text(): void
    {
        $eve = $this->user(['name' => self::NAME]);
        $admin = $this->user();
        $group = $this->group($eve, '<a href="https://evil.example">Team</a>', $admin);
        $message = $this->send($group, $eve, self::BODY)->load('author');

        foreach ([NewMessageNotification::class, MentionNotification::class] as $class) {
            $data = (new $class($group, $message))->toDatabase($admin);

            $this->assertSame(e(self::BODY), $data['body']);
            $this->assertNoLiveMarkup($data['title'].$data['body']);
            $this->assertStringContainsString(e(self::NAME), $data['title']);
        }
    }

    public function test_toast_shows_the_message_and_name_as_text(): void
    {
        $admin = $this->user();
        $eve = $this->user(['name' => self::NAME]);
        $conversation = $this->direct($admin, $eve);
        $message = $this->send($conversation, $eve, self::BODY);
        $this->actingAs($admin);

        Livewire::test(ChatDock::class)->call('onChatUpdated', [
            'conversation' => $conversation->ulid,
            'message' => $message->ulid,
            'author' => (string) $eve->id,
        ]);

        $notifications = new Notifications;
        $notifications->mount();
        $toast = $notifications->notifications->last();

        $this->assertInstanceOf(Notification::class, $toast);
        $this->assertSame(e(self::BODY), $toast->getBody());
        $this->assertNoLiveMarkup($toast->getTitle().$toast->getBody());
    }

    private function assertNoLiveMarkup(string $html): void
    {
        $rendered = (string) str($html)->sanitizeHtml();

        $this->assertStringNotContainsString('<a', $rendered);
        $this->assertStringNotContainsString('<img', $rendered);
    }
}
