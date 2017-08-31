<?php

return [
    'tz' => [
        'instanceOf' => '\DateTimeZone',
        'construct'  => ['Europe/London']
    ],
    'dt' => [
        'instanceOf' => '\DateTime',
        'construct'  => ['now', ['instanceOf' => '\DateTimeZone', 'construct' => ['Pacific/Nauru']]]
    ],
    'dt2' => [
        'instanceOf' => '\DateTime',
        'construct'  => ['now', ['instanceOf' => 'tz']]
    ]
];