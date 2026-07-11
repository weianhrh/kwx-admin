<?php
declare(strict_types=1);

// 必须与 RC /review/_config.php 的 shared_secret 完全一致。
return [
    'shared_secret' => 'efa7b1040817b3717a0e49030cb814ef9976b915e06d692909323cfafafb9c19',
    'allowed_admin_roles' => [1, 2],
    'allowed_targets' => [
        '/res/pidtrueAndtextPedding.html',
        '/res/reporthand.html',
        '/res/ai_pratol.html',
        '/res/pop_back.html',
        '/res/device_violation_review.html',
        '/res/ban_Record.html',
    ],
];
