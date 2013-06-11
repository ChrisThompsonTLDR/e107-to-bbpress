<?php
	set_time_limit(0);
	ini_set('memory_limit','512M');

	$db->setDb(array(
	    'type'     => '',
	    'hostname' => '',
	    'database' => '',
	    'username' => '',
	    'password' => ''
	));

	$e107_pre      = '';
	$wp_pre        = '';
	$default_user  = '';  //  username to associate forums to, a WP user repsonsible for creating the forums
	$wp_admin_user = 1;  //  so we don't delete him

	function debug($var) {
		$bt = debug_backtrace();
		$caller = array_shift($bt);
		echo '<strong>' . $caller['file'] . ' on line: ' . $caller['line'] . '</strong>';
		echo '<pre>';print_r($var);exit;
	}

	/**
	* e107 stores data with encoded data, for no reason
	*
	* @param string $var The string to clean up.
	*/
	function scrub($var) {
		$chars = array(
			"'" => '&#039;'
		);

		return str_replace(array_values($chars),array_keys($chars),$var);
	}

	/**
	* Moves a user from e107 to WP
	*
	* @param int $id A user id that needs to be moved
	*
	* @return WP user array
	*/
	function move_user($id, $level = 0) {
		global $db, $wp_pre, $e107_pre;

		$e107_user = $db->from($e107_pre . 'user')->where(array('user_id'=>$id))->one();

		//  no user, something wrong
		if (empty($e107_user['user_id'])) {
			debug('e107 user ' . $id . ' does not exist');
		}

		//  check if already moved
		$user = $db->from($wp_pre . 'users')->where(array('user_login' => $e107_user['user_loginname']))->one();

		//  needs moved
		if (empty($user)) {
			$db->from($wp_pre . 'users')->insert(array(
				'user_login'      => $e107_user['user_loginname'],
				'user_nicename'   => $e107_user['user_name'],
				'user_email'      => $e107_user['user_email'],
				'user_registered' => date('Y-m-d H:i:s',$e107_user['user_join']),
				'display_name'    => $e107_user['user_name'],
			))->execute();

			$user = $db->from($wp_pre . 'users')->where(array('ID'=>$db->insert_id))->one();

			moved('<small>User ' . $id . ':</small> ' . $user['user_login'], $level);
		}

		if ($e107_user['user_id'] != $id) {
			debug($id);
		}

/*		if ($e107_user['user_name'] == 'andymactree' || $e107_user['user_loginname'] == 'andymactree') {
			debug($user);
		}*/

		return $user;
	}

	function move_forum($forum, $e107_parent_id, $order, $level) {
		global $db, $wp_pre, $e107_pre;

		$forum_user_id = default_user_id();

		$db->from($wp_pre . 'posts')->insert(array(
			'post_title'        => scrub($forum['forum_name']),
			'post_content'      => scrub($forum['forum_description']),
			'post_date'         => date('Y-m-d H:i:s', $forum['forum_datestamp']),
			'post_date_gmt'     => date('Y-m-d H:i:s', $forum['forum_datestamp']),
			'post_modified'     => date('Y-m-d H:i:s', $forum['forum_datestamp']),
			'post_modified_gmt' => date('Y-m-d H:i:s', $forum['forum_datestamp']),
			'post_author'       => $forum_user_id,
			'post_name'         => makeSlugs($forum['forum_name']),
			'post_parent'       => $e107_parent_id,
			'post_type'         => 'forum',
			'menu_order'        => $order
		))->execute();

		$parent_id = $db->insert_id;
		moved('<small>Forum:</small> ' . $forum['forum_name'], $level);

		//  add the guid now that we have an id
		$db->from($wp_pre . 'posts')->where(array('id'=>$parent_id))->update(array(
			'guid' => 'http://' . $_SERVER['HTTP_HOST'] . '?post_type=forum&p=' . $parent_id
		))->execute();

		$subs = $db->from($e107_pre . 'forum')->where(array('forum_parent'=>$forum['forum_id']))->sortDesc('forum_order')->many();

		$loop = 1;

		foreach ($subs as $sub) {
			move_forum($sub, $parent_id, $loop, ($level+1));
			$loop++;
		}

		$meta = array(
			'_bbp_last_active_time'                => '',
			'_bbp_forum_subforum_count'            => '',
			'_bbp_reply_count'                     => '',
			'_bbp_total_reply_count'               => '',
			'_bbp_topic_count'                     => '',
			'_bbp_total_topic_count'               => '',
			'_bbp_topic_count_hidden'              => 0,
			'_bbp_last_topic_id'                   => '',
			'_bbp_last_reply_id'                   => '',
			'_bbp_last_active_id'                  => ''
		);

		foreach ($meta as $key => $val) {
			switch($key) {
				case '_bbp_last_active_time':
					list($last_time, $last_reply_id) = explode('.', $forum['forum_lastpost_info']);
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => $key,
						'meta_value' => date('Y-m-d H:i:s', (int) $last_time),
					))->execute();
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => '_bbp_last_topic_id',
						'meta_value' => $last_reply_id,
					))->execute();
					break;
				case '_bbp_forum_subforum_count':
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => $key,
						'meta_value' => count($subs),
					))->execute();
					break;
				case '_bbp_reply_count':
					$replies = $db->from($e107_pre . 'forum_t')->where(array('thread_forum_id'=>$forum['forum_id'],'thread_parent'=>0))->count();
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => $key,
						'meta_value' => $replies,
					))->execute();
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => '_bbp_total_reply_count',
						'meta_value' => $replies,
					))->execute();
					break;
				case '_bbp_topic_count':
					$posts = $db->from($e107_pre . 'forum_t')->where(array('thread_forum_id'=>$forum['forum_id'],'thread_parent !='=>0))->count();
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => $key,
						'meta_value' => $posts,
					))->execute();
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $parent_id,
						'meta_key'   => '_bbp_total_topic_count',
						'meta_value' => $posts,
					))->execute();
					break;
			}
		}

		//  move the threads
		move_posts($forum['forum_id'], $parent_id, 0, 0, 'topic', $level);

		return $parent_id;
	}

	/**
	* Move a thread
	*
	* @param int $forum_id The forum id for the threads being moved
	* @param int $parent What level of thread are we looking for
	* @param int $level How many spaces to use in messages to user
	*/
	function move_posts($e107_forum_id, $wp_forum_id, $e107_topic_id, $wp_topid_id, $type, $level) {
		global $db, $e107_pre, $wp_pre;

		$posts = $db->from($e107_pre . 'forum_t')->where(array(
			'thread_forum_id' => $e107_forum_id,
			'thread_parent'   => $e107_topic_id
		))->sortAsc('thread_datestamp')->many();

		$loop = 1;

		foreach($posts as $post) {
			$pieces = explode('.', $post['thread_user']);

			if (count($pieces) < 2) {
				debug($post['thread_user']);
			}

			$user = move_user($pieces[0], ($level+1));

			$db->from($wp_pre . 'posts')->insert(array(
				'post_title'        => scrub($post['thread_name']),
				'post_content'      => scrub($post['thread_thread']),
				'post_date'         => date('Y-m-d H:i:s', $post['thread_datestamp']),
				'post_date_gmt'     => date('Y-m-d H:i:s', $post['thread_datestamp']),
				'post_modified'     => date('Y-m-d H:i:s', $post['thread_datestamp']),
				'post_modified_gmt' => date('Y-m-d H:i:s', $post['thread_datestamp']),
				'post_author'       => $user['ID'],
				'post_name'         => makeSlugs(scrub($post['thread_name'])),
				'post_parent'       => (($wp_topid_id != 0) ? $wp_topid_id : $wp_forum_id),
				'post_type'         => $type
			))->execute();

			$new_id = $db->insert_id;
			moved('<small>' . ucfirst($type) . ' ' . $post['thread_id'] . ':</small> ' . $post['thread_name'], ($level+1));

			if ($type == 'topic') {
				$replies = $post['thread_total_replies'];//$db->from($e107_pre . 'forum_t')->where(array('thread_parent'=>$topic_id))->count();

				$voices = $db->sql('SELECT COUNT(DISTINCT thread_user) as my_count FROM ' . $e107_pre . 'forum_t WHERE thread_parent = ' . $post['thread_id'] . ' OR thread_id = ' . $post['thread_id'])->one();

//				if ($post['thread_id'] == 1) {debug($voices);}

				$meta = array(
					'_bbp_anonymous_email'    => '',
					'_bbp_anonymous_name'     => '',
					'_bbp_anonymous_website'  => '',
					'_bbp_author_ip'          => '',
					'_bbp_forum_id'           => $wp_forum_id,
					'_bbp_last_active_id'     => '',
					'_bbp_last_active_time'   => date('Y-m-d H:i:s',$post['thread_lastpost']),
					'_bbp_last_reply_id'      => $last_post,
					'_bbp_reply_count'        => $replies,
					'_bbp_reply_count_hidden' => 0,
					'_bbp_topic_id'           => $new_id,
					'_bbp_voice_count'        => $voices['my_count']
				);

				foreach ($meta as $key => $val) {
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $new_id,
						'meta_key'   => $key,
						'meta_value' => $val,
					))->execute();
				}

				$last_post = move_posts($e107_forum_id, $wp_forum_id, $post['thread_id'], $new_id, 'reply', ($level+1));
			} else {
				$meta = array(
					'_bbp_anonymous_email'   => '',
					'_bbp_anonymous_name'    => '',
					'_bbp_anonymous_website' => '',
					'_bbp_author_ip'         => '',
					'_bbp_forum_id'          => $wp_forum_id,
					'_bbp_topic_id'          => $wp_topid_id,
				);

				foreach ($meta as $key => $val) {
					$db->from($wp_pre . 'postmeta')->insert(array(
						'post_id'    => $new_id,
						'meta_key'   => $key,
						'meta_value' => $val,
					))->execute();
				}
			}

			$loop++;
		}

//			return $post;
	}

	/**
	* Sometimes we just need a user to associate to
	*
	*/
	function default_user_id() {
		global $db, $e107_pre, $default_user;

		$user = $db->from($e107_pre . 'user')->where(array('user_loginname'=>$default_user))->one();

		$user = move_user($user['user_id']);

		return $user['ID'];
	}

	function moved($str, $level = 0) {
		ob_end_flush();

		echo spacer($level) . $str . '<br />';

		ob_start();
	}

	function spacer($level) {
		$out = '';

		for($i=0;$i<$level;$i++) {
			$out .= '&nbsp;&nbsp;';
		}
		$out .= '-&nbsp;';

		return $out;
	}