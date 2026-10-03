<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('repairs historical response timestamps using the earliest successfully delivered operator reply', function () {
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
    $undelivered = collect([DeliveryStatus::Pending, DeliveryStatus::Failed, DeliveryStatus::Cancelled])->map(function (DeliveryStatus $status): Ticket {
        $ticket = Ticket::factory()->create(['created_at' => now()->subHour(), 'first_operator_replied_at' => now()->subMinutes(30)]);
        Message::factory()->for($ticket->participant, 'participant')->for($ticket)->create([
            'direction' => MessageDirection::Outbound,
            'author' => MessageAuthor::Operator,
            'delivery_status' => $status,
        ]);

        return $ticket;
    });
    $delivered = Ticket::factory()->closed()->create(['created_at' => now()->subHour(), 'first_operator_replied_at' => now()->subMinutes(30)]);
    Message::factory()->count(2)->for($delivered->participant, 'participant')->for($delivered)->sequence(
        ['created_at' => now()->subMinutes(20), 'delivered_at' => now()->subMinutes(3)],
        ['created_at' => now()->subMinutes(10), 'delivered_at' => now()->subMinutes(7)],
    )->create(['direction' => MessageDirection::Outbound, 'author' => MessageAuthor::Operator, 'delivery_status' => DeliveryStatus::Sent]);
    Message::factory()->for($delivered->participant, 'participant')->for($delivered)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => now()->subMinutes(25),
    ]);
    $missingTimestamp = Ticket::factory()->create(['created_at' => now()->subHour(), 'first_operator_replied_at' => now()->subMinutes(30)]);
    Message::factory()->for($missingTimestamp->participant, 'participant')->for($missingTimestamp)->create([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Operator,
        'delivery_status' => DeliveryStatus::Sent,
        'delivered_at' => null,
    ]);
    $messages = Message::query()->orderBy('id')->get()->toArray();
    $migration = require database_path('migrations/2026_10_03_091337_backfill_first_operator_replied_at_from_delivered_messages.php');
    $this->travel(1)->hours();

    $migration->up();

    foreach ($undelivered as $ticket) {
        expect($ticket->refresh()->first_operator_replied_at)->toBeNull();
    }
    expect($missingTimestamp->refresh()->first_operator_replied_at)->toBeNull();
    expect($delivered->refresh()->first_operator_replied_at?->toDateTimeString())->toBe('2026-10-03 11:53:00')
        ->and($delivered->updated_at->toDateTimeString())->toBe('2026-10-03 12:00:00');
    expect(Message::query()->orderBy('id')->get()->toArray())->toBe($messages);
});
