<?php
/*
Plugin Name: Online Scout Manager
Description: A collection of widgets to display data from OSM on your site.
Version: 1.4.0
Author: Online Youth Manager Ltd / Andrew Grafham
License:

  Copyright 2012 Online Youth Manager Ltd.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
*/

require("ComingUp.php");
require("PatrolPoints.php");
require("AdminPage.php");
require("page_replaces/challenge_badges.php");
require("page_replaces/programme.php");
require("page_replaces/events.php");
function register_osm_widgets() {
	register_widget("OSM_PatrolPoints");
	register_widget("OSM_Whats_Next");
}
add_action('widgets_init', 'register_osm_widgets');

// Temporary debug logging while diagnosing the section-id lookup. Set to true to re-enable.
define('OSM_DEBUG', false);
define('OSM_DEBUG_LOG_FILE', WP_CONTENT_DIR . '/osm-debug.log');

function osm_debug_log($label, $data = null) {
	if (!OSM_DEBUG) {
		return;
	}
	$line = '[' . date('Y-m-d H:i:s') . '] ' . $label;
	if ($data !== null) {
		$line .= ': ' . (is_string($data) ? $data : print_r($data, true));
	}
	error_log($line . "\n", 3, OSM_DEBUG_LOG_FILE);
}


// OSM's registered app for this client only supports the Authorization Code
// grant (it requires a redirect_uri), not client_credentials - see osm_get_authorise_url().
// OSM requires an https redirect_uri. Locally that's served by a TLS proxy in front of
// the plain-http dev server; define OSM_OAUTH_REDIRECT_BASE in wp-config.php to point at it
// (e.g. define('OSM_OAUTH_REDIRECT_BASE', 'https://localhost:8443');). In production, where
// the site itself is already https, this constant should stay undefined.
function osm_get_redirect_uri() {
	$admin_url = admin_url('admin.php?page=osm&osm_callback=1');
	if (defined('OSM_OAUTH_REDIRECT_BASE') && OSM_OAUTH_REDIRECT_BASE) {
		$path_and_query = substr($admin_url, strpos($admin_url, '/wp-admin/'));
		return rtrim(OSM_OAUTH_REDIRECT_BASE, '/') . $path_and_query;
	}
	return $admin_url;
}

function osm_get_authorise_url($state) {
	$params = array(
		'client_id' => trim(get_option('OnlineScoutManager_ClientID')),
		'redirect_uri' => osm_get_redirect_uri(),
		'response_type' => 'code',
		'scope' => 'section:programme:read',
		'state' => $state,
	);
	return 'https://www.onlinescoutmanager.co.uk/oauth/authorize?' . http_build_query($params);
}

// Shared POST to /oauth/token for both the authorization_code exchange and refresh_token calls.
function osm_post_token_request($parts, $label) {
	$data = '';
	foreach ($parts as $key => $val) {
		$data .= '&' . $key . '=' . urlencode($val);
	}

	$curl_handle = curl_init();
	curl_setopt($curl_handle, CURLOPT_URL, 'https://www.onlinescoutmanager.co.uk/oauth/token');
	curl_setopt($curl_handle, CURLOPT_POSTFIELDS, substr($data, 1));
	curl_setopt($curl_handle, CURLOPT_POST, 1);
	curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
	$body = curl_exec($curl_handle);
	$curl_error = curl_error($curl_handle);
	$http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
	curl_close($curl_handle);

	if ($curl_error) {
		osm_debug_log($label . ' curl error', $curl_error);
		return null;
	}

	$result = json_decode($body, true);

	$logged = $result;
	if (is_array($logged)) {
		foreach (array('access_token', 'refresh_token') as $secret_field) {
			if (isset($logged[$secret_field])) {
				$logged[$secret_field] = '[REDACTED]';
			}
		}
	}
	osm_debug_log($label . ' HTTP ' . $http_code . ' response', $logged);

	if (!is_array($result) || isset($result['error'])) {
		update_cached_osm('loginFail', 'now');
		return null;
	}

	return $result;
}

function osm_store_token($result) {
	if (!isset($result['access_token'])) {
		return;
	}
	delete_option('OnlineScoutManager_loginFail');
	$expires_in = isset($result['expires_in']) ? intval($result['expires_in']) : 3600;
	// 'time' is stored as the actual expiry (minus a safety buffer), so freshness
	// checks can just compare it against time() directly.
	update_cached_osm('BearerTok3n', $result['access_token'], max(60, $expires_in - 60));
	if (isset($result['refresh_token'])) {
		update_cached_osm('RefreshTok3n', $result['refresh_token']);
	}
}

function exchangeOsmAuthCode($code) {
	$parts = array(
		'grant_type' => 'authorization_code',
		'client_id' => trim(get_option('OnlineScoutManager_ClientID')),
		'client_secret' => trim(get_option('OnlineScoutManager_ClientSecret')),
		'code' => $code,
		'redirect_uri' => osm_get_redirect_uri(),
	);
	$result = osm_post_token_request($parts, 'exchangeOsmAuthCode');
	if ($result) {
		osm_store_token($result);
		return true;
	}
	return false;
}

function osm_refresh_access_token($refresh_token) {
	$parts = array(
		'grant_type' => 'refresh_token',
		'client_id' => trim(get_option('OnlineScoutManager_ClientID')),
		'client_secret' => trim(get_option('OnlineScoutManager_ClientSecret')),
		'refresh_token' => $refresh_token,
	);
	$result = osm_post_token_request($parts, 'osm_refresh_access_token');
	if ($result) {
		osm_store_token($result);
		return $result['access_token'];
	}
	return null;
}

function getBearerToken()
{
	$loginFail = get_option('OnlineScoutManager_loginFail');

	// If the login failed, don't retry for a long time
	if ($loginFail and $loginFail['time'] > time() - 90000)
	{
		return null;
	}

	$cachedToken = get_option('OnlineScoutManager_BearerTok3n');
	if ($cachedToken and $cachedToken['time'] > time()) {
		return $cachedToken['content'];
	}

	$refreshToken = get_option('OnlineScoutManager_RefreshTok3n');
	if ($refreshToken) {
		$newToken = osm_refresh_access_token($refreshToken['content']);
		if ($newToken) {
			return $newToken;
		}
	}

	// No valid token and no usable refresh token - the admin needs to click
	// "Authorise with OSM" again on the plugin's settings page.
	return null;
}

function osm_query($url, $parts = null) {
	global $OnlineScoutManager_userid, $OnlineScoutManager_secret;
	if ($parts == null) {
		$parts = array();
	}

	
	$data = '';
	foreach ($parts as $key => $val) {
		$data .= '&'.$key.'='.urlencode($val);
	}
	$curl_handle = curl_init();
	
	$bearer_token = getBearerToken();
	
	if (is_null($bearer_token))
	{
	    return null;
	}
	
	curl_setopt($curl_handle, CURLOPT_URL, 'https://www.onlinescoutmanager.co.uk/'.$url);
	curl_setopt($curl_handle, CURLOPT_POSTFIELDS, substr($data, 1));
	curl_setopt($curl_handle, CURLOPT_POST, 1);
	curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $bearer_token));

	$msg = curl_exec($curl_handle);
	$curl_error = curl_error($curl_handle);
	$http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);

	if ($curl_error) {
		osm_debug_log('osm_query curl error for ' . $url, $curl_error);
	}
	osm_debug_log('osm_query ' . $url . ' HTTP ' . $http_code . ' response', $msg);

	return json_decode($msg, true);
}
function osm_get_current_termid($sectionTerms) {
	if (empty($sectionTerms)) {
		return 0;
	}
	$now = time();
	foreach ($sectionTerms as $term) {
		if (strtotime($term['startdate']) <= $now && $now <= strtotime($term['enddate'])) {
			return $term['termid'];
		}
	}
	// Between terms (e.g. a gap over the holidays) - use whichever term started
	// most recently, falling back to the earliest upcoming term if none have started.
	$mostRecentStarted = null;
	foreach ($sectionTerms as $term) {
		if (strtotime($term['startdate']) <= $now) {
			$mostRecentStarted = $term;
		}
	}
	if ($mostRecentStarted) {
		return $mostRecentStarted['termid'];
	}
	$earliest = reset($sectionTerms);
	return $earliest['termid'];
}

function getTerms() {
	$terms = get_cached_osm('terms');
	if (!$terms) {
		$terms = osm_query('api.php?action=getTerms');
		update_cached_osm('terms', $terms);
	}
	$activeRoles = get_option('OnlineScoutManager_activeRoles');
	if (is_array($activeRoles)) {
		foreach ($activeRoles as $sectionid => $role) {
			// OSM's own 'past' flag isn't a reliable signal for "current term" - pick
			// whichever term's date range actually contains today instead.
			$role['termid'] = osm_get_current_termid($terms[$role['sectionid']] ?? array());
			$activeRoles[$sectionid] = $role;
		}
		update_option('OnlineScoutManager_activeRoles', $activeRoles);
	}
	return $terms;
}
function get_cached_osm($key) {
	$val = get_option('OnlineScoutManager_'.$key);
	if ($val and $val['time'] > time() - 86400) {
		return $val['content'];
	} else {
		return false;
	}
}
function update_cached_osm($key, $val, $timeOffset = 0) {
	$values['time'] = time() + $timeOffset;
	$values['content'] = $val;
	update_option('OnlineScoutManager_'.$key, $values);
}
?>