<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageAuthor;
use App\Enums\TicketCloseReason;
use App\Enums\TicketStatus;
use App\Livewire\OperatorDashboard;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('smoke runs webhook database workers operator replies explicit resolution auto closure and statistics', function () {
    $this->freezeTime();
    config()->set('telegram.webhook_secret', 'smoke-secret');
    config()->set('telegram.bot_token', 'smoke-token');
    config()->set('telegram.api_base_url', 'https://telegram.example');
    config()->set('llm.endpoint', 'https://llm.example/smoke');
    Http::preventStrayRequests();
    $content = fn (array $decision): array => ['choices' => [['message' => ['content' => json_encode($decision, JSON_THROW_ON_ERROR)]]]];
    Http::fake([
        'https://llm.example/smoke' => Http::sequence()
            ->push($content(['decision' => 'answer', 'reason' => 'rule_answer', 'answer' => 'Кефир не участвует.', 'evidence' => [['rule_id' => '4.2', 'quote' => 'Другая продукция']]]))
            ->push($content(['decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => []]))
            ->push($content(['decision' => 'escalate', 'reason' => 'participant_specific', 'answer' => null, 'evidence' => []])),
        'https://telegram.example/botsmoke-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1234]]),
    ]);
    $worker = function (string $queue): void {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
    };
    $incoming = function (int $id, string $text): void {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'smoke-secret')->postJson(route('telegram.webhook'), [
            'update_id' => $id,
            'message' => ['message_id' => $id, 'from' => ['id' => 71001], 'chat' => ['id' => 71001, 'type' => 'private'], 'text' => $text],
        ])->assertOk()->assertJsonPath('status', 'accepted');
    };

    $incoming(1, 'Кефир участвует?');
    $worker('ai');
    $worker('telegram');
    $this->assertDatabaseCount('tickets', 0);
    expect(Message::query()->where('author', MessageAuthor::Bot)->sole()->delivery_status)->toBe(DeliveryStatus::Sent);
    $incoming(2, 'Проверьте мой чек');
    $worker('ai');
    $worker('telegram');
    $ticket = Ticket::query()->sole();
    $this->actingAs(User::factory()->create());
    $panel = Livewire::test(OperatorDashboard::class)->call('selectTicket', $ticket->id);
    $this->travel(2)->minutes();
    $panel->set('replyBody', 'Ответ оператора')->call('sendReply')->assertHasNoErrors();
    $worker('telegram');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $panel->call('resolveTicket')->assertHasNoErrors();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
    $incoming(3, 'Не решило');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $panel->set('replyBody', 'Уточнённый ответ')->call('sendReply')->assertHasNoErrors();
    $worker('telegram');
    $incoming(4, 'Проблема решена');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $panel->call('closeTicket')->assertHasNoErrors();
    expect($ticket->refresh()->close_reason)->toBe(TicketCloseReason::OperatorClosed);
    $incoming(5, 'Теперь вопрос о доставке');
    $worker('ai');
    $worker('telegram');
    $secondTicket = Ticket::query()->latest('id')->firstOrFail();
    $panel->call('selectTicket', $secondTicket->id);
    $this->travel(4)->minutes();
    $panel->set('replyBody', 'Ответ о доставке')->call('sendReply')->assertHasNoErrors();
    $worker('telegram');
    $panel->call('resolveTicket')->assertHasNoErrors();
    $this->travel(24)->hours();
    $worker('maintenance');
    expect($secondTicket->refresh()->close_reason)->toBe(TicketCloseReason::AutoClosed);
    $panel->call('$refresh')->assertViewHas('statistics', [
        'bot_resolved' => 1, 'bot_prepared' => 1, 'bot_pending' => 0, 'bot_failed' => 0, 'bot_cancelled' => 0,
        'escalated' => 2, 'average_operator_response_seconds' => 180.0, 'operator_cancelled' => 0,
    ]);
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(Message::query()->where('direction', 'outbound')->where('delivery_status', '!=', DeliveryStatus::Sent)->count())->toBe(0);
    Http::assertSentCount(9);
});
