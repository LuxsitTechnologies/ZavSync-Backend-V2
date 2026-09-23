<?php

namespace App\Enums;

enum FbrSubmissionStatus: string
{
    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
