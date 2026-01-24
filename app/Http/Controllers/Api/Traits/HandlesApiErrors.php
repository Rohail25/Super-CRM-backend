<?php

namespace App\Http\Controllers\Api\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

trait HandlesApiErrors
{
    /**
     * Format error response with detailed error information.
     *
     * @param string $message
     * @param \Exception|null $exception
     * @param int $statusCode
     * @param array $additionalData
     * @return \Illuminate\Http\JsonResponse
     */
    protected function errorResponse(
        string $message,
        ?\Exception $exception = null,
        int $statusCode = 500,
        array $additionalData = []
    ) {
        $response = [
            'message' => $message,
            'error' => null,
        ];

        // Add detailed error information if exception is provided
        if ($exception) {
            $response['error'] = [
                'message' => $exception->getMessage(),
                'type' => get_class($exception),
            ];

            // Add file and line information in debug mode
            if (config('app.debug')) {
                $response['error']['file'] = $exception->getFile();
                $response['error']['line'] = $exception->getLine();
                $response['error']['trace'] = $exception->getTraceAsString();
            }

            // Log the error
            Log::error($message, [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        // Merge additional data
        $response = array_merge($response, $additionalData);

        return response()->json($response, $statusCode);
    }

    /**
     * Handle validation exceptions with detailed error messages.
     *
     * @param ValidationException $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleValidationException(ValidationException $e)
    {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $e->errors(),
            'error' => [
                'message' => 'The given data was invalid.',
                'type' => 'ValidationException',
            ],
        ], 422);
    }

    /**
     * Handle database exceptions with detailed error messages.
     *
     * @param \Exception $e
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function handleDatabaseException(\Exception $e)
    {
        // Handle specific database errors
        if ($e instanceof \Illuminate\Database\QueryException) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            // Handle integrity constraint violations
            if ($errorCode === '23000') {
                if (str_contains($errorMessage, 'Duplicate entry')) {
                    return response()->json([
                        'message' => 'A record with this information already exists.',
                        'error' => [
                            'message' => $errorMessage,
                            'type' => 'DatabaseIntegrityConstraintViolation',
                            'code' => $errorCode,
                        ],
                    ], 422);
                }

                if (str_contains($errorMessage, 'foreign key constraint')) {
                    return response()->json([
                        'message' => 'Cannot perform this operation due to related records.',
                        'error' => [
                            'message' => $errorMessage,
                            'type' => 'DatabaseForeignKeyConstraint',
                            'code' => $errorCode,
                        ],
                    ], 422);
                }
            }
        }

        return null;
    }
}
