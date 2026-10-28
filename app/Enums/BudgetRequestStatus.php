<?php

namespace App\Enums;

enum BudgetRequestStatus: string
{
    case Requested = 'requested';   // client offered a budget, photographer has not answered
    case Proposed = 'proposed';     // photographer sent terms, client has not answered
    case Accepted = 'accepted';     // client accepted the terms: the booking price changed
    case Rejected = 'rejected';     // client turned the terms down
    case Declined = 'declined';     // photographer declined the budget
    case Expired = 'expired';       // the client did not answer the terms in time
    case Superseded = 'superseded'; // closed because the booking was accepted at the listed price
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Proposed], true);
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Proposed->value];
    }
}