<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Backend User Administration',
    'description' => 'Backend user administration and overview. Allows you to compare the settings of users and verify their permissions and see who is online.',
    'category' => 'module',
    'author' => 'Felix Kopp',
    'author_email' => 'felix-source@phorax.com',
    'author_company' => 'PHORAX',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '9.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '9.0.0-9.0.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
