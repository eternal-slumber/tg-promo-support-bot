<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidLlmDecisionException;
use App\Exceptions\LlmRequestException;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Message;
use App\Services\SensitiveDataSanitizer;
use App\Services\TelegramIngestionService;
use App\Services\TelegramMessagePresentation;
use App\Services\TelegramUpdateParser;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class EvaluateSupport extends Command
{
    protected $signature = 'support:evaluate {--output= : Markdown report path}';

    protected $description = 'Evaluate all 25 assignment requests using the configured LLM without Telegram delivery or retained fixtures';

    public function handle(SensitiveDataSanitizer $sanitizer, TelegramUpdateParser $parser, TelegramIngestionService $ingestion, TelegramMessagePresentation $presentation): int
    {
        preg_match_all('/\*\*(\d+)\.\*\*\s+([^\n]+)/u', file_get_contents(base_path('docs/assignment/requests.md')), $requests, PREG_SET_ORDER);

        if (count($requests) !== 25) {
            $this->error('Expected exactly 25 assignment requests.');

            return self::FAILURE;
        }

        $report = "# Evaluation обращений\n\n";
        $report .= 'Дата UTC: '.now()->toIso8601String()."\n\n";
        $report .= 'Модель: '.config('llm.model')."\n\n";
        $report .= 'Реальный LLM; штатный database worker применяет attempts/backoff/fallback ProcessIncomingMessage. Telegram не вызывается, записи откатываются. Model result содержит валидированную классификацию и ошибки validation; infrastructure failure reason содержит транспортные/HTTP сбои. Оценки требуют ручной проверки.'."\n\n";
        $report .= "| № | Вопрос (маскированный) | Model result | Infrastructure failure reason | Попытки | Ответ бота | Передано оператору | Оценка | Комментарий |\n|---|---|---|---|---|---|---|---|---|\n";
        $baseId = random_int(1_000_000_000, 2_000_000_000);
        $queueManager = Queue::getFacadeRoot();
        $worker = app('queue.worker');
        $evaluationQueue = null;
        $failures = [];
        $invalidResults = [];
        $attempts = 0;
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$evaluationQueue, &$attempts): void {
            if ($event->job->getQueue() === $evaluationQueue) {
                $attempts++;
            }
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use (&$evaluationQueue, &$failures): void {
            if ($event->job->getQueue() === $evaluationQueue) {
                $failures[] = $event->exception instanceof LlmRequestException
                    ? $event->exception->getMessage() : $event->exception::class;
            }
        });
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$evaluationQueue, &$invalidResults): void {
            if ($event->job->getQueue() === $evaluationQueue && $event->exception instanceof InvalidLlmDecisionException) {
                $invalidResults[] = $event->exception->getMessage();
            }
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$evaluationQueue, &$failures): void {
            if ($evaluationQueue !== null && $event->request->url() === config('llm.endpoint') && $event->response->failed()) {
                $failures[] = 'LLM HTTP '.$event->response->status();
            }
        });

        foreach ($requests as $request) {
            Queue::fake();
            $evaluationQueue = 'evaluation-'.$baseId.'-'.$request[1];
            $failures = [];
            $invalidResults = [];
            $attempts = 0;
            DB::beginTransaction();

            try {
                $id = $baseId + (int) $request[1];
                $update = $parser->parse([
                    'update_id' => $id,
                    'message' => ['message_id' => $id, 'from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $request[2]],
                ]);
                $result = $ingestion->ingest($update);
                $databaseQueue = $queueManager->connection('database');
                $databaseQueue->pushOn($evaluationQueue, (new ProcessIncomingMessage($result->messageId))->onQueue($evaluationQueue));
                while ($databaseQueue->size($evaluationQueue) > 0) {
                    $worker->runNextJob('database', $evaluationQueue, new WorkerOptions(sleep: 1));
                }

                $message = Message::query()->findOrFail($result->messageId);
                $answers = Message::query()->where('participant_id', $message->participant_id)->where('direction', 'outbound')->orderBy('id')->get()
                    ->map(fn (Message $outbound): string => $presentation->present($outbound)->text)->implode("\n\n");
                $decision = $message->decision;
                $modelResult = $decision?->reason === 'llm_failure' ? 'нет валидного результата' : $decision?->type->value.' / '.$decision?->reason;
                if ($invalidResults !== []) {
                    $modelResult .= '; validation: '.implode('; ', array_unique($invalidResults));
                }
                $columns = [$request[1], $sanitizer->sanitize($request[2])->text, $modelResult, implode('; ', array_unique($failures)) ?: 'нет', (string) $attempts, $answers, $message->ticket_id === null ? 'нет' : 'да', 'требует проверки', $decision?->reason ?? 'нет решения'];
                $report .= '| '.implode(' | ', array_map(fn (string $value): string => str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], $value), $columns))." |\n";
                $this->components->info('Evaluated request '.$request[1].'/25');
            } finally {
                DB::rollBack();
                $evaluationQueue = null;
                Queue::swap($queueManager);
            }
        }

        if ($path = $this->option('output')) {
            if (file_put_contents($path, $report) === false) {
                $this->error('Could not write report.');

                return self::FAILURE;
            }
        } else {
            $this->output->write($report);
        }

        return self::SUCCESS;
    }
}
