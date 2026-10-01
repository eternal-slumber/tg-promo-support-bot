<?php

use App\Data\TelegramSentMessage;
use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Exceptions\TelegramDeliveryException;
use App\Jobs\DeliverTelegramMessage;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Services\TelegramBotClient;
use App\Services\TelegramMessagePresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;

uses(LazilyRefreshDatabase::class);

test('marks a pending message sent only after Telegram accepts it', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andReturn(new TelegramSentMessage(789));

    deliver($message, $client);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($message->telegram_message_id)->toBe(789)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->delivery_attempts)->toBe(1)
        ->and($message->last_delivery_error)->toBeNull();
});

test('keeps a rejected message for retry with a safe failure state', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_request_rejected', false));

    deliver($message, $client);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->delivery_attempts)->toBe(1)
        ->and($message->last_delivery_error)->toBe('telegram_request_rejected');
});

test('rethrows a temporary failure so the queue retries the same message record', function () {
    $message = pendingOutboundMessage();
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new TelegramDeliveryException('telegram_temporary_failure', true));

    expect(fn () => deliver($message, $client))->toThrow(TelegramDeliveryException::class);

    $message->refresh();

    expect($message->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->delivery_attempts)->toBe(1);
});

test('does not send an already sent message again', function () {
    $message = pendingOutboundMessage(['delivery_status' => DeliveryStatus::Sent, 'telegram_message_id' => 789, 'delivered_at' => now()]);
    $client = Mockery::mock(TelegramBotClient::class);
    $client->shouldNotReceive('sendMessage');

    deliver($message, $client);

    expect($message->refresh()->delivery_attempts)->toBe(0);
});

function pendingOutboundMessage(array $attributes = []): Message
{
    $participant = TelegramParticipant::factory()->create();

    return Message::factory()->for($participant, 'participant')->create(array_merge([
        'direction' => MessageDirection::Outbound,
        'author' => MessageAuthor::Bot,
        'body' => 'Сообщение участнику.',
        'delivery_status' => DeliveryStatus::Pending,
    ], $attributes));
}

function deliver(Message $message, TelegramBotClient $client): void
{
    (new DeliverTelegramMessage($message->id))->handle($client, app(TelegramMessagePresentation::class));
}
