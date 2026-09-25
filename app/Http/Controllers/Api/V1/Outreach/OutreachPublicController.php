<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Services\Outreach\OutreachEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OutreachPublicController extends Controller
{
    public function __construct(private readonly OutreachEventService $events) {}

    public function open(string $message, string $token): Response
    {
        $this->events->open($message, $token);

        return response(base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, private']);
    }

    public function click(string $token): RedirectResponse
    {
        return redirect()->away($this->events->click($token));
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $this->events->unsubscribe($token);

        return response('<!doctype html><html><body><h1>Unsubscribed</h1><p>This address will no longer receive outreach from this company.</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
