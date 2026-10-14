<?php

namespace App\Services\Fbr;

use App\Contracts\FbrCreditNoteGateway;
use App\Exceptions\FbrUnavailableException;
use App\Models\PakistanFbrCreditNote;

class UnavailableFbrCreditNoteGateway implements FbrCreditNoteGateway
{
    public function submit(PakistanFbrCreditNote $creditNote, string $idempotencyKey): FbrSubmissionResult
    {
        throw new FbrUnavailableException('FBR Credit Note provider transmission is not certified.');
    }
}
