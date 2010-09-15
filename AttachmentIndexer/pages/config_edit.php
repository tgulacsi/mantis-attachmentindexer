<?php
# MantisBT - a php based bugtracking system
# Copyright (C) 2002 - 2009  MantisBT Team - mantisbt-dev@lists.sourceforge.net
# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

form_security_validate( 'plugin_attachmentindexer_config_edit' );

auth_reauthenticate( );
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

$f_backend = gpc_get_string( 'backend', NULL );
/*
echo '<pre>old_url='.plugin_config_get( 'url' ).', new_url='.$f_url.'</pre>';
*/

require_once( dirname(__FILE__).'/../core/indexer_backend_api.php' );

$t_valid_backends = get_valid_backends();
if( in_array($f_backend, $t_valid_backends) && plugin_config_get( 'backend' ) != $f_backend ) {
	plugin_config_set( 'backend', $f_backend );

	if( $f_backend == 'xapian' ) {
		$f_xapian_dbname = gpc_get_string( 'xapian_dbname', NULL );
		if( $f_xapian_dbname != plugin_config_get( 'xapian_dbname' ) ) {
			plugin_config_set( 'xapian_dbname', $f_xapian_dbname );
		}
	} elseif( $f_backend == 'tsearch2' ) {
		require_api( 'database_api.php' );
		$t_attachment_table = plugin_table('bug_file');
		$t_result = db_query("select count(0) from information_schema.columns
			where column_name = 'idx' and LOWER(table_name) = LOWER('$t_attachment_table')");
		$t_db = db_result($t_result);
		//echo "db=$t_db";
		if( $t_db == 0 )
			db_query("ALTER TABLE $t_attachment_table ADD idx tsvector");
	}

}

$f_store_extracted_text = gpc_get_bool( 'store_extracted_text', ON );
if( $f_store_extracted_text != (ON == plugin_config_get( 'store_extracted_text', OFF )) ) {
	plugin_config_set( 'store_extracted_text', $f_store_extracted_text );
}

form_security_purge( 'plugin_attachmentindexer_config_edit' );

print_successful_redirect( plugin_page( 'config', true ) );
?>
