<?php

namespace App\Services\Accounting;

class PostingResult
{
    public bool $success = false;

    public ?int $journalEntryId = null;

    public ?int $voucherId = null;

    public string $message = '';

    public array $data = [];

    public static function success(
        string $message='',
        ?int $journalEntryId=null,
        ?int $voucherId=null,
        array $data=[]
    ): self
    {
        $r = new self();

        $r->success = true;

        $r->message = $message;

        $r->journalEntryId = $journalEntryId;

        $r->voucherId = $voucherId;

        $r->data = $data;

        return $r;
    }

    public static function error(string $message): self
    {
        $r = new self();

        $r->success = false;

        $r->message = $message;

        return $r;
    }
}