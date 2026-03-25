<?php
/**
 * CI4 Installer Configuration
 *
 * Customize this file for your CodeIgniter 4 application.
 * See the README for full documentation of all options.
 */
return [
    // --- Branding ---
    'branding' => [
        'name'          => 'My CI4 App',
        'version'       => '1.0.0',
        'logo'          => '',  // filename or URL, or empty for no logo
        'support_url'   => '',
        'support_email' => '',
        'welcome_text'  => '',  // empty = auto-generated from app name
        'colors'        => [
            'primary'   => '#570DF8',
            'secondary' => '#F000B8',
            'accent'    => '#37CDBE',
            'neutral'   => '#3D4451',
            'base-100'  => '#FFFFFF',
            'base-200'  => '#F2F2F2',
            'base-300'  => '#E5E6E6',
        ],
    ],

    // --- Download Source ---
    // source.zip is REQUIRED. composer and git are optional enhancements.
    'source' => [
        'composer' => '',  // Packagist package name, e.g. 'vendor/package'
        'git'      => '',  // Git repository URL
        'zip'      => '',  // REQUIRED: Direct download URL to a zip file
    ],

    // --- Server Requirements ---
    'requirements' => [
        'php'        => '8.2',
        'extensions' => ['curl', 'mbstring', 'intl', 'json', 'fileinfo', 'openssl'],
        'databases'  => ['MySQLi'],  // Options: MySQLi, Postgre, SQLite3, SQLSRV
    ],

    // --- Writable Directories ---
    'writable_dirs'            => [
        'writable/cache',
        'writable/logs',
        'writable/session',
        'writable/uploads',
    ],
    'writable_dir_permissions' => 0755,

    // --- Custom ENV Variables ---
    // Each entry creates a form field in the wizard.
    // Types: text, password, email, url, select, boolean
    'env_vars' => [
        // Example:
        // [
        //     'key'      => 'app.myKey',
        //     'label'    => 'My Setting',
        //     'type'     => 'text',
        //     'required' => false,
        //     'group'    => 'General',
        //     'help'     => 'Description of this setting.',
        //     'default'  => '',
        //     'validate' => '',  // regex pattern, e.g. 'regex:/^sk_/'
        // ],
    ],

    // --- Post-Install Actions ---
    'post_install' => [
        'migrate'       => true,
        'seed'          => false,
        'seeder_class'  => '',  // e.g. 'App\\Database\\Seeds\\DefaultSeeder'
    ],

    // --- Authentication ---
    // system: shield, ion_auth, myth_auth, custom, none
    'auth' => [
        'system'  => 'none',
        'collect' => [],     // e.g. ['username', 'email', 'password']
        'group'   => '',     // e.g. 'superadmin'
        // For 'custom' system only:
        // 'table'         => 'users',
        // 'fields'        => ['email' => 'email', 'password' => 'password_hash'],
        // 'hash_method'   => 'PASSWORD_DEFAULT',
        // 'extra_inserts' => ['role' => 'admin', 'active' => 1],
    ],

    // --- CI4 Public Directory Handling ---
    // auto: try isolate → htaccess → flatten
    // isolate: move app files outside document root (most secure)
    // htaccess: rewrite to public/ via .htaccess/web.config
    // flatten: move index.php to root (last resort)
    // none: developer's zip already handles it
    'public_dir_handling' => 'auto',

    // --- Post-Install Redirect ---
    'post_install_url' => '/',
];
