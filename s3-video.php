<?php
/* 
Plugin Name: S3 Video Plugin
Plugin URI: https://github.com/anthony-mills/s3-video
Description: Upload and embed videos using your Amazon S3 account
Version: 0.981
Author: Anthony Mills
Author URI: http://www.development-cycle.com
*/

if ('s3-video.php' == basename($_SERVER['SCRIPT_FILENAME'])){
	die ('Access denied');
}

// Load required modules
require_once(WP_PLUGIN_DIR . '/s3-video/modules/player_management.php');
require_once(WP_PLUGIN_DIR . '/s3-video/modules/plugin_functionality.php');

// Load other required libraries
require_once(WP_PLUGIN_DIR . '/s3-video/includes/shared.php');
require_once(WP_PLUGIN_DIR . '/s3-video/includes/s3.php');

register_activation_hook(__FILE__, 'S3_plugin_activate');
register_deactivation_hook(__FILE__, 'S3_plugin_deactivate');

add_action('admin_menu', 's3_video_plugin_menu');
add_action('admin_enqueue_scripts', 's3_video_load_css');
add_action('admin_enqueue_scripts', 's3_video_load_js');
add_action('wp_enqueue_scripts', 's3_video_load_player_js');

// Add Ajax calls
add_action('wp_ajax_remove_video_still', 's3_video_remove_video_still');

// Add shortcodes
add_shortcode( 'S3_embed_video', 's3_video_embed_video' );
add_shortcode( 'S3_embed_playlist', 's3_video_embed_playlist' );

add_filter('media_upload_tabs', array('S3 Video', 's3_video_add_media_tabs'), 99999);

// Add deactivation hook
register_deactivation_hook( __FILE__, 's3_video_deactivate');

/*
 *  Default plugin page displaying existing media files
 */
function s3_video()
{
	s3_video_check_user_access();
	$pluginSettings = s3_video_check_plugin_settings();

	if ((isset($_GET['delete'])) && (!empty($_GET['delete']))) {
		$videoName = $_GET['delete'];
		
		$s3Access = new S3($pluginSettings['amazon_access_key'], $pluginSettings['amazon_secret_access_key'], NULL, $pluginSettings['amazon_url']);		
		require_once(WP_PLUGIN_DIR . '/s3-video/includes/video_management.php');
		$videoManagement = new s3_video_management();

		// Delete the video from S3
		$result = $s3Access->deleteObject($pluginSettings['amazon_video_bucket'], $videoName);
		
		// Delete any stills that are associated with the video
		$videoStill = $videoManagement->getVideoStillByVideoName($videoName);
		$result = $s3Access->deleteObject($pluginSettings['amazon_video_bucket'], $videoStill);						
		$videoManagement->deleteVideoStill($videoName);		

		// Delete the video from any playlists

		$result = $s3Access->deleteObject($pluginSettings['amazon_video_bucket'], $videoName);			
	
		if ($result) {
			$successMsg = $_GET['delete'] . ' was successfully deleted.';
		}
	}

	$existingVideos= s3_video_get_all_existing_video($pluginSettings);		
	
	require_once(WP_PLUGIN_DIR . '/s3-video/views/video-management/existing-videos.php');		
}

/*
 * Upload videos to S3 bucket
 */
function s3_video_upload_video()
{
	s3_video_check_user_access(); 
	$pluginSettings = s3_video_check_plugin_settings();
	$tmpDirectory = s3_video_check_upload_directory();
	$fileTypes = array('video/x-flv', 'video/x-msvideo', 'video/mp4', 'application/octet-stream', 'video/avi', 'video/x-msvideo', 
						'video/mpeg');
	if ((!empty($_FILES)) && ($_FILES['upload_video']['size'] > 0)) {
			if ((!in_array($_FILES['upload_video']['type'], $fileTypes)) && ($_FILES['upload_video']['type'] !='application/octet-stream')) {					
					$errorMsg = 'You need to provide an .flv or .mp4 file';
			} else {
				$fileName = basename($_FILES['upload_video']['name']);
				$fileName = preg_replace('/[^A-Za-z0-9_.]+/', '', $fileName);
				$videoLocation = $tmpDirectory . $fileName;
				if(move_uploaded_file($_FILES['upload_video']['tmp_name'], $videoLocation)) {
					$s3Access = new S3($pluginSettings['amazon_access_key'], $pluginSettings['amazon_secret_access_key'], NULL, $pluginSettings['amazon_url']);
					$s3Result = $s3Access->putObjectFile($videoLocation, $pluginSettings['amazon_video_bucket'], $fileName, S3::ACL_PUBLIC_READ);
					switch ($s3Result) {
		
						case 0:
							$errorMsg = 'Request unsucessful check your S3 access credentials';
						break;	
		
						case 1:
							$successMsg = 'The video has successfully been uploaded to your S3 account';					
						break;
						
					}
				} else {
            $errorMsg = 'Unable to move file to ' . $videoLocation . ' check the permissions and try again.';
        }
			}
	} else {
		if (!empty($_POST)) {
    		$errorMsg = 'There was an error uploading the video';
		}
	}
	require_once(WP_PLUGIN_DIR . '/s3-video/views/video-management/upload-video.php');
}

/*
 * Create a new playlist and add videos
 */
function s3_video_create_playlist()
{
	$pluginSettings = s3_video_check_plugin_settings();	
		
	if ((!empty($_POST['playlist_contents'])) && (!empty($_POST['playlist_name']))) {
		require_once(WP_PLUGIN_DIR . '/s3-video/includes/playlist_management.php');
		$playlistManagement = new s3_playlist_management();
		
		$playlistName = sanitize_title($_POST['playlist_name']);
		$playlistExists = $playlistManagement->getPlaylistsByTitle($playlistName);
		if (!$playlistExists) {
			$playlistResult = $playlistManagement->createPlaylist($playlistName, $_POST['playlist_contents']);
			if (!$playlistResult) {
	    		$errorMsg = 'An error occurred whilst creating the play list.';			
			} else {
				$successMsg = 'New playlist saved successfully.';			
			} 
		} else {
	    		$errorMsg = 'A playlist with this name already exists.';					
		}  
	}
	$existingVideos= s3_video_get_all_existing_video($pluginSettings);
	require_once(WP_PLUGIN_DIR . '/s3-video/views/playlist-management/create-playlist.php');	
}
 
/*
 *	Manage existing playlists of S3 based media 
 */
function s3_video_show_playlists()
{
	$pluginSettings = s3_video_check_plugin_settings();			
	require_once(WP_PLUGIN_DIR . '/s3-video/includes/playlist_management.php');
	$playlistManagement = new s3_playlist_management();
	
	if (!empty($_GET['delete'])) {
		$playlistId = preg_replace('/[^0-9]/Uis', '', $_GET['delete']);
		$playlistManagement->deletePlaylist($playlistId);
	}
	
	if (((!empty($_GET['edit'])) && (is_numeric($_GET['edit']))) || ((!empty($_GET['reorder'])) && (is_numeric($_GET['reorder'])))) {
		
		if (!empty($_GET['edit'])) {
			$playlistId = preg_replace('/[^0-9]/Uis', '', $_GET['edit']);
			
			if (!empty($_POST['playlist_contents'])) {
				$playlistManagement->deletePlaylistVideos($playlistId);
				$playlistManagement->updatePlaylistVideos($playlistId, $_POST['playlist_contents']);	
				$playlistUpdated = 1;
			} 
			$existingVideos = $playlistManagement->getPlaylistVideos($playlistId);	
			$s3Videos = s3_video_get_all_existing_video($pluginSettings);
					
			require_once(WP_PLUGIN_DIR . '/s3-video/views/playlist-management/edit-playlist.php');
		} 
		
		if (!empty($_GET['reorder'])) {
			$playlistId = preg_replace('/[^0-9]/Uis', '', $_GET['reorder']);
			$playlistVideos = $playlistManagement->getPlaylistVideos($playlistId);
			require_once(WP_PLUGIN_DIR . '/s3-video/views/playlist-management/reorder-playlist.php');	
		} 	
		
	} else {
		/*
		 * If we don't have a playlist to display a list of them all  
		 */
		$existingPlaylists = $playlistManagement->getAllPlaylists();	
		require_once(WP_PLUGIN_DIR . '/s3-video/views/playlist-management/playlist-management.php');
	}
	
}

/**
 * Display a page for handling the meta data belonging to a video
 */ 
function s3_video_meta_data()
{
	$pluginSettings = s3_video_check_plugin_settings();
	$videoName = urldecode($_GET['video']);
	if (empty($videoName)) {
		die('Video not found..');
	}
		
	require_once(WP_PLUGIN_DIR . '/s3-video/includes/video_management.php');
	$videoManagement = new s3_video_management();			
				
	s3_video_check_user_access(); 
	$pluginSettings = s3_video_check_plugin_settings();
	$tmpDirectory = s3_video_check_upload_directory();	
	
	if ((!empty($_FILES)) && ($_FILES['upload_still']['size'] > 0)) {
			$stillTypes = array('image/gif', 'image/png', 'image/jpeg');
			if ((!in_array($_FILES['upload_still']['type'], $stillTypes)) || ($_FILES['upload_still']['error'] > 0)) {
				$errorMsg = 'The uploaded file is not able to be used as a video still.';
			} else {
				$imageDimensions = getimagesize($_FILES['upload_still']['tmp_name']);
				if (($imageDimensions[0] < 200) || ($imageDimensions[1] < 200) || ($imageDimensions[0] > 3000) || ($imageDimensions[1] > 3000)) {
					$errorMsg = 'Your video still needs to be over 200px x 200px in size and under 3000px x 3000px';
				} else {				
					$fileName = time() . '_' . basename($_FILES['upload_still']['name']);
					$fileName = preg_replace('/[^A-Za-z0-9_.]+/', '', $fileName);
					$imageLocation = $tmpDirectory . $fileName;
					if(move_uploaded_file($_FILES['upload_still']['tmp_name'], $imageLocation)) {
						$s3Access = new S3($pluginSettings['amazon_access_key'], $pluginSettings['amazon_secret_access_key'], NULL, $pluginSettings['amazon_url']);
						$s3Result = $s3Access->putObjectFile($imageLocation, $pluginSettings['amazon_video_bucket'], $fileName, S3::ACL_PUBLIC_READ);
						switch ($s3Result) {
							case 0:
								$errorMsg = 'Request unsucessful check your S3 access credentials';
							break;	
			
							case 1:
								$successMsg = 'The image has successfully been uploaded to your S3 account';					
								
								// Save the image to the database
								$videoManagement->deleteVideoStill($videoName);
								$s3Access = new S3($pluginSettings['amazon_access_key'], $pluginSettings['amazon_secret_access_key'], NULL, $pluginSettings['amazon_url']);
								$result = $s3Access->deleteObject($pluginSettings['amazon_video_bucket'], $_POST['image_name']);
								
								$videoManagement->createVideoStill($fileName, $videoName);
							break;
						}
				}
			}
		}
	}

	// Check and see if there is a still in the database for this video
	$videoStill = $videoManagement->getVideoStillByVideoName($videoName);
	$stillFile = '';
	if (!empty($videoStill)) {
		$stillFile = $videoStill;
		$videoStill = 'http://' . $pluginSettings['amazon_video_bucket'] .'.'.$pluginSettings['amazon_url'] . '/' . urlencode($videoStill);
	}
	
	require_once(WP_PLUGIN_DIR . '/s3-video/views/video-management/meta-data.php');	
		
} 

/**
 * 
 * Delete a still thats associated with a video
 * 
 */
function s3_video_remove_video_still()
{
	if ((!empty($_POST)) && (!empty($_POST['image_name'])) && (!empty($_POST['video_name']))) {
		$pluginSettings = s3_video_check_plugin_settings();	
		
		require_once(WP_PLUGIN_DIR . '/s3-video/includes/video_management.php');
		$videoManagement = new s3_video_management();
		
		$videoManagement->deleteVideoStill($_POST['video_name']);	
		
		$s3Access = new S3($pluginSettings['amazon_access_key'], $pluginSettings['amazon_secret_access_key'], NULL, $pluginSettings['amazon_url']);
		$result = $s3Access->deleteObject($pluginSettings['amazon_video_bucket'], $_POST['image_name']);					
	}
	die();
}

/*
 * Load the custom style sheets for the admin pages
 */
function s3_video_load_css()
{
	wp_register_style('s3_video_default', WP_PLUGIN_URL . '/s3-video/css/style.css');
	wp_enqueue_style('s3_video_default');
	
	wp_register_style('s3_video_colorbox', WP_PLUGIN_URL . '/s3-video/css/colorbox.css');
	wp_enqueue_style('s3_video_colorbox');	
	
	wp_register_style('multiselect_css', WP_PLUGIN_URL . '/s3-video/css/chosen.css');
	wp_enqueue_style('multiselect_css');			
}

/*
 * Load javascript required by the backend administration pages
 */
function s3_video_load_js()
{	
	wp_enqueue_script('validateJSs', WP_PLUGIN_URL . '/s3-video/js/jquery.validate.js', array('jquery'), '1.0');
	wp_enqueue_script('placeholdersJS', WP_PLUGIN_URL . '/s3-video/js/jquery.placeholders.js', array('jquery'), '1.0');
	wp_enqueue_script('colorBox', WP_PLUGIN_URL . '/s3-video/js/jquery.colorbox.js', array('jquery'), '1.0');
	wp_enqueue_script('tableSorter', WP_PLUGIN_URL . '/s3-video/js/jquery.tablesorter.js', array('jquery'), '1.0');	
	wp_enqueue_script('tablePaginator', WP_PLUGIN_URL . '/s3-video/js/jquery.paginator.js', array('jquery'), '1.0');	
	wp_enqueue_script('multiSelect', WP_PLUGIN_URL . '/s3-video/js/jquery.multiselect.js', array('jquery'), '1.0');		
	wp_enqueue_script('dragDropTable', WP_PLUGIN_URL . '/s3-video/js/jquery.tablednd.js', array('jquery'), '1.0');		
	wp_enqueue_script('jTip', WP_PLUGIN_URL . '/s3-video/js/jtip.js', array('jquery'), '1.0');				
}


