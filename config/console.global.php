<?php

use OpenAPI\Generator\Documentation\Generate;

return [
    'commands' => [
        [
            'name' => 'generate-swagger',
            'summary' => 'Generate swagger documentation based on the routes',
            'description' => 'Read the routes configuration and build swagger documentation based upon it',
            'handler' => Generate::class,
            'parameters' => [
                '--directory | --dir | -d' => [
                    'description' => 'the directory in which to store the file',
                    'type' => 'string',
                ],
                '--filename | --file | -f' => [
                    'description' => 'The filename (incl. extension) under which to save the file',
                    'type' => 'string',
                ],
            ],
        ],
    ],
];
