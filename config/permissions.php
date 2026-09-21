<?php

return [
    'catalog' => [
        'green-zone.view' => ['name' => 'Зелёная зона: страница', 'description' => 'Доступна всем активным пользователям.', 'assignable' => false],
        'green-zone.read' => ['name' => 'Зелёная зона: таблица', 'description' => 'Только по расширенной подписке.', 'assignable' => false],
        'green-zone.import' => ['name' => 'Зелёная зона: импорт', 'description' => 'Импорт XLS/XLSX в общую базу.', 'assignable' => true],
        'rrj-express.view' => ['name' => 'RRJ-EXPRESS: страница', 'description' => 'Доступна всем активным пользователям.', 'assignable' => false],
        'rrj-express.read' => ['name' => 'RRJ-EXPRESS: таблица', 'description' => 'Только по расширенной подписке.', 'assignable' => false],
        'rrj-express.import' => ['name' => 'RRJ-EXPRESS: импорт', 'description' => 'Импорт XLS/XLSX в общую базу.', 'assignable' => true],
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
        'history.view' => [
            'name' => 'Хронология изменений',
            'description' => 'Просмотр изменений рабочего плана и времени их подтверждения.',
            'assignable' => true,
        ],
        'airfase.view' => [
            'name' => 'AirFASE: страница',
            'description' => 'Доступна всем активным пользователям.',
            'assignable' => false,
        ],
        'airfase.read' => [
            'name' => 'AirFASE: таблица',
            'description' => 'Только по расширенной подписке.',
            'assignable' => false,
        ],
        'airfase.import' => [
            'name' => 'AirFASE: импорт',
            'description' => 'Загрузка XLS/XLSX в общую базу. Требует доступа к странице.',
            'assignable' => true,
        ],
        'users.view' => [
            'name' => 'Просмотр пользователей',
            'description' => 'Просмотр списка пользователей без изменения статусов и ролей.',
            'assignable' => true,
        ],
        'users.manage' => [
            'name' => 'Управление пользователями',
            'description' => 'Подтверждение регистрации, изменение статусов и должности пилота. Не позволяет назначать роли доступа. Требует просмотра пользователей.',
            'assignable' => true,
        ],
        'roles.manage' => [
            'name' => 'Управление ролями и разрешениями',
            'description' => 'Только системный администратор: создание ролей, настройка разрешений и назначение ролей пользователям.',
            'assignable' => false,
        ],
    ],
    'defaults' => ['dashboard.view', 'profile.view', 'workplan.view', 'history.view'],
];
