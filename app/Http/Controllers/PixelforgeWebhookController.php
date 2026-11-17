<?php

namespace App\Http\Controllers;

use App\Actions\ApplyPixelforgeWebhook;
use App\Services\Pixelforge\PixelforgeWebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use JsonException;
use OverflowException;
use UnexpectedValueException;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixelforgeWebhookSignature $signature,
        ApplyPixelforgeWebhook $apply,
    ): Response {
        $body = $request->getContent();
        $headers = $request->headers->all('pixelforge-signature');

        if (count($headers) !== 1 || ! $signature->isValid($headers[0], $body)) {
            return response()->noContent(400);
        }

        try {
            $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($event) || array_is_list($event)) {
                return response()->noContent(422);
            }

            $apply->handle($event);
        } catch (JsonException|ValidationException|UnexpectedValueException|OverflowException) {
            return response()->noContent(422);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent(409);
        }

        return response()->noContent();
    }
}
