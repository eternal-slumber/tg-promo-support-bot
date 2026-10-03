<?php

namespace App\Jobs;

use App\Data\SupportLlmRequest;
use App\Exceptions\InvalidLlmDecisionException;
use App\Exceptions\LlmRequestException;
use App\Models\Message;
use App\Models\SupportDecision;
use App\Services\PromotionRules;
use App\Services\SupportDecisionService;
use App\Services\SupportLlmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $messageId)
    {
        $this->onConnection('database')->onQueue('ai')->beforeCommit();
        $this->tries = max(1, min(3, (int) config('llm.max_attempts')));
        $this->timeout = (int) config('llm.timeout') + 10;
        $this->backoff = config('llm.retry_backoff');
    }

    /**
     * Execute the job.
     */
    public function handle(
        SupportLlmClient $client,
        PromotionRules $rules,
        SupportDecisionService $decisions,
    ): void {
        $message = Message::query()->find($this->messageId);

        if ($message === null
            || SupportDecision::query()->where('message_id', $this->messageId)->exists()
            || ! $message->isInboundParticipantMessage()
            || $message->ticket_id !== null) {
            return;
        }

        try {
            $decision = $client->analyze(new SupportLlmRequest($message->body, $rules->content()));
        } catch (InvalidLlmDecisionException $exception) {
            $decisions->failSafeEscalate($message, $rules->hash());
            $this->fail($exception);

            return;
        } catch (LlmRequestException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $decisions->failSafeEscalate($message, $rules->hash());

            return;
        }
        $decisions->apply($message, $decision, $rules->hash());
    }

    public function failed(Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || SupportDecision::query()->where('message_id', $this->messageId)->exists()) {
            return;
        }

        app(SupportDecisionService::class)->failSafeEscalate($message, app(PromotionRules::class)->hash());
    }
}
