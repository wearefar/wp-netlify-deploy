<?php
/*
Plugin Name: WP Netlify Deploy
Plugin URI: https://github.com/wearefar
Description: Trigger Netlify deploys form the WordPress admin
Author: Victor Guerrero
Version: 0.2.2
Author URI: https://wearefar.com
Requires PHP: 8.4
License: MIT
*/

require __DIR__.'/src/Deploy.php';

new WeAreFar\WPNetlify\Deploy(__FILE__);
