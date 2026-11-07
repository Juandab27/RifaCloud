<?php

declare(strict_types=1);

try {
    new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'mysql',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: 'rifacloud',
        ),
        getenv('DB_USERNAME') ?: 'rifacloud',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_TIMEOUT => 3],
    );
} catch (Throwable) {
    exit(1);
}

exit(0);
