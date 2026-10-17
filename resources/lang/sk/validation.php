<?php

declare(strict_types=1);

return [
    // Pravidlo SafeRemoteUrl: jeden riadok pre každé odmietnutie, aby používateľ nezistil, ktoré
    // interné mená existujú. Presný dôvod vráti MediaLibrary::remote()->vet().
    'remote_url' => 'Pole :attribute musí byť verejná a dostupná URL adresa s protokolom http alebo https.',
];
