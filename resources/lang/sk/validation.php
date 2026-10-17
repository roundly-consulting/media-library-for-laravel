<?php

declare(strict_types=1);

return [
    // Pravidlo SafeRemoteUrl, jeden riadok pre každý verdikt MediaLibrary::remote()->vet().
    'remote_url' => [
        'invalid_url' => 'Pole :attribute musí byť platná URL adresa s protokolom http alebo https.',
        'scheme' => 'Pole :attribute musí používať protokol http alebo https.',
        'credentials' => 'Pole :attribute nesmie obsahovať používateľské meno ani heslo.',
        'non_canonical_host' => 'Pole :attribute musí uvádzať hostiteľa doménovým menom alebo štandardnou IP adresou.',
        'blocked' => 'Pole :attribute nesmie smerovať na súkromnú ani vyhradenú sieťovú adresu.',
        'port' => 'Pole :attribute používa nepovolený port.',
        'unresolved' => 'Hostiteľa v poli :attribute sa nepodarilo nájsť.',
    ],
];
