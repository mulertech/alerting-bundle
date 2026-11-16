<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Throttle;

enum Verdict
{
    /** Sent through the channels. */
    case Send;

    /** The same fault was sent within the window: counted, and reported with the next one sent. */
    case Suppress;

    /** This alert reaches the cap: the channels receive a notice of it, the alert goes to standard error. */
    case CapReached;

    /** The cap was already reached in this window: standard error only. */
    case OverCap;
}
