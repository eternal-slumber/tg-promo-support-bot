<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('excludes historical start warnings from bot statistics without changing answers or delivery data', function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    Queue::fake();
    $participant = TelegramParticipant::factory()->create();
    $body = 'Не отправляйте данные банковских карт, пароли или коды из SMS: они не нужны для поддержки акции.';
    $warnings = [];
    foreach (DeliveryStatus::cases() as $status) {
        $message = Message::factory()->for($participant, 'participant')->create([
            'direction' => MessageDirection::Outbound,
            'author' => MessageAuthor::Bot,
            'body' => $body,
            'delivery_status' => $status,
            'delivered_at' => $status === DeliveryStatus::Sent ? now() : null,
        ]);
        $warnings[$message->id] = $message->refresh()->getAttributes();
    }
    $answer = Message::factory()->for($participant, 'participant')->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'body' => 'Йогурты участвуют в акции.',
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now(),
    ]);
    $ticket = Ticket::factory()->for($participant, 'participant')->create(['first_operator_replied_at' => now()]);
    $operatorReply = Message::factory()->for($participant, 'participant')->for($ticket)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'body' => $body,
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now(),
    ]);
    $inbound = Message::factory()->for($participant, 'participant')->create(['body' => $body]);
    $unchanged = collect([$answer, $operatorReply, $inbound])->mapWithKeys(fn (Message $message): array => [$message->id => $message->refresh()->getAttributes()]);
    $migration = require database_path('migrations/2026_10_03_093504_classify_start_warnings_as_system_messages.php');

    $migration->up();

    foreach ($warnings as $id => $attributes) {
        expect(Message::query()->findOrFail($id)->getAttributes())->toBe(array_replace($attributes, ['author' => 'system']));
    }
    foreach ($unchanged as $id => $attributes) {
        expect(Message::query()->findOrFail($id)->getAttributes())->toBe($attributes);
    }
    $this->assertDatabaseCount('messages', 7);
    $this->actingAs(User::factory()->create());
    Livewire::test(OperatorDashboard::class)->assertViewHas('statistics', fn (array $statistics): bool => $statistics['bot_resolved'] === 1 && $statistics['bot_prepared'] === 1
    );
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
