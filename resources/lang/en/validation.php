<?php

declare(strict_types=1);

return [
    // The SafeRemoteUrl rule: one line for every refusal, so a user cannot tell which internal
    // names exist. MediaLibrary::remote()->vet() gives the exact reason.
    'remote_url' => 'The :attribute field must be a public http or https URL that can be reached.',
];
