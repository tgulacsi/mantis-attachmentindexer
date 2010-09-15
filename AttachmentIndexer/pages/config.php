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

auth_reauthenticate( );
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

html_page_top( plugin_lang_get( 'name' ) );

print_manage_menu( );

require_once( dirname(__FILE__).'/../core/indexer_backend_api.php' );
$t_valid_backends = get_valid_backends();
$t_act_backend = plugin_config_get( 'backend' );

?>

<br/>
<form action="<?php echo plugin_page( 'config_edit' )?>" method="post">
<?php echo form_security_field( 'plugin_attachmentindexer_config_edit' ) ?>
<table align="center" class="width50" cellspacing="1">

<tr>
	<td class="form-title" colspan="3">
		<?php echo plugin_lang_get( 'config' )?>
	</td>
</tr>

<tr <?php echo helper_alternate_class( )?>>
	<td class="category" width="60%">
		<?php echo plugin_lang_get( 'backend' )?>
	</td>
	<td class="center" width="20%">
	<?php foreach($t_valid_backends as $t_back) { ?>
		<input type="radio" name="backend" value="<?php echo $t_back;?>"
		<?php if( $t_back === $t_act_backend ) echo 'checked="true"'; ?>
		><?php echo $t_back;?></input>
	<?php } ?>
	</td>
</tr>
<tr <?php echo helper_alternate_class( )?>>
	<td class="category" width="60%">
		<?php echo plugin_lang_get( 'store_extracted_text' )?>
	</td>
	<td class="center" width="20%">
		<input type="checkbox" name="store_extracted_text"
			checked="<?php echo plugin_config_get( 'store_extracted_text' ) ? 'true' : 'false'; ?>" />
	</td>
</tr>
<tr <?php echo helper_alternate_class( )?>>
	<td class="category" width="60%">
		<?php echo plugin_lang_get( 'xapian_dbname' )?>
	</td>
	<td class="center" width="20%">
		<input type="text" name="xapian_dbname" value="<?php
			echo plugin_config_get( 'xapian_dbname', '' ); ?>"
			<?php if( plugin_config_get( 'backend' ) !== 'xapian' )
				echo 'disabled="disabled"'; ?>/>
	</td>
</tr>

<tr>
	<td class="center" colspan="3">
		<input type="submit" class="button" value="<?php echo lang_get( 'change_configuration' )?>" />
	</td>
</tr>

</table>
</form>

<?php
html_page_bottom();
?>
