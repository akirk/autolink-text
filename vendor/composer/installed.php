<?php return array(
    'root' => array(
        'name' => 'akirk/auto-linker',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'akirk/auto-linker' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'maxschmeling/y-php' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => '4515ef1f427d050f070430cf8ff1a5f1355731aa',
            'type' => 'library',
            'install_path' => __DIR__ . '/../maxschmeling/y-php',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
