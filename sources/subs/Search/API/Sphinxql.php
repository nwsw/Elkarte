<?php

/**
 * Used when an Sphinx search daemon is running and Access is via Sphinx's own
 * implementation of MySQL network protocol (SphinxQL)
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1.9
 *
 */

namespace ElkArte\Search\API;

/**
 * SearchAPI-Sphinxql.class.php, SphinxQL API,
 *
 * What it does:
 *
 * - Used when an Sphinx search daemon is running
 * - Access is via Sphinx's own implementation of MySQL network protocol (SphinxQL)
 * - Requires Sphinx 2 or higher
 *
 * @package Search
 */
class Sphinxql extends SearchAPI
{
	/**
	 * This is the last version of ElkArte that this was tested on, to protect against API changes.
	 * @var string
	 */
	public $version_compatible = 'ElkArte 1.1';

	/**
	 * This won't work with versions of ElkArte less than this.
	 * @var string
	 */
	public $min_elk_version = 'ElkArte 1.0 Beta 1';

	/**
	 * Is it supported?
	 * @var boolean
	 */
	public $is_supported = true;

	/**
	 * What words are banned?
	 * @var array
	 */
	protected $bannedWords = array();

	/**
	 * What is the minimum word length?
	 * @var int
	 */
	protected $min_word_length = 4;

	/**
	 * What databases are supported?
	 * @var array
	 */
	protected $supported_databases = array('MySQL');

	/**
	 * Nothing to do ...
	 */
	public function __construct()
	{
		// Is this database supported?
		if (!in_array(DB_TYPE, $this->supported_databases))
		{
			$this->is_supported = false;

			return;
		}

		parent::__construct();
	}

	/**
	 * If the settings don't exist we can't continue.
	 */
	public function isValid()
	{
		global $modSettings;

		return !(empty($modSettings['sphinx_searchd_server']) || empty($modSettings['sphinxql_searchd_port']));
	}

	/**
	 * Callback function for usort used to sort the results.
	 *
	 * - The order of sorting is: large words, small words, large words that
	 * are excluded from the search, small words that are excluded.
	 *
	 * @param string $a Word A
	 * @param string $b Word B
	 * @return integer indicating how the words should be sorted (-1, 0 1)
	 */
	public function searchSort($a, $b)
	{
		$x = strlen($a) - (in_array($a, $this->_excludedWords) ? 1000 : 0);
		$y = strlen($b) - (in_array($b, $this->_excludedWords) ? 1000 : 0);

		return $x < $y ? 1 : ($x > $y ? -1 : 0);
	}

	/**
	 * Do we have to do some work with the words we are searching for to prepare them?
	 *
	 * @param string $word word(s) to index
	 * @param mixed[] $wordsSearch The Search words
	 * @param string[] $wordsExclude Words to exclude
	 * @param boolean $isExcluded
	 */
	public function prepareIndexes($word, &$wordsSearch, &$wordsExclude, $isExcluded)
	{
		$subwords = text2words($word, null, false);

		$fulltextWord = count($subwords) === 1 ? $word : '"' . $word . '"';
		$wordsSearch['indexed_words'][] = $fulltextWord;
		if ($isExcluded)
		{
			$wordsExclude[] = $fulltextWord;
		}
	}

	/**
	 * This has it's own custom search.
	 *
	 * @param mixed[] $search_params
	 * @param mixed[] $search_words Words to search
	 * @param string[] $excluded_words Words to exclude, not used in this API
	 * @param int[] $participants
	 * @param string[] $search_results
	 * @throws \Elk_Exception
	 */
	public function searchQuery($search_params, $search_words, $excluded_words, &$participants, &$search_results)
	{
		global $user_info, $context, $modSettings;

		// Only request the results if they haven't been cached yet.
		$cached_results = array();
		if (!\Cache::instance()->getVar($cached_results, 'searchql_results_' . md5($user_info['query_see_board'] . '_' . $context['params'])))
		{
			// Create an instance of the sphinx client and set a few options.
			$mySphinx = @mysqli_connect(($modSettings['sphinx_searchd_server'] === 'localhost' ? '127.0.0.1' : $modSettings['sphinx_searchd_server']), '', '', '', (int) $modSettings['sphinxql_searchd_port']);

			// No connection, daemon not running?  log the error
			if ($mySphinx === false)
			{
				\Errors::instance()->fatal_lang_error('error_no_search_daemon');
			}

			// Compile different options for our query
			$index = (!empty($modSettings['sphinx_index_prefix']) ? $modSettings['sphinx_index_prefix'] : 'elkarte') . '_index';
			$query = 'SELECT *' . (empty($search_params['topic']) ? ', COUNT(*) num' : '') . ', WEIGHT() weights, (weights + (relevance/10)) rank FROM ' . $index;

			// Construct the (binary mode & |) query.
			$where_match = $this->_constructQuery($search_params['search']);

			// Nothing to search, return zero results
			if (trim($where_match) === '')
			{
				return 0;
			}

			if ($search_params['subject_only'])
			{
				$where_match = '@subject ' . $where_match;
			}

			$query .= ' WHERE MATCH(\'' . $where_match . '\')';

			// Set the limits based on the search parameters.
			$extra_where = array();
			if (!empty($search_params['min_msg_id']) || !empty($search_params['max_msg_id']))
			{
				$extra_where[] = 'id >= ' . $search_params['min_msg_id'] . ' AND id <= ' . (empty($search_params['max_msg_id']) ? (int) $modSettings['maxMsgID'] : $search_params['max_msg_id']);
			}
			if (!empty($search_params['topic']))
			{
				$extra_where[] = 'id_topic = ' . (int) $search_params['topic'];
			}
			if (!empty($search_params['brd']))
			{
				$extra_where[] = 'id_board IN (' . implode(',', $search_params['brd']) . ')';
			}
			if (!empty($search_params['memberlist']))
			{
				$extra_where[] = 'id_member IN (' . implode(',', $search_params['memberlist']) . ')';
			}
			if (!empty($extra_where))
			{
				$query .= ' AND ' . implode(' AND ', $extra_where);
			}

			// Put together a sort string; besides the main column sort (relevance, id_topic, or num_replies)
			$search_params['sort_dir'] = strtoupper($search_params['sort_dir']);
			$sphinx_sort = $search_params['sort'] === 'id_msg' ? 'id_topic' : $search_params['sort'];

			// Add secondary sorting based on relevance value (if not the main sort method) and age
			$sphinx_sort .= ' ' . $search_params['sort_dir'] . ($search_params['sort'] === 'relevance' ? '' : ', relevance DESC') . ', poster_time DESC';

			// Replace relevance with the returned rank value, rank uses sphinx weight + our own computed field weight relevance
			$sphinx_sort = str_replace('relevance ', 'rank ', $sphinx_sort);

			// Grouping by topic id makes it return only one result per topic, so don't set that for in-topic searches
			if (empty($search_params['topic']))
			{
				// In the topic group, date based seems the most "normal" ORDER BY param for display purposes
				$query .= ' GROUP BY id_topic WITHIN GROUP ORDER BY poster_time ASC';
			}

			$query .= ' ORDER BY ' . $sphinx_sort;
			$query .= ' LIMIT 0,' . (int) $modSettings['sphinx_max_results'];

			// Set any options needed, like field weights
			// A better ranker expression is one based off the standard Sphinx SPH04 algo but boosted for
			// exact_hit and use of proper BM25F instead of the short form BM25.  Use the following for best results
			// ranker=expr(\'sum((4 * lcs + 2 * (min_hit_pos == 1) + 4 * exact_hit) * user_weight) * 1000 + bm25f(2.0, 0.75, {subject = 4, body = 1})\'),
			// For proper use of bm25f add "index_field_lengths = 1" to your sphinx.conf and rebuild the index
			$query .= ' OPTION field_weights=(subject=' . (!empty($modSettings['search_weight_subject']) ? $modSettings['search_weight_subject'] * 10 : 100) . ',body=100),
			ranker=proximity_bm25,
			idf=plain,
			max_matches=' . (int) $modSettings['sphinx_max_results'];

			// Execute the search query.
			$request = mysqli_query($mySphinx, $query);

			// Can a connection to the daemon be made?
			if ($request === false)
			{
				// Just log the error.
				if (mysqli_error($mySphinx))
				{
					\Errors::instance()->log_error(mysqli_error($mySphinx));
				}

				\Errors::instance()->fatal_lang_error('error_no_search_daemon');
			}

			// Get the relevant information from the search results.
			$cached_results = array(
				'num_results' => 0,
				'matches' => array(),
			);

			if (mysqli_num_rows($request) != 0)
			{
				while ($match = mysqli_fetch_assoc($request))
				{
					if (empty($search_params['topic']))
					{
						$num = isset($match['num']) ? $match['num'] : (isset($match['@count']) ? $match['@count'] : 0);
					}
					else
					{
						$num = 0;
					}

					$cached_results['matches'][$match['id']] = array(
						'id' => $match['id_topic'],
						'relevance' => round($match['rank'] / 5000, 1) . '%',
						'num_matches' => $num,
						'matches' => array(),
						'weight' => round($match['weights'], 0),
						'rel' => round($match['relevance'] / 10, 0),
					);
				}
			}
			mysqli_free_result($request);
			mysqli_close($mySphinx);

			$cached_results['num_results'] = count($cached_results['matches']);

			// Store the search results in the cache.
			\Cache::instance()->put('searchql_results_' . md5($user_info['query_see_board'] . '_' . $context['params']), $cached_results, 600);
		}

		$participants = array();
		foreach (array_slice(array_keys($cached_results['matches']), (int) $_REQUEST['start'], $modSettings['search_results_per_page']) as $msgID)
		{
			$context['topics'][$msgID] = $cached_results['matches'][$msgID];
			$participants[$cached_results['matches'][$msgID]['id']] = false;
		}

		// Sentences need to be broken up in words for proper highlighting.
		$search_results = array();
		foreach ($search_words as $orIndex => $words)
		{
			$search_results = array_merge($search_results, $search_words[$orIndex]['subject_words']);
		}

		return $cached_results['num_results'];
	}

	/**
	 * Constructs a binary mode query to pass back to sphinx
	 *
	 * @param string $string The user entered query to construct with
	 * @return string A binary mode query
	 */
	private function _constructQuery($string)
	{
		$keywords = array('include' => array(), 'exclude' => array());

		// Split our search string and return an empty string if no matches
		if (!preg_match_all('~ (-?)("[^"]+"|[^" ]+)~', ' ' . $string, $tokens, PREG_SET_ORDER))
		{
			return '';
		}

		// First we split our string into included and excluded words and phrases
		$or_part = false;
		foreach ($tokens as $token)
		{
			// Strip the quotes off of a phrase
			if ($token[2][0] === '"')
			{
				$token[2] = substr($token[2], 1, -1);
				$phrase = true;
			}
			else
			{
				$phrase = false;
			}

			// Prepare this token
			$cleanWords = $this->_cleanString($token[2]);

			// Explode the cleanWords again in case the cleaning puts more spaces into it
			$addWords = $phrase ? array('"' . $cleanWords . '"') : preg_split('~ ~u', $cleanWords, -1, PREG_SPLIT_NO_EMPTY);

			// Excluding this word?
			if ($token[1] === '-')
			{
				$keywords['exclude'] = array_merge($keywords['exclude'], $addWords);
			}
			// OR'd keywords (we only do this if we have something to OR with)
			elseif (($token[2] === 'OR' || $token[2] === '|') && count($keywords['include']))
			{
				$last = array_pop($keywords['include']);
				if (!is_array($last))
				{
					$last = array($last);
				}
				$keywords['include'][] = $last;
				$or_part = true;
				continue;
			}
			// AND is implied in a Sphinx Search
			elseif ($token[2] === 'AND' || $token[2] === '&')
			{
				continue;
			}
			// If this part of the query ended up being blank, skip it
			elseif (trim($cleanWords) === '')
			{
				continue;
			}
			// Must be something they want to search for!
			else
			{
				// If this was part of an OR branch, add it to the proper section
				if ($or_part)
				{
					$keywords['include'][count($keywords['include']) - 1] = array_merge($keywords['include'][count($keywords['include']) - 1], $addWords);
				}
				else
				{
					$keywords['include'] = array_merge($keywords['include'], $addWords);
				}
			}

			// Start fresh on this...
			$or_part = false;
		}

		// Let's make sure they're not canceling each other out
		if (!count(array_diff($keywords['include'], $keywords['exclude'])))
		{
			return '';
		}

		// Now we compile our arrays into a valid search string
		$query_parts = array();
		foreach ($keywords['include'] as $keyword)
		{
			$query_parts[] = is_array($keyword) ? '(' . implode(' | ', $keyword) . ')' : $keyword;
		}

		foreach ($keywords['exclude'] as $keyword)
		{
			$query_parts[] = '-' . $keyword;
		}

		return implode(' ', $query_parts);
	}

	/**
	 * Cleans a string of everything but alphanumeric characters
	 *
	 * @param string $string A string to clean
	 * @return string A cleaned up string
	 */
	private function _cleanString($string)
	{
		// Decode the entities first
		$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

		// Lowercase string
		$string = \Util::strtolower($string);

		// Fix numbers so they search easier (phone numbers, SSN, dates, etc)
		$string = preg_replace('~([[:digit:]]+)\pP+(?=[[:digit:]])~u', '', $string);

		// Last but not least, strip everything out that's not alphanumeric
		$string = preg_replace('~[^\pL\pN]+~u', ' ', $string);

		return $string;
	}
}
