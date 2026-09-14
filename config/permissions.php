<?php

return [
    'catalog' => [
        'green-zone.view' => ['name' => 'Зелёная зона: страница', 'description' => 'Открытие страницы отчётов.', 'assignable' => true],
        'green-zone.read' => ['name' => 'Зелёная зона: таблица', 'description' => 'Просмотр и фильтрация общей базы.', 'assignable' => true],
        'green-zone.import' => ['name' => 'Зелёная зона: импорт', 'description' => 'Импорт XLS/XLSX в общую базу.', 'assignable' => true],
        'rrj-express.view' => ['name' => 'RRJ-EXPRESS: страница', 'description' => 'Открытие страницы отчётов.', 'assignable' => true],
        'rrj-express.read' => ['name' => 'RRJ-EXPRESS: таблица', 'description' => 'Просмотр и фильтрация общей базы.', 'assignable' => true],
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
        'deviations.view' => [
            'name' => 'Отклонения: страница',
            'description' => 'Открытие страницы отклонений. Чтение таблицы и импорт выдаются отдельно.',
            'assignable' => true,
        ],
        'deviations.read' => [
            'name' => 'Отклонения: таблица',
            'description' => 'Просмотр общей базы отклонений, фильтрация и сортировка. Требует доступа к странице.',
            'assignable' => true,
        ],
        'deviations.import' => [
            'name' => 'Отклонения: импорт',
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
