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
            'message' => 'required_without:template_sid|nullable|string',
            'template_sid' => 'required_without:message|nullable|string',
            'template_variables' => 'nullable|array',
            'media_urls' => 'nullable|array',
        ]);

        try {
            $result = $this->twilioService->sendWhatsAppMessage(
                $validated['to'],
                $validated['message'] ?? '',
                $validated['media_urls'] ?? null,
                $validated['template_sid'] ?? null,
                $validated['template_variables'] ?? null
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
