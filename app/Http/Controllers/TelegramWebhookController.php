<?php

namespace App\Http\Controllers;

use App\Services\TelegramIngestionService;
use App\Services\TelegramUpdateParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        TelegramUpdateParser $parser,
        TelegramIngestionService $ingestion,
    ): JsonResponse {
        $secret = config('telegram.webhook_secret');
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_unless(is_string($secret) && $secret !== '' && is_string($providedSecret) && hash_equals($secret, $providedSecret), 403);

        $update = $parser->parse($request->json()->all());

        if ($update === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'malformed_update'], 422);
        }

        $result = $ingestion->ingest($update);

        return response()->json([
            'status' => $result->duplicate ? 'duplicate' : ($result->ignored ? 'ignored' : 'accepted'),
        ]);
    }
}
