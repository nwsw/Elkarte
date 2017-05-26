<?php

/**
 * Recent Topics
 *
 * @name      Recent Topics
 * @copyright Recent Topics contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 0.0.4
 *
 */

class Recent_Topics_Integrate
{
	protected static $_number_recent_posts = 0;

	public static function boardindex_before()
	{
		global $settings;

		if (!isset($settings['number_recent_posts']) || $settings['number_recent_posts'] < 2)
			return;

		self::$_number_recent_posts = $settings['number_recent_posts'];
		$settings['number_recent_posts'] = 0;
	}

	public static function get()
	{
		global $user_info, $context,$txt;

		if (empty(self::$_number_recent_posts))
			return;

		$latestTopicOptions = array(
			'number_posts' => self::$_number_recent_posts,
			'id_member' => $user_info['id'],
		);

		$context['latest_posts'] = cache_quick_get('boardindex-latest_topics:' . md5($user_info['query_wanna_see_board'] . $user_info['language']), 'subs/RecentTopics.class.php', 'cache_getLastTopics', array($latestTopicOptions));

		if (!empty($context['latest_posts']) || !empty($context['latest_post']))
		{
			$txt['recent_posts'] = 'Recent';
			array_unshift($context['info_center_callbacks'], 'recent_posts');
		}
	}

	public static function create_post()
	{
		global $user_info, $modSettings;

		if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] < 2)
			cache_put_data('boardindex-latest_topics:' . md5($user_info['query_wanna_see_board'] . $user_info['language']), null, 60);
	}
}

if (!function_exists('cache_getLastTopics'))
{
	/**
	 * Callback-function for the cache for getLastTopics().
	 *
	 * @param mixed[] $latestTopicOptions
	 */
	function cache_getLastTopics($latestTopicOptions)
	{
		return array(
			'data' => getLastTopics($latestTopicOptions),
			'expires' => time() + 60,
			'post_retri_eval' => '
				foreach ($cache_block[\'data\'] as $k => $post)
				{
					$cache_block[\'data\'][$k] += array(
						\'time\' => standardTime($post[\'raw_timestamp\']),
						\'html_time\' => htmlTime($post[\'raw_timestamp\']),
						\'timestamp\' => $post[\'raw_timestamp\'],
					);
				}',
		);
	}

	/**
	 * Get the latest posts of a forum.
	 *
	 * @param mixed[] $latestTopicOptions
	 * @return array
	 */
	function getLastTopics($latestTopicOptions)
	{
		global $scripturl, $modSettings, $txt;

		$db = database();

		// Find all the posts. Newer ones will have higher IDs. (assuming the last 20 * number are accessable...)
		// @todo SLOW This query is now slow, NEEDS to be fixed.  Maybe break into two?
		$request = $db->query('substring', '
			SELECT
				ml.poster_time, mf.subject, ml.id_topic, ml.id_member, ml.id_msg, t.id_first_msg, ml.id_msg_modified,
				' . ($latestTopicOptions['id_member'] == 0 ? '0' : 'IFNULL(lt.id_msg, IFNULL(lmr.id_msg, -1)) + 1') . ' AS new_from,
				IFNULL(mem.real_name, ml.poster_name) AS poster_name, t.id_board, b.name AS board_name,
				SUBSTRING(ml.body, 1, 385) AS body, ml.smileys_enabled
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				LEFT JOIN {db_prefix}messages AS mf ON (t.id_first_msg = mf.id_msg)
				LEFT JOIN {db_prefix}messages AS ml ON (t.id_last_msg = ml.id_msg)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = ml.id_member)' . ($latestTopicOptions['id_member'] == 0 ? '' : '
				LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
				LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = t.id_board AND lmr.id_member = {int:current_member})') . '
			WHERE ml.id_msg >= {int:likely_max_msg}' .
				(!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle_board}' : '') . '
				AND {query_wanna_see_board}' . ($modSettings['postmod_active'] ? '
				AND t.approved = {int:is_approved}' : '') . '
			ORDER BY t.id_last_msg DESC
			LIMIT {int:num_msgs}',
			array(
				'likely_max_msg' => max(0, $modSettings['maxMsgID'] - 50 * $latestTopicOptions['number_posts']),
				'recycle_board' => $modSettings['recycle_board'],
				'is_approved' => 1,
				'num_msgs' =>  $latestTopicOptions['number_posts'],
				'current_member' =>  $latestTopicOptions['id_member'],
			)
		);

		$posts = array();
		while ($row = $db->fetch_assoc($request))
		{
			// Censor the subject and post for the preview ;).
			censorText($row['subject']);
			censorText($row['body']);

			$row['body'] = strip_tags(strtr(parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']), array('<br />' => '&#10;')));
			$row['body'] = Util::shorten_text($row['body'], !empty($modSettings['lastpost_preview_characters']) ? $modSettings['lastpost_preview_characters'] : 128, true);

			// Build the array.
			$post = array(
				'board' => array(
					'id' => $row['id_board'],
					'name' => $row['board_name'],
					'href' => $scripturl . '?board=' . $row['id_board'] . '.0',
					'link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0">' . $row['board_name'] . '</a>'
				),
				'topic' => $row['id_topic'],
				'poster' => array(
					'id' => $row['id_member'],
					'name' => $row['poster_name'],
					'href' => empty($row['id_member']) ? '' : $scripturl . '?action=profile;u=' . $row['id_member'],
					'link' => empty($row['id_member']) ? $row['poster_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['poster_name'] . '</a>'
				),
				'subject' => $row['subject'],
				'short_subject' => Util::shorten_text($row['subject'], $modSettings['subject_length']),
				'preview' => $row['body'],
				'time' => standardTime($row['poster_time']),
				'html_time' => htmlTime($row['poster_time']),
				'timestamp' => forum_time(true, $row['poster_time']),
				'raw_timestamp' => $row['poster_time'],
				'href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . ';topicseen#msg' . $row['id_msg'],
				'link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . ';topicseen#msg' . $row['id_msg'] . '" rel="nofollow">' . $row['subject'] . '</a>',
				'new' => $row['new_from'] <= $row['id_msg_modified'],
				'new_from' => $row['new_from'],
				'newtime' => $row['new_from'],
				'new_href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['new_from'] . '#new',
			);
			if ($post['new'])
				$post['link'] .= '
								<a class="new_posts" href="' . $post['new_href'] . '" id="newicon' . $row['id_msg'] . '">' . $txt['new'] . '</a>';

			$posts[] = $post;
		}
		$db->free_result($request);

		return $posts;
	}
}