<?php
namespace Deployer;

require 'recipe/laravel.php';

// Config

set('repository', 'git@github.com:inspirecio/it-assets.git');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

// Hosts

host('lamp4.inspirecio.com')
    ->set('remote_user', 'root')
    ->set('deploy_path', '/var/www/sites/assets.inspirecxo.com');

// Hooks

after('deploy:failed', 'deploy:unlock');
