<?php 

unset($CFG);
global $CFG;
$CFG = new stdClass();


$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MOODLE_DB_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DB_USER') ?: 'moodleuser';
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
);

// URL und Port
$CFG->wwwroot   = getenv('MOODLE_URL');

//prüft ob SSL an ist
$ssl = getenv('SSL');
$CFG->sslproxy = $ssl !== false ? filter_var($ssl, FILTER_VALIDATE_BOOLEAN) : false;

$url_parts = parse_url($CFG->wwwroot);
if (isset($url_parts['port'])) {
    $_SERVER['SERVER_PORT'] = $url_parts['port'];
}

$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
