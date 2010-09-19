<?php

if (php_sapi_name() != "cli") {
    print "This example script is written to run under the command line ('cli') version of\n";
    print "the PHP interpreter, but you're using the '".php_sapi_name()."' version\n";
    exit(1);
}

require_once( dirname(__FILE__) . '/../core/indexer_backend_api.php' );
//require_once( 'core.php' );

//$conf_pref = 'plugin_AttachmentIndexer_';

try {
    $indexer = get_indexer();
    //print "INDEXER: $indexer\n"; print_r($indexer);
    $indexer->default_laguage = 'hungarian';
    //print_r(unindexed_files(10));
    foreach( unindexed_files($argc > 1 ? (int)($argv[1]) : 100) as $elt ) {
        echo "indexing {$elt['id']} ({$elt['file_type']})...\n";
        $indexer->add_file( $elt['id'], $p_file_type=$elt['file_type'],
            $p_save_to='/tmp' );
        ob_flush();
    }
} catch (Exception $e) {
    print $e->getMessage() . "\n";
    exit(1);
}
?>
