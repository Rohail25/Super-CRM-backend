<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Services\TwilioService;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private TwilioService $twilioService
    ) {}

    /**
     * Send a WhatsApp message.
     */
    public function sendWhatsApp(Request $request)
    {
        $validated = $request->validate([
            'to' => 'required|string',
            'message' => 'required|string',
        ]);

        try {
            $result = $this->twilioService->sendWhatsAppMessage(
                $validated['to'],
                $validated['message']
            );

            return response()->json([
                'message' => 'WhatsApp message sent successfully',
                'details' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to send WhatsApp message',
                $e,
                500
            );
        }
    }
}
