<?php

namespace App\Enums;

enum BankTransactionStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case PartiallyMatched = 'partially_matched';
    case Matched = 'matched';
    case Classified = 'classified';
    case Ignored = 'ignored';
}
