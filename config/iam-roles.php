<?php

declare(strict_types=1);

return [
    'CRS.Registrar' => [
        'name' => 'CRS Registrar',
        'description' => 'Can create and edit civil registration records.',
        'permissions' => [
            'crs.birth.create',
            'crs.birth.edit',
        ],
    ],

    'CRS.Approver' => [
        'name' => 'CRS Approver',
        'description' => 'Can approve civil registration records.',
        'permissions' => [
            'crs.birth.approve',
        ],
    ],
];
