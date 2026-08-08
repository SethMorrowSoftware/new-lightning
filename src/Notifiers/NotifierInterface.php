<?php
declare(strict_types=1);

namespace StormWatch\Notifiers;

use StormWatch\Alert;

interface NotifierInterface
{
    /** Short channel name used in the event log, e.g. "slack". */
    public static function channel(): string;

    /** Is this channel configured and switched on? */
    public static function isEnabled(): bool;

    /**
     * Deliver an alert.
     *
     * @return array{ok:bool,message:string}
     */
    public static function send(Alert $alert): array;
}
