<?php
namespace Deployer;

require 'recipe/laravel.php';

// Config

set('repository', 'git@github.com:inspirecio/it-assets.git');

// Add shared files that persist between deployments
add('shared_files', [
    '.env',
]);

// Add shared directories that persist between deployments
add('shared_dirs', [
    'storage',
    'public/uploads',
]);

// Writable directories for web server
add('writable_dirs', [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/public',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'public/uploads',
]);

// Hosts

host('lamp4.inspirecio.com')
    ->set('remote_user', 'root')
    ->set('deploy_path', '/var/www/sites/assets.inspirecxo.com');

// Tasks

// Task to check if .env exists in shared folder
task('deploy:check_env', function () {
    $sharedEnv = '{{deploy_path}}/shared/.env';
    if (!test("[ -f $sharedEnv ]")) {
        writeln("<comment>Warning: .env file not found in shared folder!</comment>");
        writeln("<comment>You need to create $sharedEnv before deploying</comment>");
        throw new \Exception('.env file missing in shared folder');
    }
})->desc('Check if .env file exists');

// Task to check if storage certificates exist
task('deploy:check_certs', function () {
    $sharedStorage = '{{deploy_path}}/shared/storage/certs/ca-certificate.crt';
    if (!test("[ -f $sharedStorage ]")) {
        writeln("<comment>Warning: SSL certificate not found!</comment>");
        writeln("<comment>You may need to upload: $sharedStorage</comment>");
    }
})->desc('Check if SSL certificates exist');

// Task to optimize the application
task('deploy:optimize', function () {
    run('cd {{release_path}} && {{bin/php}} artisan optimize');
})->desc('Optimize application');

// Hooks

// Check .env exists before deploying
before('deploy:shared', 'deploy:check_env');

// Check certificates after shared setup
after('deploy:shared', 'deploy:check_certs');

// Optimize after migrations
after('artisan:migrate', 'deploy:optimize');

// Unlock on failure
after('deploy:failed', 'deploy:unlock');
