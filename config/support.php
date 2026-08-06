<?php

return [
    // Shown to buyers in transactional emails. Falls back to the "from"
    // address when unset, so preview environments still render a valid
    // human-readable contact line.
    'contact_email' => env('SUPPORT_CONTACT_EMAIL'),
];
