<?php

if( file_exists(dirname(__FILE__) . '/../../../core.php') ) {
    require_once( dirname(__FILE__) . '/../../../core.php' );
} else {
    require_once( dirname(__FILE__) . '/../../../../core.php' );
}
require_once( config_get( 'class_path' ) . 'MantisPlugin.class.php' );

require_api( 'database_api.php' );
require_api( 'file_api.php' );
require_api( 'plugin_api.php' );

global $g_plugin_current;
if( !isset($g_plugin_current[0]) )
    $g_plugin_current[0] = 'AttachmentIndexer';

class Extractor {
    public static $binaries = array(
        'antiword' => 'antiword',
        'unzip' => 'unzip',
        'pdftotext' => 'pdftotext',
        'java' => 'java',
    );
    public static $mimetypes = array(
        'text/plain' => 'text',
        'text/html' => 'html',
        'text/xml' => 'xml',
        'application/pdf' => 'pdf',
        'application/msword' => 'msword',
        'application/vnd.ms-word' => 'msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
    );
    public static $extractor_possibilities = array(
        'tika' => array('msword', 'pdf', 'odt', 'docx'),
        'antiword' => array('msword'),
        'pdftotext' => array('pdf'),
        'xml' => array('html', 'xml'),
        'text' => array('text')
    );

    public static $extractors = array(
        'msword' => 'tika', 'pdf' => 'tika', 'odt' => 'tika', 'docx' => 'tika'
    );

    function __construct() {
        $args = func_get_args();
        if( $args[0] )
            $this->extractors = $args[0];
    }

    function get_extractor( $p_type ) {
        $typ = Extractor::$mimetypes[$p_type];
        $x = Extractor::$extractors[$typ];
        //print "$p_type -> {$typ} -> {$x}\n";
        return $x;
    }

    function __call( $p_type, $p_data ) {
        return $this->extract( $p_type, $p_data );
    }

    function extract( $p_type, $p_data ) {
        $t_extractor = 'extract_' . $this->get_extractor($p_type);
        //print "t_extractor=$t_extractor\n";
        return $this->$t_extractor( $p_data );
    }

    public function set_extractor($p_type, $p_extractor) {
        $this->extractors[$p_type] = $p_extractor;
    }

    protected function execute( $p_prog, $p_cmd, $input, $env=array() ) {
        $result = NULL;
        $descriptorspec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('file', '/tmp/attachmentindexer-execute-error.txt', 'a')
        );
        setlocale(LC_ALL, 'en_US.UTF-8');
        setlocale(LC_CTYPE, 'en_US.UTF-8');

        $cmd = (array_key_exists($p_prog, Extractor::$binaries)
            ? Extractor::$binaries[$p_prog] : $p_prog) . ' ' . $p_cmd;
        print "\n  EXECUTING $cmd\n";

        $fh = proc_open( $cmd, $descriptorspec, $pipes, '/tmp' );
        if (is_resource($fh) ) {
            fwrite($pipes[0], $input);
            //print_r($input);
            fclose($pipes[0]);
            $result = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        return array(proc_close($fh), $result);
    }

    public static function extract_xml( $p_data ) {
        $xml = DOMDocument::loadXML($p_data,
            LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
        // Return data without XML formatting tags
        return array(0, strip_tags($xml->saveXML()));
    }

    public static function extract_html( $p_data ) {
        return array(0, strip_tags($p_data));
    }

    public static function extract_text( $p_data ) {
        return array(0, $p_data);
    }

    public function extract_zipxml( $p_data, $p_filename ) {
        $result = $this->execute('unzip',  '-p - ' . escapeshellarg( $p_filename ), $p_data);
        if( $result[0] === 0 ) {
            $result = Extractor::extract_xml( $result[1] );
        }
        return $result;
    }

    public function extract_odt( $p_data ) {
        return $this->extract_zipxml( $p_data, 'content.xml');
    }

    public function extract_docx( $p_data ) {
        return $this->extract_zipxml( $p_data, 'word/document.xml');
    }

    public function extract_msword( $p_data ) {
        return $this->execute( 'antiword', '-t -i1 - | grep -v [pic]', $p_data,
            array('LC_ALL' => 'en_US.UTF-8'));
    }

    public function extract_pdf( $p_data ) {
        $tmpfn = tempnam( '/tmp', 'pdf' );
        file_put_contents( $tmpfn, $p_data );
        try {
            $result = $this->execute( 'pdftotext', '-enc UTF-8 "' . $tmpfn . '" -', NULL );
        } catch(Exception $e) {
            $result = array(-1, $e);
        }
        unlink($tmpfn);
        return $result;
    }

    public function extract_tika( $p_data ) {
        return $this->execute('java', '-jar '.dirname(__FILE__).'/../tika-app-0.7.jar -eUTF-8 -t -', $p_data);
    }
}

abstract class IndexerBackend {
    public $extractor = NULL;
    public $default_language = 'english';

    public function add_file( $p_file_id, $p_file_type=NULL, $p_save_to=NULL ) {
        //print "\nadd_file($p_file_id): ";
        $result = file_get_content( $p_file_id, 'bug' );
        //print $result['type'] . "\n";
        if( $this->extractor == NULL ) {
            $t_extractors = plugin_config_get( 'extractors', NULL );
            $this->extractor = new Extractor($t_extractors);
        }
        if( $p_file_type == NULL ) {
            if( !array_key_exists( $result['content'], Extractor::$mimetypes ) ) {
                $rset = db_query_bound(
                    'SELECT file_type FROM '.db_get_table('bug_file').'
                       WHERE id='.db_param(),
                    array( $p_file_id ) );
                $row = db_fetch_array( $rset );
                $t_type = $row['file_type'];
            } else {
                $t_type = $result['content'];
            }
        } else {
            $t_type = $p_file_type;
        }
        $t_extractor = $this->extractor->get_extractor( $t_type );
        if ( $t_extractor != NULL ) {
            //print '  '.strlen($result['content'])."\n";
            if( $p_save_to !== NULL ) {
                file_put_contents("/tmp/$p_file_id", $result['content'] );
            }
            $result = $this->extractor->extract( $t_type, $result['content'] );
            //print "  "; print_r($result); print "\n";
            if ( $result[0] === 0 ) {
                //print_r($result);
                $text = $result[1] == NULL ? NULL : trim($result[1]);
                if( !mb_check_encoding($text, 'UTF-8') ) {
                    $text = mb_convert_encoding($text, 'UTF-8',
                        array('ASCII', 'ISO-8859-2', 'ISO-8859-1', 'CP1252', 'CP1251'));
                }
                $this->add_text( $p_file_id, $text );
            }
        }
    }

    public function add_text( $p_id, $p_text ) {
        $t_store_text = (int)(plugin_config_get( 'store_backend_text', ON ));
        db_query('BEGIN');

        $t_attachment_table = plugin_table( 'bug_file' );
        $c_id = db_prepare_int( $p_id );

        //invalid byte sequence
        try {
            $query = "INSERT INTO $t_attachment_table (file_id, text)
                        VALUES ($c_id, ".db_param().')';
            print 'QUERY:' . $query . "\n";
            db_query_bound( $query, array($p_text) );
        } catch(Exception $e) {
            db_query_bound( $query, array(iconv('ISO-8859-2', 'UTF-8', $p_text)) );
        }

        $this->index_text( $p_id, $p_text );
        if( ON != $t_store_text ) {
            db_query( "UPDATE $t_attachment_table SET text = NULL WHERE file_id = $c_id" );
        }

        db_query('COMMIT');
    }

    public function get_lang( $p_language=NULL ) {
        return $p_language === NULL ? $this->default_language : $p_language;
    }

    abstract protected function index_text( $p_id, $p_text, $p_language=NULL );

    abstract protected function find_text( $p_query, $p_language=NULL, $p_limit=100 );
}

class IndexerXapianBackend extends IndexerBackend {
    protected $dbname = null;
    protected $xp = array('stemmers'=>array(), 'indexers'=>array(), 'queryparsers'=>array());

    function __construct($p_dbname) {
        require_once("xapian.php");

        $this->dbname = $p_dbname;
    }

    protected function get_xp($p_key, $p_language=NULL) {
        $t_lang = $this->get_lang($p_language);
        $t_arr = $this->$xp[$p_key];
        if( !array_key_exists($t_lang, $t_arr) ) {
            switch($p_key) {
                case 'stemmer':
                    $t_arr[$t_lang] = new XapianStem();
                    break;
                case 'indexer':
                    $t_arr[$t_lang] = new XapianTermGenerator();
                    $t_arr[$t_lang]->set_stemmer($this->get_xp('stemmer', $t_lang));
                    break;
                case 'queryparser':
                    $t_arr[$t_lang] = new XapianQueryParser();
                    $t_arr[$t_lang]->set_stemmer($this->get_xp('stemmer', $t_lang));
                    $t_arr[$t_lang]->set_stemming_strategy(XapianQueryParser::STEM_SOME);
                    break;
            }
        }

        return $t_arr[$t_lang];
    }

    protected function index_text( $p_id, $p_text, $p_language=NULL ) {
        $indexer = $this->get_xp('indexer', $p_language);

        // Open the database for update, creating a new database if necessary.
        $database = new XapianWritableDatabase($this->dbname, Xapian::DB_CREATE_OR_OPEN);

        $doc = new XapianDocument();
        $doc->set_data($p_id);
        $indexer->set_document($doc);
        $indexer->index_text($p_text);

        // Add the document to the database.
        $database->add_document($doc);

        // Set the database handle to Null to ensure that it gets closed
        // down cleanly or unflushed changes may be lost.
        $database = Null;
    }

    public function find_text( $p_query, $p_language=NULL, $p_limit=100 ) {
        $qp = $this->get_xp('queryparser',  $p_language);
        $database = new XapianDatabase($this->dbname);
        $qp->set_database($database);
        $query = $qp->parse_query($p_query);
        $enquire = new XapianEnquire($database);
        $enquire->set_query($query);
        $matches = $enquire->get_mset(0, $p_limit);

        $result = array();
        $i = $matches->begin();
        while(!$i->equals($matches->end())) {
            $result[] = $i->get_document()->get_data();
            $i->next();
        }
        return $result;
    }
}

class IndexerTSearch2Backend extends IndexerBackend {
    protected function index_text( $p_id, $p_text, $p_language=NULL ) {
        $t_attachment_table = plugin_table( 'bug_file' );
        $c_id = db_prepare_int( $p_id );
        $c_lang = db_prepare_string( $this->get_lang($p_language) );

        $query = "UPDATE $t_attachment_table
                    SET idx = to_tsvector('$c_lang', text)
                    WHERE text IS NOT NULL AND
                          (file_id = $c_id OR idx IS NULL)";
        db_query( $query );
    }

    public function find_text( $p_query, $p_language=NULL, $p_limit=100 ) {
        $result = array();
        $t_attachment_table = plugin_table( 'bug_file' );
        $c_lang = db_prepare_string( $this->get_lang($p_language) );

        $query = "SELECT file_id FROM $t_attachment_table
                    WHERE idx @@ to_tsquery('$c_lang', ".db_param().")";
        $t_result = db_query_bound( $query, array( $p_query ), $p_limit );
        while( !$t_result->EOF ) {
            $row = db_fetch_array( $t_result );
            //print_r($row);
            if( $row === false )
                break;
            $result[] = $row['file_id'];
        }
        return $result;
    }
}

function unindexed_files( $p_limit=100 ) {
    $t_attachment_table = plugin_table( 'bug_file' );
    $t_bug_file_table = db_get_table( 'bug_file' );
    $c_limit = db_prepare_int( $p_limit );

    $query = "SELECT A.id, A.file_type FROM $t_bug_file_table AS A
                WHERE NOT EXISTS (SELECT 1 FROM $t_attachment_table AS X
                                    WHERE X.file_id = A.id) AND
                      A.file_type IS NOT NULL AND
                      A.file_type NOT IN ('application/x-empty', 'application/zip') AND
                      (";
    $t_likes = array();
    foreach( array_keys(Extractor::$mimetypes) as $key ) {
        $t_likes[] = "A.file_type LIKE '" . db_prepare_string( $key ) . "%'";
    }
    $query .= implode(' OR ', $t_likes) . ')';
    //print $query . "\n";
    $result = array();
    $t_result = db_query( $query, $p_limit );
    while( !$t_result->EOF ) {
		$row = db_fetch_array( $t_result );
        if( $row === false )
            break;
        $result[] = $row;
    }
    //print_r($result);
    return $result;
}

function get_valid_backends() {
    $t_valid_backends = array();
    require_api( 'database_api.php' );
    if ( db_is_pgsql() ) $t_valid_backends[] = 'tsearch2';
    if ( class_exists( 'Xapian', true ) ) $t_valid_backends[] = 'xapian';
    return $t_valid_backends;
}

function get_indexer( $p_backend=NULL ) {
    $t_backend = $p_backend == NULL ? plugin_config_get( 'backend' ) : $p_backend;
    //print "\$t_backend=$t_backend\n";
    $t_valid_backends = get_valid_backends();
    if( $t_backend == NULL || !in_array( $t_backend, $t_valid_backends ) )
        $t_backend = $t_valid_backends[0];
    //print "backend=$t_backend\n";
    return ($t_backend == 'xapian'
        ? new IndexerXapianBackend( plugin_config_get( 'xapian_dbname' ))
        : new IndexerTSearch2Backend()
    );
}
