<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Support;

final readonly class OutboundMessage
{
    /** @param list<string> $params template parameters in the order the template expects */
    public function __construct(
        public string $id,
        public string $to,
        public string $body,
        public ?string $template,
        public ?string $language,
        public array $params,
    ) {}
}
