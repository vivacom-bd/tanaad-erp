<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

final class BriefRules
{
    /** copy = the CEO copied the text to send it by hand; whatsapp = sent by the messaging driver; log = log driver. */
    public const array CHANNELS = ['copy', 'whatsapp', 'log'];

    public const int MAX_LINES = 200;

    public const int MAX_LINE = 500;
}
