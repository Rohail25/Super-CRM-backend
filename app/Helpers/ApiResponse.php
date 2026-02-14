<?php

namespace App\Helpers;

use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ApiResponse
{
    public static function success(array $data = [], int $status = 200)
    {
        return response()->json($data, $status);
    }

    public static function error(
        string $message,
        ?\Exception $exception = null,
        int $status = 500,
        array $extra = []
    ) {
        $response = [
            'message' => $message,
            'error' => null,
        ];

        if ($exception) {
            $response['error'] = [
                'message' => $exception->getMessage(),
                'type' => get_class($exception),
            ];

            if (config('app.debug')) {
                $response['error']['file'] = $exception->getFile();
                $response['error']['line'] = $exception->getLine();
                $response['error']['trace'] = $exception->getTraceAsString();
            }

            Log::error($message, [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        $response = array_merge($response, $extra);

        return response()->json($response, $status);
    }

    public static function validation(ValidationException $e)
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

    public static function database(\Exception $e)
    {
        if ($e instanceof QueryException) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

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
