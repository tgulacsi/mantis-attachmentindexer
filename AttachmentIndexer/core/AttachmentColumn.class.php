<?php
require_once( config_get( 'class_path' ) . 'MantisColumn.class.php' );

class AttachmentColumn extends MantisColumn {

	protected $cache = array();

	/**
	 * Column title, as displayed to the user.
	 */
	public $title = 'attachment';

	/**
	 * Column name, as selected in the manage columns interfaces.
	 */
	public $column = 'attachment';

	/**
	 * Column is sortable by the user.  Setting this to true implies that
	 * the column will properly implement the sortquery() method.
	 */
	public $sortable = false;

	/**
	 * Build the SQL query elements 'join' and 'order' as used by
	 * core/filter_api.php to create the filter sorting query.
	 * @param string Sorting order ('ASC' or 'DESC')
	 * @return array Keyed-array with query elements; see developer guide
	 */
	public function sortquery( $p_dir ) {}

	protected function cache_bug_attachment( $p_bug ) {
		if( $p_bug == NULL || $p_bug->id == NULL || !bug_exists( $p_bug->id ) )
			return;
		$t_file_ids = AttachmentFilter::$cache;
		//log_event(LOG_FILTERING, 'file_ids='.var_export($t_file_ids, true));
		if( !is_array($t_file_ids) || count($t_file_ids) == 0 )
			return;
		//log_event(LOG_FILTERING, 'bug_get_attachments('.$p_bug->id.')');
		$t_attachments = bug_get_attachments( $p_bug->id );
		log_event(LOG_FILTERING, 'attachments on '.$p_bug->id.': '.var_export($t_attachemtns, true));
		if( !is_array($t_attachments) )
			return;
		foreach( $t_attachements as $t_key => $t_file ) {
			if( in_array( $t_file['id'], $t_file_ids ) ) {
				$this->cache[$p_bug->id] = $t_file;
			}
		}
	}

	/**
	 * Allow plugin columns to pre-cache data for all issues
	 * that will be shown in a given view.  This is preferable to
	 * the alternative option of querying the database for each
	 * issue as the display() method is called.
	 * @param array Bug objects
	 */
	public function cache( $p_bugs ) {
		require_once( 'AttachmentFilter.class.php' );
		require_api( 'bug_api.php' );
		$this->cache = array();
		if( is_array(AttachmentFilter::$cache) && count(AttachmentFilter::$cache) > 0) {
			foreach( $p_bugs as $t_key => $t_bug ) {
				$this->cache_bug_attachment( $p_bug );
			}
		}
	}

	/**
	 * Function to display column data for a given bug row.
	 * @param object Bug object
	 * @param int Column display target
	 */
	public function display( $p_bug, $p_columns_target ) {
		$result = NULL;
		if( $this->cache == NULL || count($this->cache) == 0 ) {
			$this->cache_bug_attachment( $p_bug );
		}
		if( isset($this->cache[$p_bug->id]) ) {
			$result = $this->cache[$p_bug_id]['filename'];
		}
		return $result;
	}
}

