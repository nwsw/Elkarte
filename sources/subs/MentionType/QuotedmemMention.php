<?php

/**
 * Handles mentioning of members whose messages has been quoted.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1.7
 *
 */

namespace ElkArte\sources\subs\MentionType;

/**
 * Class Quotedmem_Mention
 *
 * Handles mentioning of members whose messages has been quoted
 *
 * @package ElkArte\sources\subs\MentionType
 */
class Quotedmem_Mention extends Mention_BoardAccess_Abstract
{
	/**
	 * {@inheritdoc }
	 */
	protected static $_type = 'quotedmem';

	/**
	 * {@inheritdoc }
	 */
	public static function getEvents($controller)
	{
		$methods = array(
			'post' => array(
				'after_save_post' => array('msgOptions', 'becomesApproved', 'posterOptions')
			),
			'display' => array('prepare_context' => array('virtual_msg')),
		);

		return isset($methods[$controller]) ? $methods[$controller] : array();
	}

	/**
	 * Listener attached to the prepare_context event of the Display controller
	 * used to mark a mention as read.
	 *
	 * @param int $virtual_msg
	 */
	public function display_prepare_context($virtual_msg)
	{
		global $modSettings;

		// Mark the mention as read if requested
		if (isset($_REQUEST['mentionread']) && !empty($virtual_msg))
		{
			$mentions = new \Mentioning(database(), new \Data_Validator(), $modSettings['enabled_mentions']);
			$mentions->markread((int) $_REQUEST['item']);
		}
	}

	/**
	 * Listener attached to the after_save_post event of the Post controller.
	 *
	 * @param mixed[] $msgOptions
	 * @param bool $becomesApproved
	 * @param mixed[] $posterOptions
	 */
	public function post_after_save_post($msgOptions, $becomesApproved, $posterOptions)
	{
		$status = $becomesApproved ? 'new' : 'unapproved';
		$this->_sendNotification($msgOptions, $status, $posterOptions);
	}

	/**
	 * Checks if a message has been quoted and if so notifies the owner
	 *
	 * @param mixed[] $msgOptions The message options array
	 * @param string $status
	 * @param mixed[] $posterOptions
	 */
	protected function _sendNotification($msgOptions, $status, $posterOptions)
	{
		$quoted_names = $this->_findQuotedMembers($msgOptions['body']);

		if (!empty($quoted_names))
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$members_id = membersBy(array(array('or' => 'member_names')), array('member_names' => $quoted_names, 'limit' => count($quoted_names)));
		}

		if (!empty($members_id))
		{
			// If the message was edited, attribute the quote to the starter not the modifier to prevent
			// the sending of another notification
			$modified = (isset($posterOptions['id_starter']));

			$notifier = \Notifications::instance();
			$notifier->add(new \Notifications_Task(
				'quotedmem',
				$msgOptions['id'],
				$modified ? $posterOptions['id_starter'] : $posterOptions['id'],
				array('id_members' => $members_id, 'notifier_data' => $posterOptions, 'status' => $status, 'subject' =>  $msgOptions['subject'],)
			));
		}
	}

	/**
	 * Finds member names in quote tags present in a passed string.
	 *
	 * @param string $text the string to look into for member names
	 *
	 * @return string[] An array of member names
	 */
	protected function _findQuotedMembers($text)
	{
/*
The following bbcode is for testing, to be moved to a test when ready.

[quote author=emanuele date=1430141592 link=msg=972]
[quote author=lele]test[/quote]
[/quote]

[quote author=lele non nested]test[/quote]

[quote author=lele full date=1430141592 link=msg=972]test[/quote]


[quote author=emanuele date=1430141592 link=msg=972]
[quote author=lele multi1]
[quote author=lele multi2]
[quote author=lele multi3]
[quote author=lele]test[/quote]
[/quote]
[/quote]
[/quote]
[/quote]
*/
		if (strpos($text, '[quote ') !== false)
		{
			$quoted = array();
			$blocks = preg_split('~\[quote~', $text);

			$skip_next = false;
			foreach ($blocks as $block)
			{
				if (empty($block))
				{
					continue;
				}

				if (!$skip_next)
				{
					preg_match('~author=(.*?)(\]|date=|link=)~', $block, $match);

					if (!empty($match[1]))
					{
						$quoted[] = trim($match[1]);
					}
				}

				$skip_next = strpos($block, '[/quote]') === false;
			}

			return array_unique($quoted);
		}
		else
		{
			return false;
		}
	}

	/**
	 * {@inheritdoc }
	 */
	public static function getModules($modules)
	{
		$modules['mentions'] = array('post', 'display');

		return $modules;
	}

	/**
	 * {@inheritdoc }
	 */
	public function getNotificationBody($lang_data, $members)
	{
		if (empty($lang_data['suffix']))
		{
			return $this->_getNotificationStrings('', array('subject' => static::$_type, 'body' => static::$_type), $members, $this->_task);
		}
		else
		{
			$keys = array('subject' => 'notify_quotedmem_' . $lang_data['subject'], 'body' => 'notify_quotedmem_' . $lang_data['body']);
		}

		$replacements = array(
			'ACTIONNAME' => $this->_task['source_data']['notifier_data']['name'],
			'SUBJECT' => $this->_task['source_data']['subject'],
			'MSGLINK' => replaceBasicActionUrl('{script_url}?msg=' . $this->_task->id_target),
		);

		return $this->_getNotificationStrings('notify_quotedmem', $keys, $members, $this->_task, array(), $replacements);
	}
}
