<?php
require_once( 'indexer_backend_api.php' );
require_class( 'MantisFilter.class.php' );

class IndexerFilter extends MantisFilter {

	/**
	 * Field name, as used in the form element and processing.
	 */
	public $field = 'attachment_contains';

	/**
	 * Filter title, as displayed to the user.
	 */
	public $title = 'Attachment filter';

	/**
	 * Filter type, as defined in core/constant_inc.php
	 */
	public $type = FILTER_TYPE_STRING;

	/**
	 * Default filter value, used for non-list filter types.
	 */
	public $default = '';

	/**
	 * Form element size, used for non-boolean filter types.
	 */
	public $size = 20;

	/**
	 * Number of columns to use in the bug filter.
	 */
	public $colspan = 1;

	/**
	 * Build the SQL query elements 'join', 'where', and 'params'
	 * as used by core/filter_api.php to create the filter query.
	 * @param multi Filter field input
	 * @return array Keyed-array with query elements; see developer guide
	 */
	function query( $p_filter_input ) {
		result = array();
		$indexer = get_indexer();
		$t_file_ids = $indexer->find_text( $p_filter_input );

		$t_attachment_table = plugin_table( 'bug_file' );
		$t_bug_table = db_get_table( 'bug' );
		$t_file_table = db_get_table( 'bug_file' );
		$result = array(
			'where' => array( "( $t_bug_table.id IN ( SELECT bug_id FROM $t_file_table WHERE id IN (" . implode(',', $t_file_ids) . ") ) )" ),
		);
        return $result;
    }

	/**
	 * Display the current value of the filter field.
	 * @param multi Filter field input
	 * @return string Current value output
	 */
	function display( $p_filter_value ) {
        return $p_filter_value;
    }

	/**
	 * For list type filters, define a keyed-array of possible
	 * filter options, not including an 'any' value.
	 * @return array Filter options keyed by value=>display
	 */
	function options() {
        return array();
    }
}

