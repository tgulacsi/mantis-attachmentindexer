<?php

require_once( config_get( 'class_path' ) . 'MantisPlugin.class.php' );

class AttachmentIndexerPlugin extends MantisPlugin {
    function register() {
        $this->name = 'AttachmentIndexer';
        $this->description = "Attachment indexer using Xapian or PostgreSQL's tsearch2 as backend";
        $this->page = 'config';
        $this->version = '0.1.2';
        $this->requires = array(
            'MantisCore' => '1.2',
        );
        $this->author = 'Tamás Gulácsi';
        $this->contact = 'gt-dev AT NOSPAM DOT gthomas DOT homelinux DOT org';
    }

	/**
	 * Schema
	 */
	function schema ()
	{
		return array(
			array('CreateTableSQL', array(plugin_table('bug_file'), "
				file_id     I UNSIGNED NOTNULL PRIMARY,
				text  XL")),
		);
	}

    function config() {
	    require_once( dirname(__FILE__) . '/core/indexer_backend_api.php' );
	    return array(
		    'backend' => 'tsearch2',
		    'store_extracted_text' => ON,
		    'xapian_dbname' => NULL,
		    'extractors' => Extractor::$extractors,
		    'binaries' => Extractor::$binaries,
	    );
    }

    function hooks() {
	    return array(
		    'EVENT_MENU_MANAGE' => 'manage',
		    'EVENT_MENU_FILTER' => 'filter_link',
            'EVENT_FILTER_FIELDS' => 'filter_field_classes',
            'EVENT_FILTER_COLUMNS' => 'filter_field_columns',
		);
    }
    
    function filter_link() {
        log_event( LOG_FILTERING, 'AI.filter_link' );
        return '???';
    }
    
    function filter_field_columns() {
        log_event( LOG_FILTERING, 'AI.filter_field_columns' );
        return array();
    }

    function filter_field_classes( ) {
        log_event( LOG_FILTERING, 'AI.filter_field_classes' );
	    require_once( dirname(__FILE__) . '/core/IndexerFilter.class.php' );
        return array( 'IndexerFilter' ); //class names for custom filters extending MantisFilter
    }

    function manage( ) {
        require_once( 'core.php' );

        if ( access_get_project_level() >= MANAGER) {
            return array( '<a href="' . plugin_page( 'config.php' ) . '">'
                .  plugin_lang_get('config') . '</a>', );
        }
    }

}
