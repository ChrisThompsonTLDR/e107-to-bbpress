<?php
	global $db;

	include 'sparrow.php';
	include 'slugs.php';

	$db = new Sparrow();

	include 'config.php';

	//  wipe everything out first
	$db->sql('DELETE FROM ' . $wp_pre . 'users WHERE ID != ' . $wp_admin_user . ';')->execute();
	$db->sql('DELETE FROM ' . $wp_pre . 'usermeta WHERE user_id != ' . $wp_admin_user . ';')->execute();
	$db->sql('DELETE FROM ' . $wp_pre . 'posts WHERE ID != 18;')->execute();
	$db->sql('DELETE FROM ' . $wp_pre . 'postmeta WHERE post_id != 18;')->execute();

	//  grab the parent forums
	$parents = $db->from($e107_pre . 'forum')->where(array('forum_parent'=>0))->sortAsc('forum_order')->many();

	$loop = 1;

	foreach ($parents as $parent) {
		//  move the user
		move_forum($parent, 0, $loop, 0);

		$loop++;
	}

	//  clean up all the nonsense sparrow creates
	$db->sql("
		UPDATE
			" . $wp_pre . "posts
		SET
			post_title = REPLACE(post_title,\"\\\\'\",\"'\"),
			post_content = REPLACE(post_content,\"\\\\'\",\"'\");
	")->execute();

	debug('done');