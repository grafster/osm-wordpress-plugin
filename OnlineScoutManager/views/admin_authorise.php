<div class="wrap">
	<h2>Online Scout Manager</h2>
	<h3>Authorisation</h3>
	<form action="" method="post">
	<input type="hidden" name="mode" value="<?php echo $mode; ?>" />
	<?php
	if ($mode == 'usernamepassword') {?>
	<p>This page does not work currently. Please enter OnlineScoutManager_OnlineScoutManager_ClientID and OnlineScoutManager_OnlineScoutManager_ClientSecret into wp_options in the database</p>
	
	<?php } else if ($mode == 'enableroles') {?>
		<p>Please select which sections should be enabled for use on this site.</p>		
		<table>
		<?php
		foreach ($storeRoles as $role) {
			echo '<tr><td>'.$role['groupname'].' : '.$role['sectionname'].'</td><td><input type="checkbox" name="roles['.$role['sectionid'].']" value="true" /></td></tr>';
		}
		?>
		<tr><td colspan="2"><?php if (strlen($authoriseErrorMsg) > 0) { echo '<span style="color: red;">'.$authoriseErrorMsg.'</span><br />';}?><input id="submit" class="button-primary" type="submit" value="Next" name="submit"></td></tr>
		</table>
	<?php } ?>
	</form>
</div>