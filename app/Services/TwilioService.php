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
    private string $whatsAppNumber;
    private ?string $agentPhoneNumber;

    public function __construct()
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $this->phoneNumber = config('services.twilio.phone_number', '');
        $this->whatsAppNumber = config('services.twilio.whatsapp_number', $this->phoneNumber);
        $this->agentPhoneNumber = config('services.twilio.agent_phone_number') ?: null;

        if (empty($accountSid) || empty($authToken)) {
            Log::warning('Twilio credentials not configured');
            $this->client = null;
        } else {
            $this->client = new Client($accountSid, $authToken);
        }
    }

    /**
     * Initiate an outbound call using click-to-call flow.
     * This calls the agent first, then connects to the customer when agent answers.
     * 
     * @param Call $call The call record
     * @param string $customerPhoneNumber The customer's phone number to dial
     * @param string|null $agentPhoneNumber Optional agent phone (defaults to config)
     * @param string|null $fromPhoneNumber Optional from number (defaults to Twilio number)
     */
    public function initiateCall(Call $call, string $customerPhoneNumber, ?string $agentPhoneNumber = null, ?string $fromPhoneNumber = null): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured. Please set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_PHONE_NUMBER in your .env file.');
        }

        // Use agent phone from parameter, config, or throw error
        $agentPhone = $agentPhoneNumber ?? $this->agentPhoneNumber;
        if (empty($agentPhone)) {
            throw new \RuntimeException(
                'Agent phone number is required for click-to-call. ' .
                'Please set TWILIO_AGENT_PHONE_NUMBER in your .env file. ' .
                'This is the phone number that will ring when you click "Call Now".'
            );
        }

        // Store customer phone in call record so TwiML can dial it
        if (!$call->contact_phone) {
            $call->update(['contact_phone' => $customerPhoneNumber]);
        }

        $fromNumber = $fromPhoneNumber ?? $this->phoneNumber;
        
        // Get webhook URL - prefer TWILIO_WEBHOOK_URL, fallback to APP_URL
        $webhookBaseUrl = config('services.twilio.webhook_url') 
            ?: config('app.url', env('APP_URL', 'http://localhost'));
        
        // Validate URL is publicly accessible (not localhost for production)
        if (str_contains($webhookBaseUrl, 'localhost') || str_contains($webhookBaseUrl, '127.0.0.1')) {
            throw new \RuntimeException(
                'Twilio requires a publicly accessible URL. Localhost URLs are not allowed. ' .
                'For local development, please use ngrok or set TWILIO_WEBHOOK_URL in your .env file to a public URL. ' .
                'Example: TWILIO_WEBHOOK_URL=https://your-ngrok-url.ngrok.io'
            );
        }
        
        // Ensure URL uses HTTPS (required by Twilio)
        if (str_starts_with($webhookBaseUrl, 'http://') && !str_contains($webhookBaseUrl, 'localhost')) {
            $webhookBaseUrl = str_replace('http://', 'https://', $webhookBaseUrl);
        }
        
        // Remove trailing slash
        $webhookBaseUrl = rtrim($webhookBaseUrl, '/');
        
        // Webhook URL for call status updates
        $statusCallbackUrl = $webhookBaseUrl . '/api/calls/twilio/status';
        
        // TwiML URL for call instructions (will dial customer when agent answers)
        $twimlUrl = $webhookBaseUrl . '/api/calls/twilio/twiml?call_id=' . $call->id;

        try {
            // Click-to-call: Call the AGENT first (not the customer)
            // When agent answers, TwiML will dial the customer
            $twilioCall = $this->client->calls->create(
                $agentPhone,    // To: Agent's phone (will ring first)
                $fromNumber,    // From: Your Twilio number
                [
                    'url' => $twimlUrl,  // TwiML that dials customer when agent answers
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
                'agent_phone' => $agentPhone,
                'customer_phone' => $customerPhoneNumber,
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
     * 
     * @param string|null $phoneNumber The phone number to dial (in E.164 format)
     * @param string $message Optional message to play before connecting
     */
    public function generateTwiML(?string $phoneNumber = null, string $message = 'Connecting your call.'): string
    {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        
        // Play a brief message if provided
        if ($message) {
            $twiml .= '<Say voice="alice">' . htmlspecialchars($message) . '</Say>';
            $twiml .= '<Pause length="1"/>';
        }
        
        // Dial the customer's number if provided
        if ($phoneNumber) {
            // Ensure number is in E.164 format (remove any spaces, dashes, etc.)
            $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
            
            // Add + if not present (assuming it's a valid number)
            if (!str_starts_with($phoneNumber, '+')) {
                $phoneNumber = '+' . $phoneNumber;
            }
            
            $twiml .= '<Dial>';
            $twiml .= '<Number>' . htmlspecialchars($phoneNumber) . '</Number>';
            $twiml .= '</Dial>';
        } else {
            // Fallback if no number provided
            $twiml .= '<Say voice="alice">No phone number available to dial.</Say>';
        }
        
        $twiml .= '</Response>';
        
        return $twiml;
    }

    /**
     * Send a WhatsApp message.
     */
    public function sendWhatsAppMessage(string $to, string $message): array
    {
        if (!$this->client) {
            throw new \RuntimeException('Twilio is not configured.');
        }

        // Ensure numbers are in E.164 format and prefixed with "whatsapp:"
        $to = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:' . $to;
        $from = str_starts_with($this->whatsAppNumber, 'whatsapp:') ? $this->whatsAppNumber : 'whatsapp:' . $this->whatsAppNumber;

        try {
            $msg = $this->client->messages->create(
                $to,
                [
                    'from' => $from,
                    'body' => $message,
                ]
            );

            return [
                'success' => true,
                'sid' => $msg->sid,
                'status' => $msg->status,
            ];
        } catch (TwilioException $e) {
            Log::error('Twilio WhatsApp message failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to send WhatsApp message: ' . $e->getMessage());
        }
    }
}
