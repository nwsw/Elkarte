<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1.8
 *
 */

class UpgradeInstructions_upgrade_1_1_8
{
	protected $db = null;
	protected $table = null;

	public function __construct($db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	public function passwd_size_title()
	{
		return 'More space for passwd hash...';
	}

	public function passwd_size()
	{
		return array(
			array(
				'debug_title' => 'Altering passwd column to varchar(255)...',
				'function' => function()
				{
					$this->table->db_change_column('{db_prefix}members',
						'passwd',
						array(
							'type' => 'varchar',
							'size' => 255,
							'default' => ''
						)
					);
				}
			)
		);
	}
}
