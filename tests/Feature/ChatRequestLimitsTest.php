<?php

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['services.discord.required_guild_id' => '']);
});

test('chat history returns only the requested bounded page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 3) as $number) {
        ChatMessage::create(['uid' => $user->uid, 'message' => "Message {$number}"]);
    }

    $this->getJson('/chat/messages?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.message', 'Message 2')
        ->assertJsonPath('messages.1.message', 'Message 3');
});

test('chat history rejects oversized or invalid pagination', function (): void {
    $this->actingAs(User::factory()->create());

    $this->getJson('/chat/messages?limit=101')->assertUnprocessable();
    $this->getJson('/chat/messages?limit=not-a-number')->assertUnprocessable();
    $this->getJson('/chat/messages?before=not-a-number')->assertUnprocessable();
});

test('chat history and send routes have independent rate limits', function (): void {
    expect(Route::getRoutes()->getByName('chat.messages')->gatherMiddleware())
        ->toContain('throttle:chat-history');
    expect(Route::getRoutes()->getByName('chat.send')->gatherMiddleware())
        ->toContain('throttle:chat-send');
    expect(Route::getRoutes()->getByName('chat.unread-count')->gatherMiddleware())
        ->toContain('throttle:chat-unread');
    expect(Route::getRoutes()->getByName('chat.mark-read')->gatherMiddleware())
        ->toContain('throttle:chat-mark-read');
});

test('chat send throttling rejects messages beyond the per-minute allowance', function (): void {
    Event::fake();
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $number) {
        $this->postJson('/chat/send', ['message' => "Message {$number}"])->assertOk();
    }

    $this->postJson('/chat/send', ['message' => 'Blocked message'])->assertStatus(429);
    expect(ChatMessage::query()->count())->toBe(10);
});

test('chat history polling does not exhaust the message-send allowance', function (): void {
    Event::fake();
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $_) {
        $this->getJson('/chat/messages')->assertOk();
    }

    $this->postJson('/chat/send', ['message' => 'Still allowed'])->assertOk();
});
