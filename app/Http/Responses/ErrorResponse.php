<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ErrorResponse
{
    /**
     * Create an error response
     *
     * @param  mixed  $errors
     */
    public static function make(string $message = 'Error', $errors = null, int $statusCode = 400, $input = null): JsonResponse
    {
        $response = [
            'status' => false,
            'code' => $statusCode,
            'message' => $message,
        ];

        // Only include data for non-validation errors (not 422)
        if ($input !== null && $statusCode !== 422) {
            $response['data'] = self::sanitizeInputData($input);
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Remove sensitive fields from input data.
     *
     * @param  array|\Illuminate\Http\Request  $input
     */
    protected static function sanitizeInputData($input): ?array
    {
        if (is_null($input)) {
            return null;
        }

        if ($input instanceof \Illuminate\Http\Request) {
            $data = $input->all();
        } elseif (is_array($input)) {
            $data = $input;
        } else {
            // Attempt to convert other types to array
            $data = (array) $input;
        }

        // Remove common password fields
        unset($data['password'], $data['password_confirmation'], $data['password_confirm']);

        return $data;
    }
}
