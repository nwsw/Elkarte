<?php

class Dlcode_Controller extends Action_Controller
{
	public function action_index()
	{
		global $txt, $modSettings, $user_info, $context, $topic;

		$context['no_last_modified'] = true;
		
		// Make sure some code block was requested!
		if (empty($_REQUEST['topic']) || empty($_REQUEST['msg']) || empty($_REQUEST['block']) || !is_numeric($_REQUEST['block']))
			throw new Elk_Exception('no_access', false);

		require_once(SUBSDIR . '/Post.subs.php');

		$real_msgid = (int) $_REQUEST['msg'];
		$real_codeblocknum = (int) $_REQUEST['block'];

		// use the view_attachments permission as a basis for authenticating this
		isAllowedTo('view_attachments');

		$db = database();
		$msg = $time_posted = false;

		// Make sure this attachment is on this board.
		$request = $db->query('', '
			SELECT m.body, m.poster_time, m.modified_time
			FROM ({db_prefix}boards AS b, {db_prefix}messages AS m)
			WHERE b.id_board = m.id_board
				AND {query_see_board}
				AND m.id_msg = {int:id_msg} 
				AND m.id_topic = {int:id_topic}
			LIMIT 1',
			array(
				'id_msg' => $real_msgid,
				'id_topic' => $topic,
			)
		);

		if ($db->num_rows($request) != 0)
			list ($msg,$time_posted, $time_modified) = $db->fetch_row($request);

		$db->free_result($request);

		if (!$msg) throw new Elk_Exception('no_access', false);

		if (!$time_modified) $time_modified = $time_posted;

		$msg = preg_replace('~\[nobbc\](.+?)\[/nobbc\]~i', '', un_preparsecode($msg));

		$msg_parts = preg_split('~(\[/code\]|\[code(?:=[^\]]+)?\])~i', $msg, -1, PREG_SPLIT_DELIM_CAPTURE);

		if ((4*$real_codeblocknum) > count($msg_parts)) throw new Elk_Exception('no_access', false);

		$real_codeblock = trim(html_entity_decode($msg_parts[(4*$real_codeblocknum)-2],ENT_QUOTES,'UTF-8'));
		$real_filetype = strtolower(substr($msg_parts[(4*$real_codeblocknum)-3],6,-1));
		if (!$real_filetype || !in_array($real_filetype,array('ini','nwc','php','py','nwctxt','nwcitree','lua'))) $real_filetype = 'txt';
		if ($real_filetype == 'nwc') $real_filetype = 'nwctxt';
		$real_filename = "msg".$real_msgid."n".$real_codeblocknum.".".$real_filetype;

		// clear any output that was made before now
		while (ob_get_level() > 0) @ob_end_clean();
		ob_start('ob_gzhandler');

		$file_md5 = '"' . md5($real_codeblock) . '"';

		header('Pragma: ');
		if (!isBrowser('gecko'))
			header('Content-Transfer-Encoding: binary');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $time_modified) . ' GMT');
		header('Accept-Ranges: none');
		header('Connection: close');
		header('ETag: ' . $file_md5);
		header('Content-Disposition: ' . 'attachment' . '; filename="' . $real_filename . '"');

		$mimeType = ($real_filetype == 'txt') ? 'text/plain' : 'application/x-'.$real_filetype;
		if ($real_filetype == 'py') $mimeType .= 'thon';
		header('Content-Type: '.$mimeType);
		header('Cache-Control: max-age=' . (525600 * 60) . ', private');

		if ($real_filetype == 'nwctxt') {
			// we need to convert old text blocks back to ISO-8859-1
			$nwctxtVersion = 0.0;
			if (preg_match('/\!(NoteWorthyComposerClip|NoteWorthyComposer)\(([0-9\.]+)/',$real_codeblock,$m)) $nwctxtVersion = $m[2];
			if ($nwctxtVersion > 2.6) echo $real_codeblock;
			else echo mb_convert_encoding($real_codeblock, "ISO-8859-1", "UTF-8");
			}
		else {
			echo $real_codeblock;
			}

		obExit(false);
	}
}