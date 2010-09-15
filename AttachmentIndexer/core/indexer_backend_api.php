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

abstract class IndexerBackend {
    public static $known_types = array(
        'text/plain' => 'extract_text_plain',
        'text/html' => 'extract_text_html',
        'text/xml' => 'extract_xml',
        'application/pdf' => 'extract_pdf',
        'application/msword' => 'extract_msword',
        'application/vnd.ms-word' => 'extract_msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'extract_docx',
        'application/vnd.oasis.opendocument.text' => 'extract_odt',
    );
    public $default_language = 'english';

    public function add_file( $p_file_id ) {
        //print "\nadd_file($p_file_id): ";
        $result = file_get_content( $p_file_id, 'bug' );
        //print $result['type'] . "\n";
        $t_extractor = $this->get_extractor( $result['type'] );
        if ( $t_extractor != NULL ) {
            //print '  '.strlen($result['content'])."\n";
            file_put_contents("/tmp/$p_file_id", $result['content'] );
            $result = $t_extractor( $result['content'] );
            //print "  "; print_r($result); print "\n";
            if ( $result[0] === 0 ) {
                //print_r($result);
                $text = $result[1] == NULL ? NULL : trim($result[1]);
                if( strpos($text, "\xc1") )
                    $text = iconv('ISO-8859-2', 'UTF-8', $text);
                $this->add_text( $p_file_id, $text );
            }
        }
    }

    public function get_extractor( $p_type ) {
        $t_key = NULL;
        //print "\nknown_types:"; print_r($this::$known_types); print "\n";
        $p = strpos($p_type, ';');
        $t_type = $p ? substr($p_type, 0, $p) : $p_type;
        //print "$p_type -> $t_type\n";
        
        if( array_key_exists( $t_type, $this::$known_types ) ) {
            $t_key = $t_type;
        } else {
            $t_arr = explode( '/', $t_type, 1 );
            //print_r($t_arr);
            if( array_key_exists( $t_arr[0], $this::$known_types ) ) {
                $t_key = $t_arr[0];
            }
        }
        if( $t_key !== NULL && array_key_exists( $t_key, $this::$known_types ) ) {
            return $this::$known_types[ $t_key ];
        } else {
            return NULL;
        }
    }

    public function add_text( $p_id, $p_text ) {
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
        $c_query = db_prepare_string( $p_query );

        $query = "SELECT file_id FROM $t_attachment_table
                    WHERE idx @@ to_tsquery('$c_lang', '$c_query')";
        $t_result = db_query( $query, $p_limit );
        $n = db_num_rows( $t_result );
        for( $i = 0;$i < $n;$i++ ) {
            $row = db_fetch_array( $t_result );
            $result[] = $row[0];
        }
        return $result;
    }
}

function unindexed_files( $p_limit=100 ) {
    $t_attachment_table = plugin_table( 'bug_file' );
    $t_bug_file_table = db_get_table( 'bug_file' );
    $c_limit = db_prepare_int( $p_limit );

    $query = "SELECT A.id FROM $t_bug_file_table AS A
                WHERE NOT EXISTS (SELECT 1 FROM $t_attachment_table AS X
                                    WHERE X.file_id = A.id) AND
                      A.file_type IS NOT NULL AND
                      (";
    $t_likes = array();
    foreach( array_keys(IndexerBackend::$known_types) as $key ) {
        $t_likes[] = "A.file_type LIKE '" . db_prepare_string( $key ) . "%'";
    }
    $query .= implode(' OR ', $t_likes) . ')';
    //print $query . "\n";
    $result = array();
    $t_result = db_query( $query, $p_limit );
    while( !$t_result->EOF ) {
		$row = db_fetch_array( $t_result );
        //print_r($row);
        if( $row === false ) 
            break;
        $result[] = $row['id'];
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

function execute( $cmd, $input, $env=array() ) {
    $result = NULL;
    $descriptorspec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('file', '/tmp/attachmentindexer-execute-error.txt', 'a')
    );
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

function extract_xml( $p_data ) {
    $xml = DOMDocument::loadXML($p_data,
        LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
    // Return data without XML formatting tags
    return array(0, strip_tags($xml->saveXML()));
}

function extract_text_html( $p_data ) {
    return array(0, strip_tags($p_data));
}

function extract_text_plain( $p_data ) {
    return array(0, $p_data);
}

function extract_zipxml( $p_data, $p_filename ) {
    $result = execute('unzip -p - ' . escapeshellarg( $p_filename ), $p_data);
    if( $result[0] === 0 ) {
        $result = extract_xml( $result[1] );
    }
    return $result;
}

function extract_odt( $p_data ) {
    return extract_zipxml( $p_data, 'content.xml');
}

function extract_docx( $p_data ) {
    return extract_zipxml( $p_data, 'word/document.xml');
}

function extract_msword( $p_data ) {
    return execute( 'antiword -t -i1 - | grep -v [pic]', $p_data, 
        array('LC_ALL=en_US.UTF-8'));
}

function extract_pdf( $p_data ) {
    return execute('pdftotext -enc UTF-8 - -', $p_data);
}
