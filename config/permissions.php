<?php

return [
    'catalog' => [
        'dashboard.view' => [
            'name' => 'Дашборд',
            'description' => 'Просмотр сводки и ближайших событий.',
            'assignable' => true,
        ],
        'profile.view' => [
            'name' => 'Профиль',
            'description' => 'Просмотр собственного профиля.',
            'assignable' => false,
        ],
        'workplan.view' => [
            'name' => 'Рабочий план',
            'description' => 'Просмотр актуального рабочего плана.',
            'assignable' => true,
        ],
        'users.manage' => [
            'name' => 'Управление пользователями',
            'description' => 'Системное право локального администратора.',
            'assignable' => false,
        ],
    ],
    'defaults' => ['dashboard.view', 'profile.view', 'workplan.view'],
];
