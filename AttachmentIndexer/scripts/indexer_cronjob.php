<?php

if (php_sapi_name() != "cli") {
    print "This example script is written to run under the command line ('cli') version of\n";
    print "the PHP interpreter, but you're using the '".php_sapi_name()."' version\n";
    exit(1);
}

require_once( dirname(__FILE__) . '/../core/indexer_backend_api.php' );
//require_once( 'core.php' );
global $g_plugin_current;
$g_plugin_current[0] = 'AttachmentIndexer';

$conf_pref = 'plugin_AttachmentIndexer_';

try {
    $indexer = (plugin_config_get( 'backend' ) == 'tsearch2' 
        ? new IndexerTSearch2Backend() 
        : new IndexerXapianBackend( config_get( $conf_pref . 'xapian_dbname' )) 
    );
    $indexer->default_laguage = 'hungarian';
    foreach( unindexed_files($argv > 1 ? (int)($argv[1]) : 10) as $elt ) {
        echo "indexing $elt...\n";
        $indexer->add_file( $elt );
        ob_flush();
    }
} catch (Exception $e) {
    print $e->getMessage() . "\n";
    exit(1);
}
?>
