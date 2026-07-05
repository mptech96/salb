<?php

namespace App\Domain\Accounting\Enums;

enum JournalStatus: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case CANCELLED = 'CANCELLED';
}