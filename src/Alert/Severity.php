<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Alert;

enum Severity: string
{
    case Error = 'error';
    case Critical = 'critical';
}
