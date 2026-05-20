<?php
/**
 * Plugin Name: CoPAI Filesystem (mu)
 * Description: Forces direct filesystem access so WP-Admin never prompts for FTP credentials.
 */

if (!defined('FS_METHOD')) {
    define('FS_METHOD', 'direct');
}
