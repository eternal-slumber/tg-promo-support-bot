<?php

namespace App\Services;

use App\Data\TelegramOutboundMessage;
use App\Data\TelegramSentMessage;
use App\Exceptions\TelegramDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramBotApiClient implements TelegramBotClient
{
    public function sendMessage(TelegramOutboundMessage $message): TelegramSentMessage
    {
        if ($message->exceedsTextLimit()) {
            throw new TelegramDeliveryException('telegram_message_too_long', false);
        }

        $response = $this->request('sendMessage', [
            'chat_id' => $message->chatId,
            'text' => $message->text,
            'reply_markup' => ['remove_keyboard' => true],
        ]);
        $messageId = data_get($response, 'result.message_id');

        if (! is_int($messageId)) {
            throw new TelegramDeliveryException('telegram_invalid_response', false);
        }

        return new TelegramSentMessage($messageId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('telegram.connect_timeout'))
                ->timeout((int) config('telegram.timeout'))
                ->post(rtrim((string) config('telegram.api_base_url'), '/').'/bot'.config('telegram.bot_token').'/'.$method, $payload);
        } catch (ConnectionException) {
            throw new TelegramDeliveryException('telegram_connection_failed', true);
        }

        if ($response->status() === 429) {
            $retryAfter = data_get($response->json(), 'parameters.retry_after');

            throw new TelegramDeliveryException('telegram_rate_limited', true, is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : 60);
        }

        if ($response->serverError()) {
            throw new TelegramDeliveryException('telegram_temporary_failure', true);
        }

        if (! $response->successful()) {
            throw new TelegramDeliveryException('telegram_request_rejected', false);
        }

        $body = $response->json();

        if (! is_array($body) || data_get($body, 'ok') !== true) {
            throw new TelegramDeliveryException('telegram_invalid_response', false);
        }

        return $body;
    }
}
