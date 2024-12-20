<?php

/**
 * Attachment display.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1.9
 *
 */

use ElkArte\Errors\AttachmentErrorContext;

/**
 * Attachment_Controller class.
 *
 * What it does:
 *
 * - Handles the downloading of an attachment or avatar
 * - Handles the uploading of attachments via Ajax
 * - increments the download count where applicable
 *
 * @package Attachments
 */
class Attachment_Controller extends Action_Controller
{
	/**
	 * {@inheritdoc }
	 */
	public function needTheme($action = '')
	{
		global $modSettings, $user_info, $maintenance;

		// If guests are not allowed to browse and the use is a guest... kick him!
		if (empty($modSettings['allow_guestAccess']) && $user_info['is_guest'])
		{
			return true;
		}

		// If not in maintenance or allowed to use the forum in maintenance
		if (empty($maintenance) || allowedTo('admin_forum'))
		{
			$sa = $this->_req->get('sa');

			return ($sa === 'ulattach' || $sa === 'rmattach') ? true : false;
		}
		// else... politely kick him (or her).
		else
		{
			return true;
		}
	}

	/**
	 * {@inheritdoc }
	 */
	public function trackStats($action = '')
	{
		return false;
	}

	/**
	 * The default action is to download an attachment.
	 * This allows ?action=attachment to be forwarded to action_dlattach()
	 */
	public function action_index()
	{
		// add an subaction array to act accordingly
		$subActions = array(
			'dlattach' => array($this, 'action_dlattach'),
			'tmpattach' => array($this, 'action_tmpattach'),
			'ulattach' => array($this, 'action_ulattach'),
			'rmattach' => array($this, 'action_rmattach'),
		);

		// Setup the action handler
		$action = new Action('attachments');
		$subAction = $action->initialize($subActions, 'dlattach');

		// Call the action
		$action->dispatch($subAction);
	}

	/**
	 * Function to upload attachments via ajax calls
	 *
	 * - Currently called by drag drop attachment functionality
	 * - Pass the form data with session vars
	 * - Responds back with errors or file data
	 */
	public function action_ulattach()
	{
		global $context, $modSettings, $txt;

		$resp_data = array();
		loadLanguage('Errors');
		$context['attachments']['can']['post'] = !empty($modSettings['attachmentEnable']) && $modSettings['attachmentEnable'] == 1 && (allowedTo('post_attachment') || ($modSettings['postmod_active'] && allowedTo('post_unapproved_attachments')));

		// Set up the template details
		$template_layers = Template_Layers::instance();
		$template_layers->removeAll();
		loadTemplate('Json');
		$context['sub_template'] = 'send_json';

		// Make sure the session is still valid
		if (checkSession('request', '', false) != '')
		{
			$context['json_data'] = array('result' => false, 'data' => $txt['session_timeout_file_upload']);
			return false;
		}

		// We should have files, otherwise why are we here?
		if (isset($_FILES['attachment']))
		{
			loadLanguage('Post');

			$attach_errors = AttachmentErrorContext::context();
			$attach_errors->activate();

			if ($context['attachments']['can']['post'] && empty($this->_req->post->from_qr))
			{
				require_once(SUBSDIR . '/Attachments.subs.php');

				$process = $this->_req->getPost('msg', 'intval', '');
				processAttachments($process);
			}

			// Any mistakes?
			if ($attach_errors->hasErrors())
			{
				$errors = $attach_errors->prepareErrors();

				// Bad news for you, the attachments did not process, lets tell them why
				foreach ($errors as $key => $error)
				{
					$resp_data[] = $error;
				}

				$context['json_data'] = array('result' => false, 'data' => $resp_data);
			}
			// No errors, lets get the details of what we have for our response back
			else
			{
				foreach ($_SESSION['temp_attachments'] as $attachID => $val)
				{
					// We need to grab the name anyhow
					if (!empty($val['tmp_name']))
					{
						$resp_data = array(
							'name' => $val['name'],
							'attachid' => $val['public_attachid'],
							'size' => $val['size']
						);
					}
				}

				$context['json_data'] = array('result' => true, 'data' => $resp_data);
			}
		}
		// Could not find the files you claimed to have sent
		else
			$context['json_data'] = array('result' => false, 'data' => $txt['no_files_uploaded']);
	}

	/**
	 * Function to remove attachments which were added via ajax calls
	 *
	 * What it does:
	 *
	 * - Currently called by drag drop attachment functionality
	 * - Requires file name and file path
	 * - Responds back with success or error
	 */
	public function action_rmattach()
	{
		global $context, $txt, $user_info;

		// Prepare the template so we can respond with json
		$template_layers = Template_Layers::instance();
		$template_layers->removeAll();
		loadTemplate('Json');
		$context['sub_template'] = 'send_json';

		// Make sure the session is valid
		if (checkSession('request', '', false) !== '')
		{
			loadLanguage('Errors');
			$context['json_data'] = array('result' => false, 'data' => $txt['session_timeout']);

			return false;
		}

		// We need a filename and path or we are not going any further
		if (isset($this->_req->post->attachid))
		{
			$result = false;
			if (!empty($_SESSION['temp_attachments']))
			{
				require_once(SUBSDIR . '/Attachments.subs.php');

				$attachId = getAttachmentIdFromPublic($this->_req->post->attachid);

				$result = removeTempAttachById($attachId);
				if ($result === true)
				{
					$context['json_data'] = array('result' => true);
				}
			}

			if ($result !== true)
			{
				require_once(SUBSDIR . '/ManageAttachments.subs.php');
				$attachId = $this->_req->getPost('attachid', 'intval');
				if (canRemoveAttachment($attachId, $user_info['id']))
				{
					$result_tmp = removeAttachments(array('id_attach' => $attachId), '', true);
					if (!empty($result_tmp))
					{
						$context['json_data'] = array('result' => true);
						$result = true;
					}
					else
					{
						$result = $result_tmp;
					}
				}
			}

			if ($result !== true)
			{
				loadLanguage('Errors');
				$context['json_data'] = array('result' => false, 'data' => $txt[!empty($result) ? $result : 'attachment_not_found']);
			}
		}
		else
		{
			loadLanguage('Errors');
			$context['json_data'] = array('result' => false, 'data' => $txt['attachment_not_found']);
		}
	}

	/**
	 * Generates a language image based on text for display.
	 *
	 * @param null|string $text
	 * @throws \Elk_Exception
	 */
	public function action_no_attach($text = null)
	{
		global $txt;

		require_once(SUBSDIR . '/Graphics.subs.php');
		if ($text === null)
		{
			loadLanguage('Errors');
			$text = $txt['attachment_not_found'];
		}

		$this->_send_headers('no_image', 'no_image', 'image/png', false, 'inline', 'no_image.png', true, false);

		$img = generateTextImage($text, 200);

		if ($img === false)
		{
			throw new Elk_Exception('no_access', false);
		}

		echo $img;
		obExit(false);
	}

	/**
	 * Downloads an attachment or avatar, and increments the download count.
	 *
	 * What it does:
	 *
	 * - It requires the view_attachments permission. (not for avatars!)
	 * - It disables the session parser, and clears any previous output.
	 * - It is accessed via the query string ?action=dlattach.
	 * - Views to attachments and avatars do not increase hits and are not logged
	 *   in the "Who's Online" log.
	 */
	public function action_dlattach()
	{
		global $modSettings, $user_info, $context, $topic, $board, $settings, $txt;

		// Some defaults that we need.
		$context['no_last_modified'] = true;
		$filename = null;

		// Make sure some attachment was requested!
		if (!isset($this->_req->query->attach))
		{
			if (!isset($this->_req->query->id))
				$this->action_no_attach();

			if ($this->_req->query->id === 'ila')
			{
				loadLanguage('index');
				$this->action_no_attach($txt['awaiting_approval']);
			}
		}

		// We need to do some work on attachments and avatars.
		require_once(SUBSDIR . '/Attachments.subs.php');

		// Temporary attachment, special case...
		if (isset($this->_req->query->attach) && strpos($this->_req->query->attach, 'post_tmp_' . $user_info['id'] . '_') !== false)
		{
			$this->action_tmpattach();
			return;
		}
		else
		{
			$id_attach = isset($this->_req->query->attach)
				? (int) $this->_req->query->attach
				: (int) $this->_req->query->id;
		}

		if ($this->_req->getQuery('type') === 'avatar')
		{
			$attachment = getAvatar($id_attach);

			$is_avatar = true;
			$this->_req->query->image = true;
		}
		// This is just a regular attachment...
		else
		{
			if (empty($topic) && !empty($id_attach))
			{
				$id_board = 0;
				$id_topic = 0;
				$attachPos = getAttachmentPosition($id_attach);
				if ($attachPos !== false)
				{
					list($id_board, $id_topic) = array_values($attachPos);
				}
			}
			else
			{
				$id_board = $board;
				$id_topic = $topic;
			}

			isAllowedTo('view_attachments', $id_board);

			if ($this->_req->getQuery('thumb') === null)
			{
				$attachment = getAttachmentFromTopic($id_attach, $id_topic);
			}
			else
			{
				$this->_req->query->image = true;
				$attachment = getAttachmentThumbFromTopic($id_attach, $id_topic);

				// 1 is the file name, no file name, no thumbnail, no image.
				if (empty($attachment[1]))
				{
					$full_attach = getAttachmentFromTopic($id_attach, $id_topic);
					$attachment[1] = !empty($full_attach[1]) ? $full_attach[1] : '';
					$attachment[4] = 0;
					$attachment[5] = 0;
					$attachment[7] = $full_attach[7];
					$attachment[8] = $full_attach[8];

					// return mime type ala mimetype extension
					$check = returnMimeThumb(!empty($full_attach[3]) ? $full_attach[3] : 'default');

					if ($check !== false)
					{
						$attachment[3] = 'png';
						$attachment[6] = 'image/png';
						$filename = $check;
					}
					else
					{
						if (!is_array($modSettings['attachmentUploadDir']))
						{
							$attachmentUploadDir = unserialize($modSettings['attachmentUploadDir']);
						}
						else
						{
							$attachmentUploadDir = $modSettings['attachmentUploadDir'];
						}

						$filename = $attachmentUploadDir[$modSettings['currentAttachmentUploadDir']] . '/' . $attachment[1];
					}

					if (substr(get_finfo_mime($filename), 0, 5) !== 'image')
					{
						$attachment[3] = 'png';
						$attachment[6] = 'image/png';
						$filename = $settings['theme_dir'] . '/images/mime_images/default.png';
					}

				}
			}
		}

		if (empty($attachment))
		{
			return $this->action_no_attach();
		}

		list ($id_folder, $real_filename, $file_hash, $file_ext, $id_attach, $attachment_type, $mime_type, $is_approved, $id_member) = $attachment;

		// If it isn't yet approved, do they have permission to view it?
		if (!$is_approved && ($id_member == 0 || $user_info['id'] != $id_member) && ($attachment_type == 0 || $attachment_type == 3))
			isAllowedTo('approve_posts', $id_board);

		// Update the download counter (unless it's a thumbnail or an avatar).
		if (!empty($id_attach) && empty($is_avatar) || $attachment_type != 3)
		{
			increaseDownloadCounter($id_attach);
		}

		if ($filename === null)
		{
			$filename = getAttachmentFilename($real_filename, $id_attach, $id_folder, false, $file_hash);
		}

		$eTag = '"' . substr($id_attach . $real_filename . @filemtime($filename), 0, 64) . '"';
		$use_compression = $this->useCompression($mime_type);
		$disposition = !isset($this->_req->query->image) ? 'attachment' : 'inline';
		$do_cache = false === (!isset($this->_req->query->image) && getValidMimeImageType($file_ext) !== '');

		// Make sure the mime type warrants an inline display.
		if (isset($this->_req->query->image) && !empty($mime_type) && strpos($mime_type, 'image/') !== 0)
		{
			unset($this->_req->query->image);
			$mime_type = '';
		}
		// Does this have a mime type?
		elseif (empty($mime_type) || !(isset($this->_req->query->image) || getValidMimeImageType($file_ext) === ''))
		{
			$mime_type = '';
			if (isset($this->_req->query->image))
				unset($this->_req->query->image);
		}

		$this->_send_headers($filename, $eTag, $mime_type, $use_compression, $disposition, $real_filename, $do_cache);
		$this->send_file($filename, $mime_type);

		obExit(false);
	}

	/**
	 * If the mime type benefits from compression e.g. text/xyz and gzencode is
	 * available and the user agent accepts gzip, then return true, else false
	 *
	 * @param string $mime_type
	 * @return bool if we should compress the file
	 */
	public function useCompression($mime_type)
	{
		global $modSettings;

		// Support is available on the server
		if (!function_exists('gzencode') || empty($modSettings['enableCompressedOutput']))
		{
			return false;
		}

		// Not compressible, or not supported / requested by client
		if (!preg_match('~^(?:text/|application/(?:json|xml|rss\+xml)$)~i', $mime_type)
			|| (!isset($_SERVER['HTTP_ACCEPT_ENCODING']) || strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') === false))
		{
			return false;
		}

		return true;
	}

	/**
	 * Sends the requested file to the user.  If the file is compressible e.g.
	 * has a mine type of text/??? may compress the file prior to sending.
	 *
	 * @param string $filename
	 * @param string $mime_type
	 */
	public function send_file($filename, $mime_type)
	{
		$body = file_get_contents($filename);
		$use_compression = $this->useCompression($mime_type);
		$length = filesize($filename);

		// If we can/should compress this file
		if ($use_compression && strlen($body) > 250)
		{
			$body = gzencode($body, 2);
			$length = strlen($body);
			header('Content-Encoding: gzip');
			header('Vary: Accept-Encoding');
		}

		// Someone is getting a present
		if (!empty($length))
		{
			header('Content-Length: ' . $length);
		}

		// Forcibly end any output buffering going on.
		while (ob_get_level() > 0)
		{
			@ob_end_clean();
		}

		echo $body;
	}

	/**
	 * Simplified version of action_dlattach to send out thumbnails while creating
	 * or editing a message.
	 */
	public function action_tmpattach()
	{
		global $modSettings, $user_info, $topic;

		// Make sure some attachment was requested!
		if (!isset($this->_req->query->attach))
		{
			return $this->action_no_attach();
		}

		// We need to do some work on attachments and avatars.
		require_once(SUBSDIR . '/Attachments.subs.php');
		require_once(SUBSDIR . '/Graphics.subs.php');

		try
		{
			if (empty($topic) || (string) (int) $this->_req->query->attach !== (string) $this->_req->query->attach)
			{
				$attach_data = getTempAttachById($this->_req->query->attach);
				$file_ext = pathinfo($attach_data['name'], PATHINFO_EXTENSION);
				$filename = $attach_data['tmp_name'];
				$id_attach = $attach_data['attachid'];
				$real_filename = $attach_data['name'];
				$mime_type = $attach_data['type'];
			}
			else
			{
				$id_attach = $this->_req->getQuery('attach', 'intval', -1);

				isAllowedTo('view_attachments');
				$attachment = getAttachmentFromTopic($id_attach, $topic);

				if (empty($attachment))
				{
					return $this->action_no_attach();
				}

				list ($id_folder, $real_filename, $file_hash, $file_ext, $id_attach, $attachment_type, $mime_type, $is_approved, $id_member) = $attachment;

				// If it isn't yet approved, do they have permission to view it?
				if (!$is_approved && ($id_member == 0 || $user_info['id'] != $id_member) && ($attachment_type == 0 || $attachment_type == 3))
					isAllowedTo('approve_posts');

				$filename = getAttachmentFilename($real_filename, $id_attach, $id_folder, false, $file_hash);
			}
		}
		catch (\Exception $e)
		{
			throw new Elk_Exception($e->getMessage(), false);
		}
		$resize = true;

		// Return mime type ala mimetype extension
		if (substr(get_finfo_mime($filename), 0, 5) !== 'image')
		{
			$checkMime = returnMimeThumb($file_ext);
			$mime_type = 'image/png';
			$resize = false;
			$filename = $checkMime;
		}

		$eTag = '"' . substr($id_attach . $real_filename . filemtime($filename), 0, 64) . '"';
		$use_compression = $this->useCompression($mime_type);
		$do_cache = false === (!isset($this->_req->query->image) && getValidMimeImageType($file_ext) !== '');

		$this->_send_headers($filename, $eTag, $mime_type, $use_compression, 'inline', $real_filename, $do_cache);

		$max_width = $this->_req->is_set('thumb') && !empty($modSettings['attachmentThumbWidth']) ? $modSettings['attachmentThumbWidth'] : 250;
		$max_height = $this->_req->is_set('thumb') && !empty($modSettings['attachmentThumbHeight']) ? $modSettings['attachmentThumbHeight'] : 250;
		$format = setDefaultFormat($filename);
		if ($resize && resizeImageFile($filename, $filename . '_thumb', $max_width, $max_height, $format, false, false))
		{
			if (!empty($modSettings['attachment_autorotate']))
			{
				autoRotateImage($filename . '_thumb');
			}

			$filename = $filename . '_thumb';
		}

		$this->send_file($filename, $mime_type);

		obExit(false);
	}

	/**
	 * Takes care of sending out the most common headers.
	 *
	 * @param string $filename Full path+file name of the file in the filesystem
	 * @param string $eTag ETag cache validator
	 * @param string $mime_type The mime-type of the file
	 * @param boolean $use_compression If use gzip compression - Deprecated since 1.1.9
	 * @param string $disposition The value of the Content-Disposition header
	 * @param string $real_filename The original name of the file
	 * @param boolean $do_cache If send the a max-age header or not
	 * @param boolean $check_filename When false, any check on $filename is skipped
	 */
	protected function _send_headers($filename, $eTag, $mime_type, $use_compression, $disposition, $real_filename, $do_cache, $check_filename = true)
	{
		global $txt;

		// No point in a nicer message, because this is supposed to be an attachment anyway...
		if ($check_filename === true && !file_exists($filename))
		{
			loadLanguage('Errors');

			header((preg_match('~HTTP/1\.[01]~i', $_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 404 Not Found');
			header('Content-Type: text/plain; charset=UTF-8');

			// We need to die like this *before* we send any anti-caching headers as below.
			die('404 - ' . $txt['attachment_not_found']);
		}

		// If it hasn't been modified since the last time this attachment was retrieved, there's no need to display it again.
		if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']))
		{
			list ($modified_since) = explode(';', $_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if ($check_filename === false || strtotime($modified_since) >= filemtime($filename))
			{
				@ob_end_clean();

				// Answer the question - no, it hasn't been modified ;).
				header('HTTP/1.1 304 Not Modified');
				exit;
			}
		}

		// Check whether the ETag was sent back, and cache based on that...
		if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && strpos($_SERVER['HTTP_IF_NONE_MATCH'], $eTag) !== false)
		{
			@ob_end_clean();

			header('HTTP/1.1 304 Not Modified');
			exit;
		}

		// Send the attachment headers.
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $check_filename === true ? filemtime($filename) : time() - 525600 * 60) . ' GMT');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('ETag: ' . $eTag);

		if (!empty($mime_type) && strpos($mime_type, 'image/') === 0)
		{
			header('Content-Type: ' . $mime_type);
		}
		else
		{
			header('Content-Type: application/octet-stream');
		}

		// Different browsers like different standards...
		$filename = str_replace('"', '', $real_filename);

		// Send as UTF-8 if the name requires that
		$altName = '';
		if (preg_match('~[\x80-\xFF]~', $filename))
			$altName = "; filename*=UTF-8''" . rawurlencode($filename);

		header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' . $altName);

		// If this has an "image extension" - but isn't actually an image - then ensure it isn't cached cause of silly IE.
		if ($do_cache === true)
		{
			header('Cache-Control: max-age=' . (525600 * 60) . ', private');
		}
		else
		{
			header('Pragma: no-cache');
			header('Cache-Control: no-cache');
		}

		// Try to buy some time...
		detectServer()->setTimeLimit(600);
	}
}
