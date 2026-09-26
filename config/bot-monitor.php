<?php

return [
    // Private, raw client/case and Alex ⇄ bot Teams conversations only for the
    // specifically approved Jinx owner; other authenticated users remain redacted.
    'owner_user_id' => (int) env('BOT_MONITOR_OWNER_USER_ID', 0),
];
