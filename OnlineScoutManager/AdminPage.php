<?php
add_action( 'admin_menu', 'my_plugin_menu' );
function my_plugin_menu() {
	$hook = add_menu_page('Online Scout Manager', 'OSM', 'manage_options', 'osm', 'my_plugin_options');
	// Handle the OSM redirect early (before any HTML is sent) so we can issue a
	// real header redirect back to the site's normal http:// admin URL. Doing the
	// token exchange on the https:// callback page itself works, but WordPress's
	// own CSS/JS is still linked via the http:// site URL, which browsers block
	// as mixed content on an https page - hence redirecting back before rendering.
	add_action("load-$hook", 'osm_handle_oauth_callback');
}

function osm_handle_oauth_callback() {
	if (!isset($_GET['osm_callback'])) {
		return;
	}

	$expectedState = get_option('OnlineScoutManager_oauth_state');
	delete_option('OnlineScoutManager_oauth_state');

	if (isset($_GET['error'])) {
		update_option('OnlineScoutManager_lastAuthError', 'OSM authorisation was not completed: ' . sanitize_text_field($_GET['error']));
	} else if (!isset($_GET['code']) || !isset($_GET['state']) || !$expectedState || !hash_equals($expectedState, $_GET['state'])) {
		update_option('OnlineScoutManager_lastAuthError', 'Authorisation response could not be verified. Please try again.');
	} else if (exchangeOsmAuthCode($_GET['code'])) {
		getRoles();
		wp_redirect(admin_url('admin.php?page=osm&osm_mode=enableroles'));
		exit;
	} else {
		update_option('OnlineScoutManager_lastAuthError', 'OSM rejected the authorisation code - check osm-debug.log for details.');
	}

	wp_redirect(admin_url('admin.php?page=osm'));
	exit;
}
function getRoles() {
	$roles = osm_query('api.php?action=getUserRoles');
	osm_debug_log('getRoles received', $roles);
	$storeRoles = array();
	if ($roles) {
		foreach ($roles as $role) {
			switch ($role['section']) {
				case 'earlyyears':
				case 'beavers':
				case 'cubs':
				case 'scouts':
				case 'explorers':
					$storeRoles[$role['sectionid']] = array('groupname' => $role['groupname'], 'sectionname' => $role['sectionname'], 'section' => $role['section'], 'sectionid' => $role['sectionid']);
			}
		}
	}
	update_option('OnlineScoutManager_allRoles', $storeRoles);
	return $storeRoles;
}
function resyncDataToActiveRoles() {
	$activeRoles = get_option('OnlineScoutManager_activeRoles');
	$allRoles = get_option('OnlineScoutManager_allRoles');
	foreach ($activeRoles as $sectionid => $role) {
		$activeRoles[$sectionid]['section'] = $allRoles[$sectionid]['section'];
	}
	update_option('OnlineScoutManager_activeRoles', $activeRoles);
}
function my_plugin_options() {
	$OnlineScoutManager_options = array('OnlineScoutManager_ClientID', 'OnlineScoutManager_ClientSecret', 'OnlineScoutManager_BearerTok3n', 'OnlineScoutManager_RefreshTok3n', 'OnlineScoutManager_oauth_state', 'OnlineScoutManager_allRoles', 'OnlineScoutManager_activeRoles');
	$OnlineScoutManager_cache = array('OnlineScoutManager_programme', 'OnlineScoutManager_patrols');
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	$authoriseErrorMsg = get_option('OnlineScoutManager_lastAuthError', '');
	if ($authoriseErrorMsg) {
		delete_option('OnlineScoutManager_lastAuthError');
	}

	// osm_handle_oauth_callback() (on load-{hook}) already did the token exchange
	// and redirected here - just render the section picker with what it saved.
	// Only on the initial GET: the picker form posts back to this same URL
	// (action=""), so osm_mode is still in $_GET on that POST too - must not
	// intercept it here or the submitted checkboxes never get processed below.
	if (!isset($_POST['mode']) && isset($_GET['osm_mode']) && $_GET['osm_mode'] == 'enableroles') {
		$storeRoles = get_option('OnlineScoutManager_allRoles');
		$mode = 'enableroles';
		include(WP_PLUGIN_DIR . '/' . PLUGIN_SLUG . '/views/admin_authorise.php');
		return;
	}

	if (isset($_POST['mode'])) {
		$mode = $_POST['mode'];
		if ($mode == 'usernamepassword') {
			$clientId = trim($_POST['email']);
			$clientSecret = trim($_POST['password']);

			update_option('OnlineScoutManager_ClientID', $clientId);
			update_option('OnlineScoutManager_ClientSecret', $clientSecret);

			$state = bin2hex(random_bytes(16));
			update_option('OnlineScoutManager_oauth_state', $state);

			$mode = 'authorise';
			$authoriseUrl = osm_get_authorise_url($state);
			include(WP_PLUGIN_DIR . '/' . PLUGIN_SLUG . '/views/admin_authorise.php');
			return;
		} else if ($mode == 'enableroles') {
			$roles = $_POST['roles'];
			if (count($roles) > 0) {
				$storeRoles = get_option('OnlineScoutManager_allRoles');
				$activeRoles = array();
				foreach ($roles as $sectionid => $null) {
					$activeRoles[$sectionid] = $storeRoles[$sectionid];
				}
				delete_option('OnlineScoutManager_allRoles');
				update_option('OnlineScoutManager_activeRoles', $activeRoles);
				getTerms();
			} else {
				$storeRoles = get_option('OnlineScoutManager_allRoles');
				$authoriseErrorMsg = 'You must select one or more sections to use.';
				$mode = 'enableroles';
				include(WP_PLUGIN_DIR . '/' . PLUGIN_SLUG . '/views/admin_authorise.php');
				return;
			}
		} else if ($mode == 'unauthorise') {
			foreach ($OnlineScoutManager_options as $toDelete) {
				delete_option($toDelete);
			}
			foreach ($OnlineScoutManager_cache as $toDelete) {
				delete_option($toDelete);
			}
		} else if ($mode == 'purgecache') {
			$authoriseErrorMsg = "Cache has been purged";
			$options = get_alloptions();
			foreach ($options as $key => $value) {
				if (strpos($key, 'OnlineScoutManager_') === 0 and !in_array($key, $OnlineScoutManager_options)) {
					delete_option($key);
				}
			}
		}
	}
	$authorised = get_option('OnlineScoutManager_RefreshTok3n') || get_option('OnlineScoutManager_BearerTok3n');
	if ($authorised) {
		include(WP_PLUGIN_DIR . '/' . PLUGIN_SLUG . '/views/admin.php');
	} else {
		$mode = 'usernamepassword';
		include(WP_PLUGIN_DIR . '/' . PLUGIN_SLUG . '/views/admin_authorise.php');
	}
}

?>