<?php

declare(strict_types=1);

namespace WhollyCrypto\OpenCart\Sdk\Http;

use WhollyCrypto\OpenCart\Sdk\Options;

interface TransportInterface
{
    public function send(
        #[\SensitiveParameter]
        Request $request,
        Options $options
    ): Response;
}
