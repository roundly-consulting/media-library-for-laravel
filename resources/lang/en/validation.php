<?php

declare(strict_types=1);

return [
    // The SafeRemoteUrl rule, one line per verdict of MediaLibrary::remote()->vet().
    'remote_url' => [
        'invalid_url' => 'The :attribute field must be a valid http or https URL.',
        'scheme' => 'The :attribute field must use http or https.',
        'credentials' => 'The :attribute field must not contain a user name or password.',
        'non_canonical_host' => 'The :attribute field must name its host by a domain name or a standard IP address.',
        'blocked' => 'The :attribute field must not point at a private or reserved network address.',
        'port' => 'The :attribute field uses a port that is not allowed.',
        'unresolved' => 'The host in the :attribute field could not be found.',
    ],
];
