<?php
namespace App\Services;

/** Internal financial failure, not an arbitrary HTTP status or transport flag. */
final class ShipmentPostingFailure extends \RuntimeException
{
    public function __construct(\Throwable $cause, public readonly bool $rollbackConfirmed=false)
    {
        parent::__construct($cause->getMessage(),0,$cause);
    }
}
