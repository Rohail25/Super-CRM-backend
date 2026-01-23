<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class TwilioService
{
    private ?Client $client;
    private string $phoneNumber;

    public function __construct()
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $this->phoneNumber = config('services.twilio.phone_number', '');

        if (empty($accountSid) || empty($authToken)) {
            Log::warning('Twilio credentials not configured');
            $this->client = null;
        } else {
            $this->client = new Client($accountSid, $authToken);
        }
    }

    /**
     * Initiate an outbound call.
     */
    public function initiateCall(Call $call, string $toPhoneNumber, ?string $fromPhoneNumber = null): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured. Please set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_PHONE_NUMBER in your .env file.');
        }

        $fromNumber = $fromPhoneNumber ?? $this->phoneNumber;
        $appUrl = config('app.url', env('APP_URL', 'http://localhost'));
        
        // Webhook URL for call status updates
        $statusCallbackUrl = $appUrl . '/api/calls/twilio/status';
        
        // TwiML URL for call instructions (we'll create this endpoint)
        $twimlUrl = $appUrl . '/api/calls/twilio/twiml?call_id=' . $call->id;

        try {
            $twilioCall = $this->client->calls->create(
                $toPhoneNumber, // To
                $fromNumber,    // From
                [
                    'url' => $twimlUrl,
                    'statusCallback' => $statusCallbackUrl,
                    'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                    'statusCallbackMethod' => 'POST',
                    'record' => true, // Record the call
                ]
            );

            // Update call record with Twilio SID
            $call->update([
                'status' => 'in_progress',
                'started_at' => now(),
                'notes' => ($call->notes ?? '') . "\nTwilio Call SID: " . $twilioCall->sid,
            ]);

            return [
                'success' => true,
                'call_sid' => $twilioCall->sid,
                'status' => $twilioCall->status,
                'message' => 'Call initiated successfully',
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio call initiation failed', [
                'call_id' => $call->id,
                'to' => $toPhoneNumber,
                'error' => $e->getMessage(),
            ]);

            // Update call status to failed
            $call->update([
                'status' => 'cancelled',
                'notes' => ($call->notes ?? '') . "\nCall failed: " . $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to initiate call: ' . $e->getMessage());
        }
    }

    /**
     * Get call status from Twilio.
     */
    public function getCallStatus(string $callSid): ?array
    {
        if (!$this->client) {
            return null;
        }

        try {
            $call = $this->client->calls($callSid)->fetch();
            
            return [
                'sid' => $call->sid,
                'status' => $call->status,
                'duration' => $call->duration,
                'start_time' => $call->startTime?->format('Y-m-d H:i:s'),
                'end_time' => $call->endTime?->format('Y-m-d H:i:s'),
            ];
        } catch (TwilioException $e) {
            Log::error('Failed to fetch Twilio call status', [
                'call_sid' => $callSid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate TwiML for the call.
     */
    public function generateTwiML(string $message = 'Hello, this is a call from your CRM.'): string
    {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        $twiml .= '<Say voice="alice">' . htmlspecialchars($message) . '</Say>';
        $twiml .= '<Pause length="2"/>';
        $twiml .= '<Say voice="alice">Please hold while we connect you.</Say>';
        $twiml .= '</Response>';
        
        return $twiml;
    }
}

