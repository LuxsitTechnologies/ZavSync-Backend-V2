<?php

namespace App\Contracts;

use App\Models\PakistanFbrCreditNote;
use App\Services\Fbr\FbrSubmissionResult;

interface FbrCreditNoteGateway
{
    public function submit(PakistanFbrCreditNote $creditNote, string $idempotencyKey): FbrSubmissionResult;
}
