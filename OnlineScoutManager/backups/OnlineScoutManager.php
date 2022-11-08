<?php
/*
Plugin Name: Online Scout Manager
Description: A collection of widgets to display data from OSM on your site.
Version: 1.1
Author: Online Youth Manager Ltd
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


function getBearerToken()
{
	$val = get_option('OnlineScoutManager_BearerTok3n');
	if ($val and $val['time'] > time() - 3600) {
		return $val['content'];
	} 

	$parts = array();
$parts['grant_type'] = 'client_credentials';

$parts['client_id'] = get_option('OnlineScoutManager_ClientID');
$parts['client_secret'] = get_option('OnlineScoutManager_ClientSecret');
$parts['scope'] = 'section:programme:read';

$data = '';
foreach ($parts as $key => $val) {
        $data .= '&'.$key.'='.urlencode($val);
}
$url = 'oauth/token';
$curl_handle = curl_init();
curl_setopt($curl_handle, CURLOPT_URL, 'https://www.onlinescoutmanager.co.uk/'.$url);
curl_setopt($curl_handle, CURLOPT_POSTFIELDS, substr($data, 1));
curl_setopt($curl_handle, CURLOPT_POST, 1);
curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl_handle, CURLOPT_HEADER, 1);
$response = curl_exec($curl_handle);


$header_size = curl_getinfo($curl_handle, CURLINFO_HEADER_SIZE);
$header = substr($response, 0, $header_size);
$body = substr($response, $header_size);

$resultArray = json_decode($body, true);

update_cached_osm('OnlineScoutManager_BearerTok3n', $resultArray['access_token'] );

return $resultArray['access_token'];

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
	curl_setopt($curl_handle, CURLOPT_URL, 'https://www.onlinescoutmanager.co.uk/'.$url);
	curl_setopt($curl_handle, CURLOPT_POSTFIELDS, substr($data, 1));
	curl_setopt($curl_handle, CURLOPT_POST, 1);
	curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . getBearerToken()));

	$msg = curl_exec($curl_handle);
	return json_decode($msg, true);	
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
			$termid = 0;
			foreach ($terms[$role['sectionid']] as $term) {
				if ($term['past']) {
					$termid = $term['termid'];
				}
			}
			$role['termid'] = $termid;
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