<?php
// Original plugin file temporarily preserved as requested.
// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!defined("PLUGINLIBRARY")) {
    define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

// Load extracted invitation-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/invitation_functions.php";
// Load extracted newthread-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/newthread_functions.php";
// Load extracted editpost-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/editpost_functions.php";
// Load extracted post-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/post_functions.php";
// Load extracted showthread-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/showthread_functions.php";
// Load extracted postbit-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/postbit_functions.php";
// Load extracted usercp-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/usercp_functions.php";
// Load reputation-related functions
require_once MYBB_ROOT . "inc/plugins/Thread_Collaboration/Functions/reputation_functions.php";

// Track edit history for collaborators
if (!function_exists('thread_collaboration_track_edit_history'))
{
function thread_collaboration_track_edit_history($pid, $tid, $editor_uid, $edit_type, $original_content, $new_content, $original_subject = null, $new_subject = null, $edit_reason = null, $restore_from_id = null)
{
    global $db;
    
    try {
        $history_data = array(
            'pid' => (int)$pid,
            'tid' => (int)$tid,
            'editor_uid' => (int)$editor_uid,
            'edit_type' => $db->escape_string($edit_type),
            'original_content' => $db->escape_string($original_content),
            'new_content' => $db->escape_string($new_content),
            'original_subject' => $original_subject ? $db->escape_string($original_subject) : null,
            'new_subject' => $new_subject ? $db->escape_string($new_subject) : null,
            'edit_reason' => $edit_reason ? $db->escape_string($edit_reason) : null,
            'restore_from_id' => $restore_from_id ? (int)$restore_from_id : null,
            'dateline' => TIME_NOW,
            'ip_address' => $db->escape_string(get_ip())
        );
        
        $db->insert_query("collab_edit_history", $history_data);
        return true;
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error tracking edit history: " . $e->getMessage());
        return false;
    }
}
}

// Track post edit start - store original content
if (!function_exists('thread_collaboration_track_post_edit_start'))
{
function thread_collaboration_track_post_edit_start()
{
    global $mybb, $db, $thread;
    
    // Only track if this is a post edit and user is logged in
    if (!$mybb->user['uid'] || $mybb->input['action'] != 'do_editpost') {
        return;
    }
    
    $pid = (int)$mybb->get_input('pid');
    if (!$pid) {
        return;
    }
    
    // Get the post
    $post_query = $db->simple_select("posts", "*", "pid='{$pid}'", array('limit' => 1));
    $post = $db->fetch_array($post_query);
    
    if (!$post) {
        return;
    }
    
    // Check if current user is thread owner
    $is_current_user_thread_owner = false;
    $thread_query = $db->simple_select("threads", "uid", "tid='{$post['tid']}'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    if ($thread_data && $thread_data['uid'] == $mybb->user['uid']) {
        $is_current_user_thread_owner = true;
    }
    
    // Check if current user is a collaborator
    $is_current_user_collaborator = false;
    $collaborator_query = $db->simple_select("thread_collaborators", "cid", "tid='{$post['tid']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
    if ($collaborator_query && $db->num_rows($collaborator_query) > 0) {
        $is_current_user_collaborator = true;
    }
    
    // Check if post owner is a collaborator
    $is_post_owner_collaborator = false;
    $post_owner_query = $db->simple_select("thread_collaborators", "cid", "tid='{$post['tid']}' AND uid='{$post['uid']}'", array('limit' => 1));
    if ($post_owner_query && $db->num_rows($post_owner_query) > 0) {
        $is_post_owner_collaborator = true;
    }
    
    // Check if there are any collaborators in this thread
    $has_collaborators = false;
    $collaborators_count_query = $db->simple_select("thread_collaborators", "COUNT(cid) as count", "tid='{$post['tid']}'");
    $collaborators_count = $db->fetch_array($collaborators_count_query);
    if ($collaborators_count['count'] > 0) {
        $has_collaborators = true;
    }
    
    // Track if:
    // 1. Both users are collaborators, OR
    // 2. Current user is thread owner and there are collaborators in the thread
    $should_track = false;
    if (($is_current_user_collaborator && $is_post_owner_collaborator) || 
        ($is_current_user_thread_owner && $has_collaborators)) {
        $should_track = true;
    }
    
    if ($should_track) {
        // Store original content in session for later comparison
        $_SESSION['thread_collaboration_original_content'] = $post['message'];
        $_SESSION['thread_collaboration_original_subject'] = $post['subject'];
        $_SESSION['thread_collaboration_edit_pid'] = $pid;
        $_SESSION['thread_collaboration_edit_tid'] = $post['tid'];
    }
}
}

// Track post edit end - log the changes
if (!function_exists('thread_collaboration_track_post_edit_end'))
{
function thread_collaboration_track_post_edit_end()
{
    global $mybb, $db;
    
    // Only track if this is a post edit and user is logged in
    if (!$mybb->user['uid'] || $mybb->input['action'] != 'do_editpost') {
        return;
    }
    
    // Check if we have stored original content
    if (!isset($_SESSION['thread_collaboration_original_content']) || 
        !isset($_SESSION['thread_collaboration_edit_pid'])) {
        return;
    }
    
    $pid = $_SESSION['thread_collaboration_edit_pid'];
    $tid = $_SESSION['thread_collaboration_edit_tid'];
    $original_content = $_SESSION['thread_collaboration_original_content'];
    $original_subject = $_SESSION['thread_collaboration_original_subject'];
    
    // Get the updated post
    $post_query = $db->simple_select("posts", "*", "pid='{$pid}'", array('limit' => 1));
    $post = $db->fetch_array($post_query);
    
    if (!$post) {
        return;
    }
    
    $new_content = $post['message'];
    $new_subject = $post['subject'];
    
    // Only track if content actually changed
    if ($original_content !== $new_content || $original_subject !== $new_subject) {
        $edit_type = 'edit';
        $edit_reason = 'Post edited by collaborator';
        
        // Check if this is a restore operation
        if (isset($mybb->input['restore_from_history'])) {
            $edit_type = 'restore';
            $edit_reason = 'Restored from edit history';
        }
        
        thread_collaboration_track_edit_history(
            $pid,
            $tid,
            $mybb->user['uid'],
            $edit_type,
            $original_content,
            $new_content,
            $original_subject,
            $new_subject,
            $edit_reason
        );
    }
    
    // Clean up session data
    unset($_SESSION['thread_collaboration_original_content']);
    unset($_SESSION['thread_collaboration_original_subject']);
    unset($_SESSION['thread_collaboration_edit_pid']);
    unset($_SESSION['thread_collaboration_edit_tid']);
}
}

function thread_collaboration_info()
{
    global $PL, $mybb;
    $PL or require_once PLUGINLIBRARY;

    thread_collaboration_plugin_edit(); // Add this line: Call the handler to process apply/revert if requested

    $description = 'Allows thread creators to invite users to collaborate on threads with assigned roles and custom icons. Includes forum-specific permissions and admin controls.'; // Your original description

    if (thread_collaboration_is_installed()) {
        if (thread_collaboration_apply_core_edits() !== true) {
            $apply = $PL->url_append('index.php',
                [
                    'module' => 'config-plugins',
                    'thread_collaboration' => 'apply',
                    'my_post_key' => $mybb->post_code,
                ]
            );
            $description .= "<br><br>Core edits missing. <a href='{$apply}'>Apply core edits.</a>";
        } else {
            $revert = $PL->url_append('index.php',
                [
                    'module' => 'config-plugins',
                    'thread_collaboration' => 'revert',
                    'my_post_key' => $mybb->post_code,
                ]
            );
            $description .= "<br><br>Core edits in place. <a href='{$revert}'>Revert core edits.</a>";
        }
    }

    return array(
        "name"            => "Thread Collaboration",
        "description"    => $description, // Updated with status/links
        "website"        => "https://github.com/yourusername/mybb-thread-collaboration",
        "author"        => "PRADIP",
        "authorsite"    => "https://yourwebsite.com",
        "version"        => "1.3",
        "guid"            => "",
        "compatibility" => "18*"
    );
}

function thread_collaboration_plugin_edit()
{
    global $mybb;

    if ($mybb->input['my_post_key'] == $mybb->post_code) {
        if ($mybb->input['thread_collaboration'] == 'apply') {
            $result = thread_collaboration_apply_core_edits(true);
            if ($result === true) {
                flash_message('Successfully applied core edits.', 'success');
                admin_redirect('index.php?module=config-plugins');
            } else {
                // Show detailed errors
                $error_details = implode('<br>', $result);
                flash_message('There was an error applying core edits: <br>' . $error_details, 'error');
                admin_redirect('index.php?module=config-plugins');
            }
        }

        if ($mybb->input['thread_collaboration'] == 'revert') {
            $result = thread_collaboration_revert_core_edits(true);
            
            if ($result === true) {
                flash_message('Successfully reverted core edits.', 'success');
                admin_redirect('index.php?module=config-plugins');
            } else {
                // Show detailed errors for revert too
                $error_details = implode('<br>', $result);
                flash_message('There was an error reverting core edits: <br>' . $error_details, 'error');
                admin_redirect('index.php?module=config-plugins');
            }
        }
    }
}

function thread_collaboration_can_delete($post)
{
    global $mybb, $db;
    $tid = (int)$post['tid'];
    $uid = (int)$mybb->user['uid'];
    // Example: Check if user is a collaborator with delete permission (adjust table/column names)
    $query = $db->simple_select('thread_collaborators', '*', "tid = '$tid' AND uid = '$uid' AND role = 'editor'"); // Example role check
    return $db->num_rows($query) > 0;
}

function thread_collaboration_handle_invite()
{
    global $mybb, $db;
    $tid = $mybb->get_input('tid', MyBB::INPUT_INT);
    $uid = $mybb->get_input('uid', MyBB::INPUT_INT);
    // Example: Handle invite (insert into invitations table)
    $insert = [
        'tid' => $tid,
        'inviter_uid' => $mybb->user['uid'],
        'invitee_uid' => $uid,
        'role' => 'collaborator',
        'status' => 'pending',
        'invite_date' => TIME_NOW
    ];
    $db->insert_query('collaboration_invitations', $insert);
    echo json_encode(['success' => true]);
    exit;
}

function thread_collaboration_is_collaborator($tid, $uid)
{
    global $db;
    $tid = (int)$tid;
    $uid = (int)$uid;
    $query = $db->simple_select('thread_collaborators', '*', "tid = '$tid' AND uid = '$uid'");
    return $db->num_rows($query) > 0;
}

function thread_collaboration_allow_html($post)
{
    global $mybb;
    $tid = (int)$post['tid'];
    $uid = (int)$mybb->user['uid'];
    return thread_collaboration_is_collaborator($tid, $uid); // Allow HTML for collaborators
}

function thread_collaboration_handle_post_edit_approval($id, $action)
{
    global $mybb, $db, $lang;
    $lang->load('thread_collaboration');

    // Fetch pending edit owned by current user
    $row = $db->fetch_array($db->simple_select('collab_post_edits', '*', "id='".(int)$id."' AND owner_uid='".(int)$mybb->user['uid']."' AND status='pending'", array('limit' => 1)));
    if (!$row) {
        error($lang->collab_edit_invalid_request ?: 'Invalid or already processed edit request.');
        return;
    }

    if ($action === 'approve')
    {
        $draft = @json_decode($row['draft'], true);
        if (!is_array($draft)) { 
            $draft = array('edited_subject' => '', 'edited_message' => ''); 
        }

        // Update post content - try both possible field names for backward compatibility
        $edited_message = '';
        if (isset($draft['edited_message'])) {
            $edited_message = $draft['edited_message'];
        } elseif (isset($draft['message'])) {
            $edited_message = $draft['message'];
        }
        
        if (empty($edited_message)) {
            error("Cannot approve edit: No edited content found in draft.");
            return;
        }
        
        $update = array('message' => $db->escape_string($edited_message));
        
        // Update subject only if it's first post
        $thread = $db->fetch_array($db->simple_select('threads', 'tid,firstpost', "tid='".(int)$row['tid']."'", array('limit' => 1)));
        if ($thread && (int)$thread['firstpost'] === (int)$row['pid'] && isset($draft['edited_subject']) && !empty($draft['edited_subject'])) {
            $db->update_query('threads', array('subject' => $db->escape_string((string)$draft['edited_subject'])), "tid='".(int)$row['tid']."'");
            $db->update_query('posts', array('subject' => $db->escape_string((string)$draft['edited_subject'])), "pid='".(int)$row['pid']."'");
        }
        
        $db->update_query('posts', $update, "pid='".(int)$row['pid']."'");

        // Mark approved
        $db->update_query('collab_post_edits', array('status' => 'approved'), "id='".(int)$row['id']."'");

        // PM editor about approval
        if (!function_exists('thread_collaboration_send_pm'))
        {
            require_once MYBB_ROOT.'inc/datahandlers/pm.php';
            function thread_collaboration_send_pm($to_uid, $subject, $message, $from_uid = 0)
            {
                $pmhandler = new PMDataHandler();
                $pm = array('subject' => $subject, 'message' => $message, 'fromid' => (int)$from_uid, 'toid' => array((int)$to_uid));
                $pmhandler->set_data($pm);
                if($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
            }
        }
        $editor_username = $db->fetch_field($db->simple_select('users', 'username', "uid='".(int)$row['editor_uid']."'", array('limit' => 1)), 'username');
        $owner_username = $mybb->user['username'];
        $subject_txt = $lang->collab_edit_pm_approved_subject ?: 'Your edit was approved';
        $message_txt = ($lang->collab_edit_pm_approved_message ? $lang->sprintf($lang->collab_edit_pm_approved_message, htmlspecialchars_uni($owner_username)) : ('Your submitted edit was approved by '.htmlspecialchars_uni($owner_username).' and has been applied.'));
        thread_collaboration_send_pm((int)$row['editor_uid'], $subject_txt, $message_txt, (int)$mybb->user['uid']);

        redirect('usercp.php?action=collaboration_invitations', $lang->collab_edit_approved_success ?: 'The edit has been approved and applied.');
    }
    else if ($action === 'reject')
    {
        $db->update_query('collab_post_edits', array('status' => 'rejected'), "id='".(int)$row['id']."'");

        if (!function_exists('thread_collaboration_send_pm'))
        {
            require_once MYBB_ROOT.'inc/datahandlers/pm.php';
            function thread_collaboration_send_pm($to_uid, $subject, $message, $from_uid = 0)
            {
                $pmhandler = new PMDataHandler();
                $pm = array('subject' => $subject, 'message' => $message, 'fromid' => (int)$from_uid, 'toid' => array((int)$to_uid));
                $pmhandler->set_data($pm);
                if($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
            }
        }
        $owner_username = $mybb->user['username'];
        $subject_txt = $lang->collab_edit_pm_rejected_subject ?: 'Your edit was rejected';
        $message_txt = ($lang->collab_edit_pm_rejected_message ? $lang->sprintf($lang->collab_edit_pm_rejected_message, htmlspecialchars_uni($owner_username)) : ('Your submitted edit was rejected by '.htmlspecialchars_uni($owner_username).' and was not applied.'));
        thread_collaboration_send_pm((int)$row['editor_uid'], $subject_txt, $message_txt, (int)$mybb->user['uid']);

        redirect('usercp.php?action=collaboration_invitations', $lang->collab_edit_rejected_success ?: 'The edit has been rejected.');
    }
}

function thread_collaboration_apply_core_edits($apply = false)
{
    global $PL;
    $PL or require_once PLUGINLIBRARY;

    $errors = [];
    $success_count = 0;

    $edits_editpost = [
        [
            'search' => [
                '		if($forumpermissions[\'caneditposts\'] == 0)',
                '		{',
                '			error_no_permission();',
                '		}',
                '		if($mybb->user[\'uid\'] != $post[\'uid\'])',
            ],
            'replace' => '		if($forumpermissions[\'caneditposts\'] == 0)
		{
			error_no_permission();
		}
		// Check if user is the post author or a thread collaborator
		$can_edit = false;
		if($mybb->user[\'uid\'] == $post[\'uid\'])
		{
			$can_edit = true;
		}
		// Check if user is a thread collaborator (if enabled)
		elseif($mybb->settings[\'thread_collaboration_enable_collaborator_editing\'] == \'1\' && function_exists(\'thread_collaboration_is_user_collaborator\') && thread_collaboration_is_user_collaborator($mybb->user[\'uid\'], $thread[\'tid\']))
		{
			// Check if collaborators can edit any post or only collaborator posts
			if($mybb->settings[\'thread_collaboration_allow_edit_any_post\'] == \'1\' || thread_collaboration_is_user_collaborator($post[\'uid\'], $thread[\'tid\']) || $post[\'uid\'] == $thread[\'uid\'])
			{
				$can_edit = true;
			}
		}
		
		if(!$can_edit)',
        ],
    ];
    

    $result = $PL->edit_core('thread_collaboration', 'editpost.php', $edits_editpost, $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Edits for search.php (add multi-author post support)
    $edits_search = [
        [
            'search' => [
                'elseif($mybb->input[\'action\'] == "finduser")',
                '{',
                '	$where_sql = "uid=\'".$mybb->get_input(\'uid\', MyBB::INPUT_INT)."\'";',
            ],
            'replace' => 'elseif($mybb->input[\'action\'] == "finduser")
{
	$uid = $mybb->get_input(\'uid\', MyBB::INPUT_INT);
	
	// Get posts where user is author OR contributor
	$contributor_posts_query = $db->query("
		SELECT DISTINCT ccp.pid
		FROM " . TABLE_PREFIX . "collab_contributor_posts ccp
		WHERE ccp.uid = \'{$uid}\'
	");
	
	$contributor_pids = array();
	while ($row = $db->fetch_array($contributor_posts_query)) {
		$contributor_pids[] = $row[\'pid\'];
	}
	
	// Build WHERE clause to include both authored posts and contributor posts
	if (!empty($contributor_pids)) {
		$contributor_pids_str = implode(\',\', $contributor_pids);
		$where_sql = "(uid=\'{$uid}\' OR pid IN ({$contributor_pids_str}))";
	} else {
		$where_sql = "uid=\'{$uid}\'";
	}',
        ],
    ];

    $result = $PL->edit_core('thread_collaboration', 'search.php', $edits_search, $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Edits for xmlhttp.php (add collaborator permission checks for quick edit)
    $edits_xmlhttp = [
        [
            'search' => '		else if($forum[\'open\'] == 0 || $forumpermissions[\'caneditposts\'] == 0 || $mybb->user[\'uid\'] != $post[\'uid\'] || $mybb->user[\'uid\'] == 0 || $mybb->user[\'suspendposting\'] == 1)',
            'replace' => '		// Check if user is the post author or a thread collaborator
		$can_edit = false;
		if($mybb->user[\'uid\'] == $post[\'uid\'])
		{
			$can_edit = true;
		}
		// Check if user is a thread collaborator (if enabled)
		elseif($mybb->settings[\'thread_collaboration_enable_collaborator_editing\'] == \'1\')
		{
			// Include the thread collaboration plugin functions
			if(function_exists(\'thread_collaboration_is_user_collaborator\'))
			{
				if(thread_collaboration_is_user_collaborator($mybb->user[\'uid\'], $thread[\'tid\']))
				{
					// Check if collaborators can edit any post or only collaborator posts
					if($mybb->settings[\'thread_collaboration_allow_edit_any_post\'] == \'1\')
					{
						// Full access mode: collaborators can edit any post in the thread
						$can_edit = true;
					}
					else
					{
						// Restricted mode: collaborators can only edit posts by other collaborators or thread owner
						if(thread_collaboration_is_user_collaborator($post[\'uid\'], $thread[\'tid\']) || $post[\'uid\'] == $thread[\'uid\'])
						{
							$can_edit = true;
						}
					}
				}
			}
		}
		
		else if($forum[\'open\'] == 0 || $forumpermissions[\'caneditposts\'] == 0 || !$can_edit || $mybb->user[\'uid\'] == 0 || $mybb->user[\'suspendposting\'] == 1)',
        ],
    ];
    
    $result = $PL->edit_core('thread_collaboration', 'xmlhttp.php', $edits_xmlhttp, $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Edits for inc/functions_post.php (add collaborator permission checks for edit buttons)
    $edits_functions_post = [
        [
            'search' => '		if((is_moderator($fid, "caneditposts") || ($forumpermissions[\'caneditposts\'] == 1 && $mybb->user[\'uid\'] == $post[\'uid\'] && $thread[\'closed\'] != 1 && ($mybb->usergroup[\'edittimelimit\'] == 0 || $mybb->usergroup[\'edittimelimit\'] != 0 && $post[\'dateline\'] > ($time-($mybb->usergroup[\'edittimelimit\']*60))))) && $mybb->user[\'uid\'] != 0)',
            'replace' => '		// Check if user can edit this post (moderator, author, or collaborator)
		$can_edit_post = false;
		if(is_moderator($fid, "caneditposts"))
		{
			$can_edit_post = true;
		}
		elseif($forumpermissions[\'caneditposts\'] == 1 && $mybb->user[\'uid\'] == $post[\'uid\'] && $thread[\'closed\'] != 1 && ($mybb->usergroup[\'edittimelimit\'] == 0 || $mybb->usergroup[\'edittimelimit\'] != 0 && $post[\'dateline\'] > ($time-($mybb->usergroup[\'edittimelimit\']*60))))
		{
			$can_edit_post = true;
		}
		// Check if user is a thread collaborator (if enabled)
		elseif($mybb->settings[\'thread_collaboration_enable_collaborator_editing\'] == \'1\' && $forumpermissions[\'caneditposts\'] == 1 && $thread[\'closed\'] != 1 && ($mybb->usergroup[\'edittimelimit\'] == 0 || $mybb->usergroup[\'edittimelimit\'] != 0 && $post[\'dateline\'] > ($time-($mybb->usergroup[\'edittimelimit\']*60))))
		{
			// Include the thread collaboration plugin functions
			if(function_exists(\'thread_collaboration_is_user_collaborator\'))
			{
				if(thread_collaboration_is_user_collaborator($mybb->user[\'uid\'], $thread[\'tid\']))
				{
					// Check if collaborators can edit any post or only collaborator posts
					if($mybb->settings[\'thread_collaboration_allow_edit_any_post\'] == \'1\')
					{
						// Full access mode: collaborators can edit any post in the thread
						$can_edit_post = true;
					}
					else
					{
						// Restricted mode: collaborators can only edit posts by other collaborators or thread owner
						if(thread_collaboration_is_user_collaborator($post[\'uid\'], $thread[\'tid\']) || $post[\'uid\'] == $thread[\'uid\'])
						{
							$can_edit_post = true;
						}
					}
				}
			}
		}
		
		if($can_edit_post && $mybb->user[\'uid\'] != 0)',
        ],
    ];
    
    $result = $PL->edit_core('thread_collaboration', 'inc/functions_post.php', $edits_functions_post, $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }


    if (count($errors) >= 1) {
        return $errors;
    } else {
        return true;
    }
}

function thread_collaboration_revert_core_edits($apply = false)
{
    global $PL;
    $PL or require_once PLUGINLIBRARY;

    $errors = [];
    $success_count = 0;

    // Use PluginLibrary's built-in revert functionality
    $result = $PL->edit_core('thread_collaboration', 'editpost.php', [], $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Use PluginLibrary's built-in revert functionality
    $result = $PL->edit_core('thread_collaboration', 'xmlhttp.php', [], $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Use PluginLibrary's built-in revert functionality
    $result = $PL->edit_core('thread_collaboration', 'inc/functions_post.php', [], $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }

    // Use PluginLibrary's built-in revert functionality
    $result = $PL->edit_core('thread_collaboration', 'search.php', [], $apply);
    if ($result !== true) {
        $errors[] = $result;
    } else {
        $success_count++;
    }


    if (count($errors) >= 1) {
        return $errors;
    } else {
        return true;
    }
}

function thread_collaboration_is_installed()
{
    global $db;
    
    // Check if the thread_collaborators table exists
    try {
        $result = $db->query("SHOW TABLES LIKE '".TABLE_PREFIX."thread_collaborators'");
        if ($result && $db->num_rows($result) > 0)
        {
            return true;
        }
    } catch (Exception $e) {
        return false;
    }
    
    return false;
}

function thread_collaboration_install()
{
    require_once MYBB_ROOT.'inc/plugins/Thread_Collaboration/install.php';
    if(function_exists('tc_install_run')) {
        tc_install_run();
    }
}
function thread_collaboration_add_settings()
{
    global $db;
    
    // Prefer PluginLibrary + JSON-defined settings
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $settingsFile = MYBB_ROOT.'inc/plugins/Thread_Collaboration/settings.json';
        if(file_exists($settingsFile))
        {
            $json = @file_get_contents($settingsFile);
            if($json !== false)
            {
                $data = @json_decode($json, true);
                if(is_array($data) && isset($data['group']) && isset($data['settings']) && is_array($data['settings']))
                {
                    $group = $data['group'];
                    $name = !empty($group['name']) ? $group['name'] : 'thread_collaboration';
                    $title = !empty($group['title']) ? $group['title'] : 'Thread Collaboration Settings';
                    $description = !empty($group['description']) ? $group['description'] : '';
                    $list = array();
                    foreach($data['settings'] as $key => $s)
                    {
                        $list[$key] = array(
                            'title' => isset($s['title']) ? $s['title'] : '',
                            'description' => isset($s['description']) ? $s['description'] : '',
                            'optionscode' => isset($s['optionscode']) ? $s['optionscode'] : 'yesno',
                            'value' => isset($s['value']) ? (string)$s['value'] : '0',
                        );
                    }
                    $PL->settings($name, $title, $description, $list);
                    return;
                }
            }
        }
    }
}

function thread_collaboration_uninstall()
{
    require_once MYBB_ROOT.'inc/plugins/Thread_Collaboration/uninstall.php';
    if(function_exists('tc_uninstall_run')) {
        // Deactivate first to revert template changes
    thread_collaboration_deactivate();
        tc_uninstall_run();
    }
}
function thread_collaboration_add_template_modifications()
{
    global $db, $PL;

    // Prefer PluginLibrary + external template files
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();

        $basePath = MYBB_ROOT.'inc/plugins/Thread_Collaboration/Templates/';

        $tc_files = array(
            'input_field' => 'thread_collaboration_input_field.html',
            'input_container' => 'thread_collaboration_input_container.html',
            'show_collaborators' => 'thread_collaboration_show_collaborators.html',
            'show_collaborators_item' => 'thread_collaboration_show_collaborators_item.html',
            'show_pending_invitations' => 'thread_collaboration_show_pending_invitations.html',
            'show_pending_invitation_item' => 'thread_collaboration_show_pending_invitation_item.html',
            'postbit_role' => 'thread_collaboration_postbit_role.html',
            'invitations_page' => 'thread_collaboration_invitations_page.html',
            'invitation_item' => 'thread_collaboration_invitation_item.html',
            'no_invitations' => 'thread_collaboration_no_invitations.html',
            'settings_section' => 'thread_collaboration_settings_section.html',
            'pending_edits' => 'thread_collaboration_pending_edits.html',
            'pending_edit_item' => 'thread_collaboration_pending_edit_item.html',
            'no_pending_edits' => 'thread_collaboration_no_pending_edits.html',
            'request_form' => 'thread_collaboration_request_form.html',
            'request_button' => 'thread_collaboration_request_button.html',
            'request_modal' => 'thread_collaboration_request_modal.html',
            'pending_request' => 'threadcollaboration_pending_request.html',
            'no_collaborators' => 'thread_collaboration_show_no_collaborators.html',
            'requests_page' => 'thread_collaboration_requests_page.html',
            'request_item' => 'thread_collaboration_request_item.html',
            'no_requests' => 'thread_collaboration_no_requests.html',
            'request_stats_table' => 'thread_collaboration_request_stats_table.html',
            'comprehensive_stats' => 'thread_collaboration_comprehensive_stats.html',
            'generic_page' => 'thread_collaboration_generic_page.html',
            'profile_info' => 'thread_collaboration_profile_info.html',
            'ucp_collaborations_list' => 'thread_collaboration_ucp_collaborations_list.html',
            'ucp_collaboration_roles' => 'thread_collaboration_ucp_collaboration_roles.html',
            'ucp_collaboration_forums' => 'thread_collaboration_ucp_collaboration_forums.html',
            'ucp_unique_owners' => 'thread_collaboration_ucp_unique_owners.html',
            'ucp_unique_inviters' => 'thread_collaboration_ucp_unique_inviters.html',
            'ucp_threads_with_collaborators' => 'thread_collaboration_ucp_threads_with_collaborators.html',
            'ucp_invitations_received' => 'thread_collaboration_ucp_invitations_received.html',
            'ucp_total_requests_sent' => 'thread_collaboration_ucp_total_requests_sent.html',
            'edit_history' => 'collaboration_edit_history.html',
            'edit_history_list' => 'collaboration_edit_history_list.html',
            'edit_history_item' => 'collaboration_edit_history_item.html',
            'edit_history_empty' => 'collaboration_edit_history_empty.html',
            'collaboration_chat' => 'collaboration_chat.html',
            'contribution_display' => 'contribution_display.html',
            'contribution_item' => 'contribution_item.html',
            
        );

        $tc_list = array();
        foreach($tc_files as $key => $file)
        {
            $path = $basePath.$file;
            if(file_exists($path))
            {
                $tc_list[$key] = file_get_contents($path);
            }
        }

        if($tc_list)
        {
            $PL->templates('threadcollaboration', 'Thread Collaboration', $tc_list);
        }

        // Merge UCP page templates into Thread Collaboration group with new names
        $ucp_files = array(
            'usercp_invitations' => 'thread_collaboration_usercp_invitations.html',
            'usercp_tabbed' => 'thread_collaboration_usercp_tabbed.html',
            'edit_modal' => 'thread_collaboration_edit_modal.html'
        );
        foreach($ucp_files as $key => $file)
        {
            $path = $basePath.$file;
            if(file_exists($path))
            {
                $tc_list[$key] = file_get_contents($path);
            }
        }

        if($tc_list)
        {
            $PL->templates('threadcollaboration', 'Thread Collaboration', $tc_list);
        }

        // Remove any customized copies of these templates so master is used (fixes previously broken content)
        try {
            $db->delete_query('templates', "sid<>-2 AND (title='threadcollaboration' OR title LIKE 'threadcollaboration=_%' ESCAPE '=')");
        } catch (Exception $e) { /* ignore */ }

        // Done via PluginLibrary; skip legacy inline insertion
        return;
    }

}

function thread_collaboration_revert_template_modifications()
{
    if(file_exists(PLUGINLIBRARY))
    {
        require_once PLUGINLIBRARY;
        $PL = new PluginLibrary();
        $PL->templates_delete('threadcollaboration', true);
    }
}

function thread_collaboration_activate()
{
    require_once MYBB_ROOT.'inc/plugins/Thread_Collaboration/install.php';
    if(function_exists('tc_activate_run')) {
        tc_activate_run();
    }
}


function thread_collaboration_deactivate()
{
    require_once MYBB_ROOT.'inc/plugins/Thread_Collaboration/uninstall.php';
    if(function_exists('tc_deactivate_run')) {
        tc_deactivate_run();
    }
}
// (deprecated placeholder removed; quick edit approval logic implemented below)
function thread_collaboration_xmlhttp_edit_post_end()
{
	global $mybb, $db, $thread, $post, $lang;
	// Preconditions similar to full edit handler
	if (!$mybb->user['uid']) { return; }
	if (empty($thread['tid']) || empty($post['pid'])) { return; }
	if ((int)$mybb->settings['thread_collaboration_require_approval_for_protected'] !== 1) { return; }
	if (!thread_collaboration_is_forum_allowed($thread['fid'])) { return; }
	
	// Only act on update_post quick edit flow
	if ($mybb->get_input('do') !== 'update_post') { return; }
	
	// Determine protected owner and collaborator relationship
	$owner_uid = (int)$post['uid'];
	$owner = $db->fetch_array($db->simple_select('users', 'uid,usergroup,additionalgroups', "uid='{$owner_uid}'", array('limit' => 1)));
	$protected = array();
	if (!empty($mybb->settings['thread_collaboration_moderator_usergroups']))
	{
		foreach (explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']) as $gid)
		{
			$gid = (int)trim($gid); if ($gid > 0) { $protected[] = $gid; }
		}
	}
	$is_owner_protected = false;
	if (!empty($protected) && $owner)
	{
		$owner_groups = array((int)$owner['usergroup']);
		if (!empty($owner['additionalgroups']))
		{
			foreach (explode(',', $owner['additionalgroups']) as $ag) { $ag = (int)trim($ag); if ($ag > 0) { $owner_groups[] = $ag; } }
		}
		foreach ($owner_groups as $g) { if (in_array($g, $protected, true)) { $is_owner_protected = true; break; } }
	}
	$is_editor_protected = false;
	if (!empty($protected))
	{
		$editor_groups = array((int)$mybb->user['usergroup']);
		if (!empty($mybb->user['additionalgroups']))
		{
			foreach (explode(',', $mybb->user['additionalgroups']) as $eg) { $eg = (int)trim($eg); if ($eg > 0) { $editor_groups[] = $eg; } }
		}
		foreach ($editor_groups as $g) { if (in_array($g, $protected, true)) { $is_editor_protected = true; break; } }
	}
	
	$owner_is_collab = false;
	if(function_exists('thread_collaboration_is_user_collaborator'))
	{
		$owner_is_collab = thread_collaboration_is_user_collaborator($owner_uid, $thread['tid']);
	}
	if((int)$owner_uid === (int)$thread['uid']) { $owner_is_collab = true; }
	$editor_is_collab = function_exists('thread_collaboration_is_user_collaborator') ? thread_collaboration_is_user_collaborator($mybb->user['uid'], $thread['tid']) : false;
	
		// Capture message from quick edit
		$message_raw = $mybb->get_input('value');
		$subject_raw = '';
		if ((int)$post['pid'] === (int)$thread['firstpost'] && isset($mybb->input['subject'])) { $subject_raw = $mybb->get_input('subject'); }
		
	// Check if we should track this edit
	$should_track = false;
	
	// Track if both users are collaborators
	if ($owner_is_collab && $editor_is_collab) {
		$should_track = true;
	}
	
	// Track if current user is thread owner and there are collaborators
	if ((int)$mybb->user['uid'] === (int)$thread['uid']) {
		$collaborators_count_query = $db->simple_select("thread_collaborators", "COUNT(*) as count", "tid='{$thread['tid']}'");
		$collaborators_count = $db->fetch_array($collaborators_count_query);
		if ($collaborators_count['count'] > 0) {
			$should_track = true;
		}
	}
	
	// Track edit history if needed
	if ($should_track) {
		$original_content = $post['message'];
		$new_content = $message_raw;
		$original_subject = $post['subject'];
		$new_subject = $subject_raw;
		
		// Only track if content actually changed
		if ($original_content !== $new_content || $original_subject !== $new_subject) {
			thread_collaboration_track_edit_history(
				$post['pid'],
				$thread['tid'],
				$mybb->user['uid'],
				'edit',
				$original_content,
				$new_content,
				$original_subject,
				$new_subject,
				'Quick edit',
				null
			);
		}
	}
	
	if ($is_owner_protected && !$is_editor_protected && $owner_is_collab && $editor_is_collab && (int)$mybb->user['uid'] !== $owner_uid)
	{
		$draft_payload = json_encode(array('edited_subject' => (string)$subject_raw, 'edited_message' => (string)$message_raw, 'original_subject' => $thread['subject'], 'original_message' => $post['message']));
		
		$db->insert_query('collab_post_edits', array(
			'pid' => (int)$post['pid'],
			'tid' => (int)$thread['tid'],
			'editor_uid' => (int)$mybb->user['uid'],
			'owner_uid' => $owner_uid,
			'draft' => $db->escape_string($draft_payload),
			'status' => 'pending',
			'dateline' => TIME_NOW,
		));
		
		if (!function_exists('thread_collaboration_send_pm'))
		{
			function thread_collaboration_send_pm($to_uid, $subject, $message, $from_uid = 0)
			{
				require_once MYBB_ROOT.'inc/datahandlers/pm.php';
				$pmhandler = new PMDataHandler();
				$pm = array('subject' => $subject, 'message' => $message, 'fromid' => (int)$from_uid, 'toid' => array((int)$to_uid));
				$pmhandler->set_data($pm);
				if($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
			}
		}
		$editor_username = $mybb->user['username'];
		$subject_txt = !empty($lang->collab_edit_pm_subject) ? $lang->sprintf($lang->collab_edit_pm_subject, htmlspecialchars_uni($editor_username), htmlspecialchars_uni($thread['subject'])) : $editor_username.' edited your post in thread "'.htmlspecialchars_uni($thread['subject']).'"';
		$message_txt = !empty($lang->collab_edit_pm_message) ? $lang->sprintf($lang->collab_edit_pm_message, htmlspecialchars_uni($editor_username)) : 'A collaborator ('.htmlspecialchars_uni($editor_username).') edited your post. Review and approve/reject it in User CP → Collaboration Settings.';
		thread_collaboration_send_pm($owner_uid, $subject_txt, $message_txt, (int)$mybb->user['uid']);
		
		// Build a success-like JSON so the post stays visible.
		// Render the current stored message (unchanged) using the same parser options as core quick edit.
		require_once MYBB_ROOT."inc/class_parser.php";
		$parser = new postParser;
		$parser_options = array(
			"allow_html" => (int)$GLOBALS['forum']['allowhtml'],
			"allow_mycode" => (int)$GLOBALS['forum']['allowmycode'],
			"allow_smilies" => (int)$GLOBALS['forum']['allowsmilies'],
			"allow_imgcode" => (int)$GLOBALS['forum']['allowimgcode'],
			"allow_videocode" => (int)$GLOBALS['forum']['allowvideocode'],
			"me_username" => $post['username'],
			"filter_badwords" => 1
		);
		if($post['smilieoff'] == 1) { $parser_options['allow_smilies'] = 0; }
		if($mybb->user['uid'] != 0 && $mybb->user['showimages'] != 1 || $mybb->settings['guestimages'] != 1 && $mybb->user['uid'] == 0) { $parser_options['allow_imgcode'] = 0; }
		if($mybb->user['uid'] != 0 && $mybb->user['showvideos'] != 1 || $mybb->settings['guestvideos'] != 1 && $mybb->user['uid'] == 0) { $parser_options['allow_videocode'] = 0; }
		$rendered_message = $parser->parse_message($post['message'], $parser_options);
		
		$pending_note = !empty($lang->collab_edit_saved_as_draft) ? $lang->collab_edit_saved_as_draft : 'Your edit was saved as a draft and sent to the post owner for approval.';
		redirect(get_post_link($post['pid']), $pending_note);
	}
}
// Helper function to check if collaboration is allowed in current forum
function thread_collaboration_is_forum_allowed($fid = 0)
{
    global $mybb, $db, $thread;
    
    // If no forum ID provided, try to get current forum
    if (!$fid) {
        if (isset($mybb->input['fid'])) {
            $fid = (int)$mybb->input['fid'];
        } elseif (isset($thread['fid'])) {
            $fid = (int)$thread['fid'];
        } else {
            return false;
        }
    }

    // Check if collaboration is allowed in this forum
    $allowed_forums = $mybb->settings['thread_collaboration_allowed_forums'];
    
    if ($allowed_forums == -1) {
        // All forums allowed
        return true;
    } elseif ($allowed_forums == 0) {
        // No forums allowed
        return false;
    } else {
        // Specific forums allowed
        $allowed_forum_array = explode(',', $allowed_forums);
        return in_array($fid, $allowed_forum_array);
    }
}
// Check if collaboration requests are allowed for a specific thread
function thread_collaboration_are_requests_allowed($tid)
{
    global $mybb, $db;
    
    // Get thread owner
    $thread_query = $db->simple_select('threads', 'uid', "tid = '{$tid}'");
    if (!$thread_query || $db->num_rows($thread_query) == 0) {
        return false;
    }
    
    $thread = $db->fetch_array($thread_query);
    $thread_owner_uid = $thread['uid'];
    
    // Get thread owner's settings
    $settings_query = $db->simple_select('collaboration_user_settings', '*', "uid = '{$thread_owner_uid}'");
    if (!$settings_query || $db->num_rows($settings_query) == 0) {
        return false; // No settings found, requests disabled by default
    }
    
    $settings = $db->fetch_array($settings_query);
    
    // Check if requests are enabled
    if ($settings['requests_enabled'] != 1) {
        return false;
    }
    
    // Check thread IDs
    $thread_ids = trim($settings['thread_ids']);
    
    // If thread_ids is empty or "0", allow all threads (default behavior when enabled)
    if (empty($thread_ids) || $thread_ids == '0') {
        return true;
    }
    
    // Check if current thread ID is in the allowed list
    $allowed_thread_ids = explode(',', $thread_ids);
    $allowed_thread_ids = array_map('trim', $allowed_thread_ids);
    
    return in_array($tid, $allowed_thread_ids);
}

// Helper function to check if a user is a collaborator in a thread
function thread_collaboration_is_user_collaborator($user_id, $thread_id)
{
    global $db;
    
    if (!$user_id || !$thread_id) {
        return false;
    }
    
    try {
        $query = $db->simple_select("thread_collaborators", "cid", "tid='{$thread_id}' AND uid='{$user_id}'", array('limit' => 1));
        $is_collaborator = ($query && $db->num_rows($query) > 0);
        

        
        return $is_collaborator;
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error checking if user {$user_id} is collaborator in thread {$thread_id}: " . $e->getMessage());
        return false;
    }
}

// Check if user has reached maximum threads limit
function thread_collaboration_check_user_thread_limit($user_id)
{
    global $mybb, $db;
    
    $max_threads = (int)$mybb->settings['thread_collaboration_max_threads_per_user'];
    if ($max_threads <= 0) {
        return true; // No limit
    }
    
    $query = $db->simple_select("thread_collaborators", "DISTINCT tid", "uid='".(int)$user_id."'");
    $thread_count = $db->num_rows($query);
    
    return $thread_count < $max_threads;
}

// Check if user has reached maximum roles limit
function thread_collaboration_check_user_role_limit($user_id)
{
    global $mybb, $db;
    
    $max_roles = (int)$mybb->settings['thread_collaboration_max_roles_per_user'];
    if ($max_roles <= 0) {
        return true; // No limit
    }
    
    $query = $db->simple_select("thread_collaborators", "DISTINCT role", "uid='".(int)$user_id."'");
    $role_count = $db->num_rows($query);
    
    return $role_count < $max_roles;
}

// Get user's current thread count
function thread_collaboration_get_user_thread_count($user_id)
{
    global $db;
    
    $query = $db->simple_select("thread_collaborators", "DISTINCT tid", "uid='".(int)$user_id."'");
    return $db->num_rows($query);
}

// Get user's current role count
function thread_collaboration_get_user_role_count($user_id)
{
    global $db;
    
    $query = $db->simple_select("thread_collaborators", "DISTINCT role", "uid='".(int)$user_id."'");
    return $db->num_rows($query);
}
// Helper function to send PM notifications to collaborators
function thread_collaboration_send_pm_notifications($collaborators, $thread_id, $thread_subject, $from_user_id, $from_username, $is_update = false)
{
    global $mybb, $lang, $db;
    
    // Load the language file
    $lang->load("thread_collaboration");
    
    // Check if PM notifications are enabled
    if ($mybb->settings['thread_collaboration_pm_notifications'] != '1') {
        error_log("Thread Collaboration: PM notifications are disabled");
        return false;
    }
    
    // If this is an update, check if PM on update is enabled
    if ($is_update && $mybb->settings['thread_collaboration_pm_on_update'] != '1') {
        error_log("Thread Collaboration: PM notifications on update are disabled");
        return false;
    }
    
    error_log("Thread Collaboration: Sending PM notifications to " . count($collaborators) . " collaborators for thread " . $thread_id . " (update: " . ($is_update ? "yes" : "no") . ")");
    
    // Load PM handler
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    
    // Create a proper clickable thread link with the thread subject
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . $thread_id;
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    
    // Create UCP collaboration invitations URL
    $ucp_collaboration_url = $mybb->settings['bburl'] . "/usercp.php?action=collaboration_invitations";
    
    $pm_sent_count = 0;
    $errors = array();
    
    foreach ($collaborators as $collaborator) {
        try {
            $pm_subject = $lang->sprintf($lang->thread_collaboration_pm_subject, $thread_subject);
            
            // Check if custom PM message template is set
            if (!empty($mybb->settings['thread_collaboration_custom_pm_message'])) {
                $pm_message = $mybb->settings['thread_collaboration_custom_pm_message'];
                $pm_message = str_replace(
                    array('{1}', '{2}', '{3}', '{4}', '{5}'),
                    array($from_username, $thread_subject, htmlspecialchars_uni($collaborator['role']), $clickable_thread_link, $ucp_collaboration_url),
                    $pm_message
                );
            } else {
                // Use default message with 4 parameters (no UCP link in default message)
                $pm_message = $lang->sprintf($lang->thread_collaboration_pm_message, $from_username, $thread_subject, htmlspecialchars_uni($collaborator['role']), $clickable_thread_link);
            }

            $pm = array(
                "subject" => $pm_subject,
                "message" => $pm_message,
                "icon" => "",
                "toid" => array($collaborator['uid']),
                "fromid" => $from_user_id,
                "do" => "",
                "pmid" => ""
            );

            $pmhandler = new PMDataHandler();
            $pmhandler->set_data($pm);
            if ($pmhandler->validate_pm()) {
                $pmhandler->insert_pm();
                $pm_sent_count++;
                error_log("Thread Collaboration: PM sent to user {$collaborator['uid']} for role {$collaborator['role']} in " . ($is_update ? "updated" : "new") . " thread");
            } else {
                $errors[] = "Failed to send PM to user {$collaborator['uid']}";
            }
        } catch (Exception $e) {
            $errors[] = "Error sending PM to user {$collaborator['uid']}: " . $e->getMessage();
            error_log("Thread Collaboration: PM error for user {$collaborator['uid']}: " . $e->getMessage());
        }
    }
    
    // Log any errors
    if (!empty($errors)) {
        error_log("Thread Collaboration: PM errors: " . implode(", ", $errors));
    }
    
    return $pm_sent_count;
}
// Hooks will be added here later
if (defined('IN_MYBB')) {
    global $plugins;
    $plugins->add_hook('newthread_start', 'thread_collaboration_newthread_start');
    $plugins->add_hook('newthread_do_newthread_end', 'thread_collaboration_newthread_do_newthread_end');
    $plugins->add_hook('showthread_end', 'thread_collaboration_showthread_end');
    $plugins->add_hook('editpost_action_start', 'thread_collaboration_editpost_action_start');
    // Add hooks for edit history tracking
    $plugins->add_hook('editpost_do_editpost_start', 'thread_collaboration_track_post_edit_start');
    $plugins->add_hook('editpost_do_editpost_start', 'thread_collaboration_editpost_do_editpost_start');
    $plugins->add_hook('editpost_do_editpost_end', 'thread_collaboration_track_post_edit_end');
    $plugins->add_hook('postbit', 'thread_collaboration_postbit');
    $plugins->add_hook('usercp_start', 'thread_collaboration_usercp_start');
    $plugins->add_hook('global_start', 'thread_collaboration_cleanup_expired_invitations');
    $plugins->add_hook('global_start', 'thread_collaboration_cleanup_expired_requests');
    $plugins->add_hook('global_start', 'thread_collaboration_load_language');
    $plugins->add_hook('showthread_start', 'thread_collaboration_showthread_start');
    $plugins->add_hook('showthread_end', 'thread_collaboration_showthread_request_button');
    $plugins->add_hook('member_profile_end', 'thread_collaboration_member_profile_end');
    $plugins->add_hook('xmlhttp_edit_post_end', 'thread_collaboration_xmlhttp_edit_post_end');
    $plugins->add_hook('reputation_start', 'thread_collaboration_reputation_hook');
    $plugins->add_hook('reputation_do_add_end', 'thread_collaboration_reputation_end_hook');
    $plugins->add_hook('postbit', 'thread_collaboration_postbit_multi_author');
    $plugins->add_hook('search_do_search_process', 'thread_collaboration_search_hook');
    $plugins->add_hook('search_do_search_process', 'thread_collaboration_search_results_hook');
    
}

// Handle request approve/reject
function thread_collaboration_handle_request_response($request_id, $action)
{
    global $mybb, $db, $lang;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    // Get the request details
    $query = $db->simple_select("collaboration_requests", "*", "request_id='{$request_id}' AND thread_owner_uid='{$mybb->user['uid']}' AND status='pending'", array('limit' => 1));
    if (!$query || $db->num_rows($query) == 0) {
        error("Invalid request or request already processed.");
        return;
    }
    
    $request = $db->fetch_array($query);
    
    if ($action == 'approve') {
        // Add user as collaborator
        try {
            $db->insert_query("thread_collaborators", array(
                "tid" => $request['tid'],
                "uid" => $request['requester_uid'],
                "role" => $request['role'],
                "role_icon" => $request['role_icon'],
                "joined_date" => TIME_NOW,
                "joined_via" => 'request',
                "source_id" => $request['request_id']
            ));
            
            // Update request status
            $db->update_query("collaboration_requests", array(
                "status" => 'approved',
                "response_date" => TIME_NOW
            ), "request_id='{$request_id}'");
            
            // Send PM notification to requester
            thread_collaboration_send_request_approval_notification($request);
            
            redirect("usercp.php?action=collaboration_invitations", "Request approved successfully. The user is now a collaborator on your thread.");
        
    } catch (Exception $e) {
            error("Error approving request: " . $e->getMessage());
        }
        
    } elseif ($action == 'reject') {
        // Update request status
        try {
            $db->update_query("collaboration_requests", array(
                "status" => 'rejected',
                "response_date" => TIME_NOW
            ), "request_id='{$request_id}'");
            
            // Send PM notification to requester
            thread_collaboration_send_request_rejection_notification($request);
            
            redirect("usercp.php?action=collaboration_invitations", "Request rejected successfully.");
            
        } catch (Exception $e) {
            error("Error rejecting request: " . $e->getMessage());
        }
    }
}
// Send request approval notification to requester
function thread_collaboration_send_request_approval_notification($request)
{
    global $mybb, $db, $lang;
    
    // Check if PM notifications are enabled
    if ($mybb->settings['thread_collaboration_request_notifications'] != '1') {
        return;
    }
    
    // Load PM handler
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    
    // Get thread subject
    $thread_query = $db->simple_select("threads", "subject", "tid='{$request['tid']}'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    $thread_subject = $thread_data['subject'] ?: 'Unknown Thread';
    
    // Create thread link
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . $request['tid'];
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    
    try {
        $pm_subject = "Collaboration Request Approved: " . $thread_subject;
        $pm_message = "Hello,\n\nYour collaboration request for the thread [b]{$clickable_thread_link}[/b] as a [b]{$request['role']}[/b] has been approved!\n\n";
        $pm_message .= "You are now a collaborator on this thread. You can start contributing right away!\n\n";
        $pm_message .= "Best regards,\n[b]" . $mybb->settings['bbname'] . "[/b]";
        
        $pm = array(
            "subject" => $pm_subject,
            "message" => $pm_message,
            "icon" => "",
            "toid" => array($request['requester_uid']),
            "fromid" => $mybb->user['uid'],
            "do" => "",
            "pmid" => ""
        );
        
        $pmhandler = new PMDataHandler();
        $pmhandler->set_data($pm);
        if ($pmhandler->validate_pm()) {
            $pmhandler->insert_pm();
            error_log("Thread Collaboration: Request approval PM sent to requester {$request['requester_uid']}");
        }
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error sending request approval PM: " . $e->getMessage());
    }
}
// Send request rejection notification to requester
function thread_collaboration_send_request_rejection_notification($request)
{
    global $mybb, $db, $lang;
    
    // Check if PM notifications are enabled
    if ($mybb->settings['thread_collaboration_request_notifications'] != '1') {
        return;
    }
    
    // Load PM handler
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    
    // Get thread subject
    $thread_query = $db->simple_select("threads", "subject", "tid='{$request['tid']}'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    $thread_subject = $thread_data['subject'] ?: 'Unknown Thread';
    
    // Create thread link
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . $request['tid'];
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    
    try {
        $pm_subject = "Collaboration Request Update: " . $thread_subject;
        $pm_message = "Hello,\n\nYour collaboration request for the thread [b]{$clickable_thread_link}[/b] as a [b]{$request['role']}[/b] has been declined.\n\n";
        $pm_message .= "Thank you for your interest in collaborating. You may try requesting collaboration on other threads.\n\n";
        $pm_message .= "Best regards,\n[b]" . $mybb->settings['bbname'] . "[/b]";
        
        $pm = array(
            "subject" => $pm_subject,
            "message" => $pm_message,
            "icon" => "",
            "toid" => array($request['requester_uid']),
            "fromid" => $mybb->user['uid'],
            "do" => "",
            "pmid" => ""
        );
        
        $pmhandler = new PMDataHandler();
        $pmhandler->set_data($pm);
        if ($pmhandler->validate_pm()) {
            $pmhandler->insert_pm();
            error_log("Thread Collaboration: Request rejection PM sent to requester {$request['requester_uid']}");
        }
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error sending request rejection PM: " . $e->getMessage());
    }
}
// Load language file in frontend
function thread_collaboration_load_language()
{
    global $lang;
    $lang->load("thread_collaboration");
}


// Process collaboration request
function thread_collaboration_process_request()
{
    global $mybb, $db, $thread, $lang;
    
    // Validate input
    $role = trim($mybb->get_input('request_role'));
    $role_icon = trim($mybb->get_input('request_role_icon'));
    $message = trim($mybb->get_input('request_message'));
    
    // Validate role
    if (empty($role) || strlen($role) < 1 || strlen($role) > 100) {
        error("Please enter a valid role (1-100 characters).");
        return;
    }
    
    // Validate icon length
    if (!empty($role_icon) && (strlen($role_icon) < 1 || strlen($role_icon) > 100)) {
        error("Role icon must be between 1 and 100 characters.");
        return;
    }
    
    // Validate message length
    if (strlen($message) > 1000) {
        error("Message must be 1000 characters or less.");
        return;
    }
    
    try {
        // Insert the request
        $db->insert_query("collaboration_requests", array(
            "tid" => $thread['tid'],
            "requester_uid" => $mybb->user['uid'],
            "thread_owner_uid" => $thread['uid'],
            "role" => $db->escape_string($role),
            "role_icon" => $db->escape_string($role_icon),
            "message" => $db->escape_string($message),
            "status" => 'pending',
            "request_date" => TIME_NOW
        ));
        
        // Send PM notification to thread owner
        thread_collaboration_send_request_notification($thread['tid'], $thread['uid'], $role, $message);
        
        redirect("showthread.php?tid=" . $thread['tid'], "Collaboration request submitted successfully. The thread owner will be notified.");
        
    } catch (Exception $e) {
        error("Error submitting request: " . $e->getMessage());
    }
}
// Send request notification to thread owner
function thread_collaboration_send_request_notification($thread_id, $thread_owner_uid, $role, $message)
{
    global $mybb, $db, $lang;
    
    // Check if PM notifications are enabled
    if ($mybb->settings['thread_collaboration_request_notifications'] != '1') {
        return;
    }
    
    // Load PM handler
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    
    // Get thread subject
    $thread_query = $db->simple_select("threads", "subject", "tid='{$thread_id}'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    $thread_subject = $thread_data['subject'] ?: 'Unknown Thread';
    
    // Create thread link
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . $thread_id;
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    
    // Create UCP requests URL
    $ucp_requests_url = $mybb->settings['bburl'] . "/usercp.php?action=collaboration_invitations";
    
    try {
        $pm_subject = "Collaboration Request: " . $thread_subject;
        $pm_message = "Hello,\n\n[b]{$mybb->user['username']}[/b] has requested to collaborate on your thread [b]{$clickable_thread_link}[/b] as a [b]{$role}[/b].\n\n";
        
        if (!empty($message)) {
            $pm_message .= "Message from [b]{$mybb->user['username']}[/b]:\n{$message}\n\n";
        }
        
        $pm_message .= "You can review and respond to this request in your User Control Panel: [url={$ucp_requests_url}]Collaboration Invitations[/url]\n\n";
        $pm_message .= "Best regards,\n[b]" . $mybb->settings['bbname'] . "[/b]";
        
        $pm = array(
            "subject" => $pm_subject,
            "message" => $pm_message,
            "icon" => "",
            "toid" => array($thread_owner_uid),
            "fromid" => $mybb->user['uid'],
            "do" => "",
            "pmid" => ""
        );
        
        $pmhandler = new PMDataHandler();
        $pmhandler->set_data($pm);
        if ($pmhandler->validate_pm()) {
            $pmhandler->insert_pm();
            error_log("Thread Collaboration: Request notification PM sent to thread owner {$thread_owner_uid}");
        }
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error sending request notification PM: " . $e->getMessage());
    }
}
// Helper function to get default roles for Select2
function thread_collaboration_get_default_roles()
{
    global $mybb;
    
    $default_roles = array();
    $default_roles_text = $mybb->settings['thread_collaboration_default_roles'];
    
    if (!empty($default_roles_text)) {
        $roles_lines = explode("\n", $default_roles_text);
        foreach ($roles_lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $parts = explode('|', $line);
                if (count($parts) >= 2) {
                    $default_roles[] = array(
                        'name' => trim($parts[0]),
                        'icon' => trim($parts[1])
                    );
                }
            }
        }
    }
    
    return $default_roles;
}
// Function to generate user statistics
function thread_collaboration_generate_user_stats($uid)
{
    global $db;
    
    $total_invitations = 0;
    $pending_invitations = 0;
    $accepted_invitations = 0;
    $declined_invitations = 0;
    $total_collaborations = 0;
    $unique_roles = array();
    $unique_threads = array();
    $inviters = array();
    
    try {
        $query = $db->simple_select("thread_collaborators", "role, role_icon", "uid='{$uid}'");
        if ($query && $db->num_rows($query) > 0) {
            while ($collaborator = $db->fetch_array($query)) {
                $role = htmlspecialchars_uni($collaborator['role']);
                $role_icon = htmlspecialchars_uni($collaborator['role_icon']);
                
                $total_collaborations++;
                $unique_roles[] = $role;
                
                $thread_query = $db->simple_select("threads", "subject", "tid IN (SELECT tid FROM " . TABLE_PREFIX . "thread_collaborators WHERE uid='{$uid}')");
                if ($thread_query && $db->num_rows($thread_query) > 0) {
                    while ($thread = $db->fetch_array($thread_query)) {
                        $unique_threads[] = $thread['subject'];
                    }
                }
                
                $invite_query = $db->simple_select("collaboration_invitations", "status", "invitee_uid='{$uid}' AND status='pending'");
                if ($invite_query && $db->num_rows($invite_query) > 0) {
                    $pending_invitations++;
                }
                
                $accept_query = $db->simple_select("collaboration_invitations", "status", "invitee_uid='{$uid}' AND status='accepted'");
                if ($accept_query && $db->num_rows($accept_query) > 0) {
                    $accepted_invitations++;
                }
                
                $decline_query = $db->simple_select("collaboration_invitations", "status", "invitee_uid='{$uid}' AND status='declined'");
                if ($decline_query && $db->num_rows($decline_query) > 0) {
                    $declined_invitations++;
                }
                
                $inviter_query = $db->simple_select("collaboration_invitations", "inviter_uid", "invitee_uid='{$uid}'");
                if ($inviter_query && $db->num_rows($inviter_query) > 0) {
                    while ($inviter = $db->fetch_array($inviter_query)) {
                        $inviters[] = $inviter['inviter_uid'];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error generating user statistics: " . $e->getMessage());
    }
    
    $unique_roles_count = count(array_unique($unique_roles));
    $unique_threads_count = count(array_unique($unique_threads));
    $unique_inviters_count = count(array_unique($inviters));
    
    $roles_list = implode(', ', $unique_roles);
    $inviters_list = implode(', ', array_unique($inviters));
    $threads_list = implode(', ', $unique_threads);
    
    return array(
        'total_invitations' => $total_invitations,
        'pending_invitations' => $pending_invitations,
        'accepted_invitations' => $accepted_invitations,
        'declined_invitations' => $declined_invitations,
        'total_collaborations' => $total_collaborations,
        'unique_roles' => $unique_roles_count,
        'unique_threads' => $unique_threads_count,
        'unique_inviters' => $unique_inviters_count,
        'roles_list' => $roles_list,
        'inviters_list' => $inviters_list,
        'threads_list' => $threads_list
    );
}
// inviter stats removed
// Function to render statistics table

function thread_collaboration_render_request_stats_table($stats_data)
{
    global $templates, $lang, $db, $mybb;

    $uid = (int)$mybb->user['uid'];
    
    // Compute requests SENT by current user
    $total_requests_sent = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "requester_uid='{$uid}'"), 'c');
    $pending_requests_sent = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "requester_uid='{$uid}' AND status='pending'"), 'c');
    $approved_requests_sent = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "requester_uid='{$uid}' AND status='approved'"), 'c');
    $rejected_requests_sent = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "requester_uid='{$uid}' AND status='rejected'"), 'c');

    // Compute requests RECEIVED by current user (as thread owner)
    $total_requests_received = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "thread_owner_uid='{$uid}'"), 'c');
    $pending_requests_received = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "thread_owner_uid='{$uid}' AND status='pending'"), 'c');
    $approved_requests_received = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "thread_owner_uid='{$uid}' AND status='approved'"), 'c');
    $rejected_requests_received = (int)$db->fetch_field($db->simple_select('collaboration_requests', 'COUNT(*) AS c', "thread_owner_uid='{$uid}' AND status='rejected'"), 'c');

    // Build roles requested list (as requester)
    $roles_requested_list = '';
    $roles_q = $db->simple_select('collaboration_requests', 'DISTINCT role', "requester_uid='{$uid}' AND role<>''");
    if($roles_q && $db->num_rows($roles_q) > 0) {
        $tags = array();
        while($r = $db->fetch_array($roles_q)) {
            $tags[] = '<span class="role-tag">'.htmlspecialchars_uni($r['role']).'</span>';
        }
        $roles_requested_list = implode(' ', $tags);
    } else {
        $roles_requested_list = '<span class="smalltext" style="color:#666;">None</span>';    
    }

    // Build threads requested list (as requester)
    $threads_requested_list = '';
    $tid_list = array();
    $tids_q = $db->simple_select('collaboration_requests', 'DISTINCT tid', "requester_uid='{$uid}' AND tid>0");
    while($row = $db->fetch_array($tids_q)) { $tid_list[] = (int)$row['tid']; }
    if(!empty($tid_list)) {
        $in = implode(',', array_map('intval', $tid_list));
        $thr_q = $db->simple_select('threads', 'tid, subject', "tid IN ({$in})");
        $items = array();
        while($thr = $db->fetch_array($thr_q)) {
            $items[] = '<div class="thread-item"><a href="showthread.php?tid='.$thr['tid'].'">'.htmlspecialchars_uni($thr['subject']).'</a></div>';
        }
        $threads_requested_list = implode('', $items);
    } else {
        $threads_requested_list = '<div class="smalltext" style="color:#666;">None</div>';
    }

    // Language fallbacks for these labels
    if(empty($lang->roles_requested)) $lang->roles_requested = 'Roles Requested';
    if(empty($lang->threads_requested)) $lang->threads_requested = 'Threads Requested';

    eval("\$request_stats_content = \"" . $templates->get("threadcollaboration_request_stats_table") . "\";");
    return $request_stats_content;
}
// Function to generate comprehensive user statistics
function thread_collaboration_generate_comprehensive_stats($uid)
{
    global $db;
    
    $stats = array();
    
    try {
        // 1. INVITER STATS (what you've sent/invited)
        $stats['inviter'] = array(
            'total_invitations_sent' => 0,
            'pending_invitations_sent' => 0,
            'accepted_invitations_sent' => 0,
            'declined_invitations_sent' => 0,
            'total_collaborators_gained' => 0,
            'unique_roles_assigned' => 0,
            'unique_threads_with_collaborators' => 0
        );
        
        // Count invitations sent by this user
        $invitations_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "inviter_uid='{$uid}'");
        if ($invitations_query && $invitation_count = $db->fetch_array($invitations_query)) {
            $stats['inviter']['total_invitations_sent'] = $invitation_count['count'];
        }
        
        $pending_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "inviter_uid='{$uid}' AND status='pending'");
        if ($pending_query && $pending_count = $db->fetch_array($pending_query)) {
            $stats['inviter']['pending_invitations_sent'] = $pending_count['count'];
        }
        
        $accepted_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "inviter_uid='{$uid}' AND status='accepted'");
        if ($accepted_query && $accepted_count = $db->fetch_array($accepted_query)) {
            $stats['inviter']['accepted_invitations_sent'] = $accepted_count['count'];
        }
        
        $declined_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "inviter_uid='{$uid}' AND status='declined'");
        if ($declined_query && $declined_count = $db->fetch_array($declined_query)) {
            $stats['inviter']['declined_invitations_sent'] = $declined_count['count'];
        }
        
        // Count actual collaborators gained (from thread_collaborators table where user is thread owner)
        $collaborators_gained_query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t", "COUNT(DISTINCT tc.uid) as count", "t.uid='{$uid}' AND tc.tid=t.tid");
        $total_collaborators_gained = 0;
        if ($collaborators_gained_query && $collaborators_count = $db->fetch_array($collaborators_gained_query)) {
            $total_collaborators_gained = (int)$collaborators_count['count'];
        }
        
        $stats['inviter']['total_collaborators_gained'] = $total_collaborators_gained;
        
        // Get unique roles assigned (from thread_collaborators where user is thread owner)
        $roles_assigned = array();
        $roles_query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t", "DISTINCT tc.role", "t.uid='{$uid}' AND tc.tid=t.tid AND tc.role<>''");
        if ($roles_query && $db->num_rows($roles_query) > 0) {
            while ($role_data = $db->fetch_array($roles_query)) {
                $roles_assigned[] = $role_data['role'];
            }
        }
        
        $stats['inviter']['unique_roles_assigned'] = count(array_unique($roles_assigned));
        
        // Get unique threads with collaborators (from thread_collaborators where user is thread owner)
        $threads_with_collaborators = array();
        $threads_query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t", "DISTINCT tc.tid", "t.uid='{$uid}' AND tc.tid=t.tid");
        if ($threads_query && $db->num_rows($threads_query) > 0) {
            while ($thread_data = $db->fetch_array($threads_query)) {
                $threads_with_collaborators[] = $thread_data['tid'];
            }
        }
        
        $stats['inviter']['unique_threads_with_collaborators'] = count(array_unique($threads_with_collaborators));
        
        // 2. INVITEE STATS (where you've been invited)
        $stats['invitee'] = array(
            'total_invitations_received' => 0,
            'pending_invitations_received' => 0,
            'accepted_invitations_received' => 0,
            'declined_invitations_received' => 0,
            'unique_inviters' => 0
        );
        
        $invitations_received_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "invitee_uid='{$uid}'");
        if ($invitations_received_query && $invitation_count = $db->fetch_array($invitations_received_query)) {
            $stats['invitee']['total_invitations_received'] = $invitation_count['count'];
        }
        
        $pending_received_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "invitee_uid='{$uid}' AND status='pending'");
        if ($pending_received_query && $pending_count = $db->fetch_array($pending_received_query)) {
            $stats['invitee']['pending_invitations_received'] = $pending_count['count'];
        }
        
        $accepted_received_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "invitee_uid='{$uid}' AND status='accepted'");
        if ($accepted_received_query && $accepted_count = $db->fetch_array($accepted_received_query)) {
            $stats['invitee']['accepted_invitations_received'] = $accepted_count['count'];
        }
        
        $declined_received_query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", "invitee_uid='{$uid}' AND status='declined'");
        if ($declined_received_query && $declined_count = $db->fetch_array($declined_received_query)) {
            $stats['invitee']['declined_invitations_received'] = $declined_count['count'];
        }
        
        // Count unique inviters
        $inviters_query = $db->simple_select("collaboration_invitations", "DISTINCT inviter_uid", "invitee_uid='{$uid}'");
        if ($inviters_query && $db->num_rows($inviters_query) > 0) {
            $stats['invitee']['unique_inviters'] = $db->num_rows($inviters_query);
        }
        
        // 3. REQUESTER STATS (where you've requested to collaborate)
        $stats['requester'] = array(
            'total_requests_sent' => 0,
            'pending_requests_sent' => 0,
            'approved_requests_sent' => 0,
            'rejected_requests_sent' => 0,
            'unique_thread_owners' => 0
        );
        
        $requests_sent_query = $db->simple_select("collaboration_requests", "COUNT(*) as count", "requester_uid='{$uid}'");
        if ($requests_sent_query && $request_count = $db->fetch_array($requests_sent_query)) {
            $stats['requester']['total_requests_sent'] = $request_count['count'];
        }
        
        $pending_requests_query = $db->simple_select("collaboration_requests", "COUNT(*) as count", "requester_uid='{$uid}' AND status='pending'");
        if ($pending_requests_query && $pending_count = $db->fetch_array($pending_requests_query)) {
            $stats['requester']['pending_requests_sent'] = $pending_count['count'];
        }
        
        $approved_requests_query = $db->simple_select("collaboration_requests", "COUNT(*) as count", "requester_uid='{$uid}' AND status='approved'");
        if ($approved_requests_query && $approved_count = $db->fetch_array($approved_requests_query)) {
            $stats['requester']['approved_requests_sent'] = $approved_count['count'];
        }
        
        $rejected_requests_query = $db->simple_select("collaboration_requests", "COUNT(*) as count", "requester_uid='{$uid}' AND status='rejected'");
        if ($rejected_requests_query && $rejected_count = $db->fetch_array($rejected_requests_query)) {
            $stats['requester']['rejected_requests_sent'] = $rejected_count['count'];
        }
        
        // Count unique thread owners
        $thread_owners_query = $db->simple_select("collaboration_requests", "DISTINCT thread_owner_uid", "requester_uid='{$uid}'");
        if ($thread_owners_query && $db->num_rows($thread_owners_query) > 0) {
            $stats['requester']['unique_thread_owners'] = $db->num_rows($thread_owners_query);
        }
        
        // 4. COLLABORATOR STATS (where you are/were a collaborator)
        $stats['collaborator'] = array(
            'total_threads_as_collaborator' => 0,
            'current_collaborations' => 0,
            'unique_roles_played' => 0,
            'unique_forums_collaborated' => 0
        );
        
        // Get collaborations from thread_collaborators table (this is the authoritative source)
        $collaborator_query = $db->simple_select("thread_collaborators", "COUNT(*) as count", "uid='{$uid}'");
        $total_collaborations = 0;
        if ($collaborator_query && $collaborator_count = $db->fetch_array($collaborator_query)) {
            $total_collaborations = (int)$collaborator_count['count'];
        }
        
        $stats['collaborator']['total_threads_as_collaborator'] = $total_collaborations;
        $stats['collaborator']['current_collaborations'] = $total_collaborations;
        
        // Get unique roles played from thread_collaborators (authoritative source)
        $roles_played = array();
        $roles_played_query = $db->simple_select("thread_collaborators", "DISTINCT role", "uid='{$uid}' AND role<>''");
        if ($roles_played_query && $db->num_rows($roles_played_query) > 0) {
            while ($role_data = $db->fetch_array($roles_played_query)) {
                $roles_played[] = $role_data['role'];
        }
        }
        
        $stats['collaborator']['unique_roles_played'] = count(array_unique($roles_played));
        
        // Get unique forums collaborated in from thread_collaborators (authoritative source)
        $forums_collaborated = array();
        $forums_query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t, " . TABLE_PREFIX . "forums f", "DISTINCT t.fid", "tc.uid='{$uid}' AND tc.tid=t.tid AND t.fid=f.fid");
        if ($forums_query && $db->num_rows($forums_query) > 0) {
            while ($forum_data = $db->fetch_array($forums_query)) {
                $forums_collaborated[] = $forum_data['fid'];
        }
        }
        
        $stats['collaborator']['unique_forums_collaborated'] = count(array_unique($forums_collaborated));
        
    } catch (Exception $e) {
        error_log("Thread Collaboration: Error generating comprehensive statistics: " . $e->getMessage());
    }
    
    return $stats;
}

// Function to render comprehensive statistics table
function thread_collaboration_render_comprehensive_stats($stats_data)
{
    global $templates, $lang;
    
    // Set template variables for inviter stats
    $total_invitations_sent = $stats_data['inviter']['total_invitations_sent'];
    $pending_invitations_sent = $stats_data['inviter']['pending_invitations_sent'];
    $accepted_invitations_sent = $stats_data['inviter']['accepted_invitations_sent'];
    $declined_invitations_sent = $stats_data['inviter']['declined_invitations_sent'];
    $total_collaborators_gained = $stats_data['inviter']['total_collaborators_gained'];
    $unique_roles_assigned = $stats_data['inviter']['unique_roles_assigned'];
    $unique_threads_with_collaborators = $stats_data['inviter']['unique_threads_with_collaborators'];
    
    // Set template variables for invitee stats
    $total_invitations_received = $stats_data['invitee']['total_invitations_received'];
    $pending_invitations_received = $stats_data['invitee']['pending_invitations_received'];
    $accepted_invitations_received = $stats_data['invitee']['accepted_invitations_received'];
    $declined_invitations_received = $stats_data['invitee']['declined_invitations_received'];
    $unique_inviters = $stats_data['invitee']['unique_inviters'];
    
    // Set template variables for requester stats
    $total_requests_sent = $stats_data['requester']['total_requests_sent'];
    $pending_requests_sent = $stats_data['requester']['pending_requests_sent'];
    $approved_requests_sent = $stats_data['requester']['approved_requests_sent'];
    $rejected_requests_sent = $stats_data['requester']['rejected_requests_sent'];
    $unique_thread_owners = $stats_data['requester']['unique_thread_owners'];
    
    // Set template variables for collaborator stats
    $total_threads_as_collaborator = $stats_data['collaborator']['total_threads_as_collaborator'];
    $unique_roles_played = $stats_data['collaborator']['unique_roles_played'];
    $unique_forums_collaborated = $stats_data['collaborator']['unique_forums_collaborated'];

    // Map to template placeholders used in thread_collaboration_comprehensive_stats
    $total_collaborations = $total_threads_as_collaborator;
    $unique_collaboration_roles = $unique_roles_played;
    $active_collaborations = $stats_data['collaborator']['current_collaborations'];
    $forums_collaborated = $unique_forums_collaborated;
    
    // Use the comprehensive stats template
    eval("\$comprehensive_stats_content = \"" . $templates->get("threadcollaboration_comprehensive_stats") . "\";");
    
    return $comprehensive_stats_content;
}
// Handle collaboration collaborations views
function thread_collaboration_handle_collaborations_view($view)
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    $page_title = "Collaboration Collaborations";
    $content = "";
    
    switch($view) {
        case 'all':
            $page_title = "All Threads as Collaborator";
            $content = thread_collaboration_render_collaborations_list();
            break;
            
        case 'roles':
            $page_title = "Roles You've Played";
            $content = thread_collaboration_render_collaboration_roles();
            break;
            
        case 'forums':
            $page_title = "Forums You've Collaborated In";
            $content = thread_collaboration_render_collaboration_forums();
            break;
            
        case 'unique_inviters':
            // Handle unique inviters view
            thread_collaboration_load_language();
            thread_collaboration_handle_unique_inviters_view();
            break;
            
        default:
            $page_title = "Collaboration Collaborations";
            $content = thread_collaboration_render_collaborations_list();
            break;
    }
    
    // Build UserCP navigation
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    
    // Use the generic page template
    $page_content = $content;
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}

// Render collaborations list
function thread_collaboration_render_collaborations_list()
{
    global $mybb, $db, $templates;
    
    $uid = (int)$mybb->user['uid'];
    $rows = '';
    $query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t, " . TABLE_PREFIX . "forums f", 
        "tc.tid, tc.role, tc.role_icon, t.subject, t.fid, f.name as forum_name", 
        "tc.uid='{$uid}' AND tc.tid=t.tid AND t.fid=f.fid", 
        array("order_by" => "t.subject"));
    
    if ($db->num_rows($query) > 0) {
        while ($collaboration = $db->fetch_array($query)) {
            $thread_link = '<a href="showthread.php?tid=' . (int)$collaboration['tid'] . '">' . htmlspecialchars_uni($collaboration['subject']) . '</a>';
            $role_display = htmlspecialchars_uni($collaboration['role']);
            if (!empty($collaboration['role_icon'])) {
                $role_display = '<i class="' . htmlspecialchars_uni($collaboration['role_icon']) . '"></i> ' . $role_display;
            }
            $forum_name = htmlspecialchars_uni($collaboration['forum_name']);
            
            $rows .= '<tr><td class="trow1">' . $thread_link . '</td><td class="trow1">' . $role_display . '</td><td class="trow1">' . $forum_name . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align: center; padding: 20px; color: #666;">You are not currently a collaborator on any threads.</td></tr>';
    }
    
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_collaborations_list") . "\";");
    return $content;
}
// Render collaboration roles
function thread_collaboration_render_collaboration_roles()
{
    global $mybb, $db, $templates;
    
    $uid = (int)$mybb->user['uid'];
    $rows = '';
    $query = $db->simple_select("thread_collaborators", "role, role_icon, COUNT(*) as count", 
        "uid='{$uid}'", 
        array("group_by" => "role", "order_by" => "count DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($role_data = $db->fetch_array($query)) {
            $role_name = htmlspecialchars_uni($role_data['role']);
            $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
            
            $icon_html = '';
            if (!empty($role_data['role_icon'])) {
                $icon_html = '<i class="' . htmlspecialchars_uni($role_data['role_icon']) . '"></i> ';
            }
            
            // Wrap role in span with dynamic class and default class
            $role_display = '<span class="default-role ' . $role_class . '">' . $icon_html . $role_name . '</span>';
            $count = (int)$role_data['count'];
            
            $rows .= '<tr><td class="trow1">' . $role_display . '</td><td class="trow1">' . $count . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="2" style="text-align: center; padding: 20px; color: #666;">No collaboration roles found.</td></tr>';
    }
    
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_collaboration_roles") . "\";");
    return $content;
}
// Render collaboration forums
function thread_collaboration_render_collaboration_forums()
{
    global $mybb, $db, $templates;
    
    $uid = (int)$mybb->user['uid'];
    $rows = '';
    $query = $db->simple_select("thread_collaborators tc, " . TABLE_PREFIX . "threads t, " . TABLE_PREFIX . "forums f", 
        "f.fid, f.name as forum_name, COUNT(DISTINCT tc.tid) as thread_count", 
        "tc.uid='{$uid}' AND tc.tid=t.tid AND t.fid=f.fid", 
        array("group_by" => "f.fid", "order_by" => "thread_count DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($forum_data = $db->fetch_array($query)) {
            $forum_name = htmlspecialchars_uni($forum_data['forum_name']);
            $thread_count = (int)$forum_data['thread_count'];
            
            $rows .= '<tr><td class="trow1">' . $forum_name . '</td><td class="trow1">' . $thread_count . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="2" style="text-align: center; padding: 20px; color: #666;">No collaboration forums found.</td></tr>';
    }
    
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_collaboration_forums") . "\";");
    return $content;
}
// Handle unique thread owners view
function thread_collaboration_handle_unique_owners_view()
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    $lang->load("thread_collaboration");
    
    $rows = '';

    $uid = (int)$mybb->user['uid'];
    $query = $db->simple_select(
        "collaboration_requests cr, " . TABLE_PREFIX . "users u",
        "cr.thread_owner_uid, u.username, COUNT(*) as total_requests, 
         SUM(CASE WHEN cr.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN cr.status='approved' THEN 1 ELSE 0 END) as approved_count,
         SUM(CASE WHEN cr.status='rejected' THEN 1 ELSE 0 END) as rejected_count", 
        "cr.requester_uid='{$uid}' AND u.uid=cr.thread_owner_uid", 
        array("group_by" => "cr.thread_owner_uid", "order_by" => "total_requests DESC")
    );
    
    if ($db->num_rows($query) > 0) {
        while ($owner_data = $db->fetch_array($query)) {
            $username = htmlspecialchars_uni($owner_data['username']);
            $total_requests = (int)$owner_data['total_requests'];
            $pending_count = (int)$owner_data['pending_count'];
            $approved_count = (int)$owner_data['approved_count'];
            $rejected_count = (int)$owner_data['rejected_count'];
            
            // Fetch threads requested to this owner
            $threads_html = '';
            $tq = $db->simple_select(
                'collaboration_requests cr, ' . TABLE_PREFIX . 'threads t',
                'DISTINCT cr.tid, t.subject',
                "cr.requester_uid='{$uid}' AND cr.thread_owner_uid='".$db->escape_string($owner_data['thread_owner_uid'])."' AND cr.tid=t.tid"
            );
            while ($t = $db->fetch_array($tq)) {
                $threads_html .= '<div><a href="showthread.php?tid=' . (int)$t['tid'] . '">' . htmlspecialchars_uni($t['subject']) . '</a></div>';
            }
            if ($threads_html === '') {
                $threads_html = '<div class="smalltext" style="color:#666;">None</div>';
            }

            $status_summary = '';
            if ($pending_count > 0) { $status_summary .= '<span class="pending-count">' . $pending_count . ' pending</span> '; }
            if ($approved_count > 0) { $status_summary .= '<span class="accepted-count">' . $approved_count . ' approved</span> '; }
            if ($rejected_count > 0) { $status_summary .= '<span class="declined-count">' . $rejected_count . ' rejected</span>'; }

            $rows .= '<tr><td class="trow1">' . $username . '</td><td class="trow1">' . $total_requests . '</td><td class="trow1">' . $threads_html . '</td><td class="trow1">' . $status_summary . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="4" style="text-align: center; padding: 20px; color: #666;">You haven\'t requested collaboration from any thread owners yet.</td></tr>';
    }
    
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_unique_owners") . "\";");
    
    $page_title = "Unique Thread Owners You've Requested Collaboration From";
    $page_content = $content;
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}
// Handle unique inviters view
function thread_collaboration_handle_unique_inviters_view()
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    $rows = '';
    
    $uid = $mybb->user['uid'];
    $query = $db->simple_select("collaboration_invitations ci, " . TABLE_PREFIX . "users u", 
        "ci.invitee_uid, u.username, COUNT(*) as total_invitations, 
         SUM(CASE WHEN ci.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN ci.status='accepted' THEN 1 ELSE 0 END) as accepted_count,
         SUM(CASE WHEN ci.status='declined' THEN 1 ELSE 0 END) as declined_count", 
        "ci.inviter_uid='{$uid}'", 
        array("group_by" => "ci.invitee_uid", "order_by" => "total_invitations DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($inviter_data = $db->fetch_array($query)) {
            $username = htmlspecialchars_uni($inviter_data['username']);
            $total_invitations = $inviter_data['total_invitations'];
            $pending_count = $inviter_data['pending_count'];
            $accepted_count = $inviter_data['accepted_count'];
            $declined_count = $inviter_data['declined_count'];
            
            $status_summary = "";
            if ($pending_count > 0) {
                $status_summary .= '<span class="pending-count">' . $pending_count . ' pending</span> ';
            }
            if ($accepted_count > 0) {
                $status_summary .= '<span class="accepted-count">' . $accepted_count . ' accepted</span> ';
            }
            if ($declined_count > 0) {
                $status_summary .= '<span class="declined-count">' . $declined_count . ' declined</span>';
            }
            
            $rows .= '<tr><td class="trow1">' . $username . '</td><td class="trow1">' . $total_invitations . '</td><td class="trow1">' . $status_summary . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align: center; padding: 20px; color: #666;">You haven\'t invited any users to collaborate yet.</td></tr>';
    }
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_unique_inviters") . "\";");
    
    // Use the generic page template
    $page_title = "Unique Users You've Invited to Collaborate";
    $page_content = $content;
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}
// Handle threads with collaborators view
function thread_collaboration_handle_threads_with_collaborators_view()
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    $rows = '';
    
    $uid = $mybb->user['uid'];
    
    // Get threads where current user has collaborators from invitations
    $invitations_query = $db->simple_select("collaboration_invitations ci, " . TABLE_PREFIX . "threads t", 
        "ci.tid, t.subject, COUNT(*) as collaborator_count, 
         GROUP_CONCAT(DISTINCT ci.role ORDER BY ci.role SEPARATOR ', ') as roles,
         SUM(CASE WHEN ci.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN ci.status='accepted' THEN 1 ELSE 0 END) as accepted_count,
         SUM(CASE WHEN ci.status='declined' THEN 1 ELSE 0 END) as declined_count", 
        "ci.inviter_uid='{$uid}' AND ci.tid=t.tid", 
        array("group_by" => "ci.tid", "order_by" => "t.subject"));
    
    // Get threads where current user has collaborators from approved requests
    $requests_query = $db->simple_select("collaboration_requests cr, " . TABLE_PREFIX . "threads t", 
        "cr.tid, t.subject, COUNT(*) as collaborator_count, 
         GROUP_CONCAT(DISTINCT cr.role ORDER BY cr.role SEPARATOR ', ') as roles,
         SUM(CASE WHEN cr.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN cr.status='approved' THEN 1 ELSE 0 END) as approved_count,
         SUM(CASE WHEN cr.status='rejected' THEN 1 ELSE 0 END) as rejected_count", 
        "cr.thread_owner_uid='{$uid}' AND cr.tid=t.tid", 
        array("group_by" => "cr.tid", "order_by" => "t.subject"));
    
    $threads_data = array();
    
    // Process invitations
    if ($db->num_rows($invitations_query) > 0) {
        while ($thread_data = $db->fetch_array($invitations_query)) {
            $tid = $thread_data['tid'];
            if (!isset($threads_data[$tid])) {
                $threads_data[$tid] = array(
                    'subject' => $thread_data['subject'],
                    'collaborator_count' => 0,
                    'roles' => array(),
                    'pending_count' => 0,
                    'accepted_count' => 0,
                    'declined_count' => 0
                );
            }
            $threads_data[$tid]['collaborator_count'] += $thread_data['collaborator_count'];
            $threads_data[$tid]['pending_count'] += $thread_data['pending_count'];
            $threads_data[$tid]['accepted_count'] += $thread_data['accepted_count'];
            $threads_data[$tid]['declined_count'] += $thread_data['declined_count'];
            
            // Add roles
            if (!empty($thread_data['roles'])) {
                $roles_array = explode(', ', $thread_data['roles']);
                foreach ($roles_array as $role) {
                    if (!in_array($role, $threads_data[$tid]['roles'])) {
                        $threads_data[$tid]['roles'][] = $role;
                    }
                }
            }
        }
    }
    
    // Process requests
    if ($db->num_rows($requests_query) > 0) {
        while ($thread_data = $db->fetch_array($requests_query)) {
            $tid = $thread_data['tid'];
            if (!isset($threads_data[$tid])) {
                $threads_data[$tid] = array(
                    'subject' => $thread_data['subject'],
                    'collaborator_count' => 0,
                    'roles' => array(),
                    'pending_count' => 0,
                    'accepted_count' => 0,
                    'declined_count' => 0
                );
            }
            $threads_data[$tid]['collaborator_count'] += $thread_data['collaborator_count'];
            $threads_data[$tid]['pending_count'] += $thread_data['pending_count'];
            $threads_data[$tid]['accepted_count'] += $thread_data['approved_count'];
            $threads_data[$tid]['declined_count'] += $thread_data['rejected_count'];
            
            // Add roles
            if (!empty($thread_data['roles'])) {
                $roles_array = explode(', ', $thread_data['roles']);
                foreach ($roles_array as $role) {
                    if (!in_array($role, $threads_data[$tid]['roles'])) {
                        $threads_data[$tid]['roles'][] = $role;
                    }
                }
            }
        }
    }
    
    if (!empty($threads_data)) {
        // Build role icon map per thread from DB and defaults
        $tid_list = array_keys($threads_data);
        $icon_map = array();
        if(!empty($tid_list)) {
            $in = implode(',', array_map('intval', $tid_list));
            // Invitations icons
            $iq = $db->simple_select('collaboration_invitations', 'tid, role, role_icon', "inviter_uid='{$uid}' AND role<>'' AND role_icon<>'' AND tid IN (".$in.")");
            while($row = $db->fetch_array($iq)) { $icon_map[(int)$row['tid']][$row['role']] = $row['role_icon']; }
            // Requests icons
            $rq = $db->simple_select('collaboration_requests', 'tid, role, role_icon', "thread_owner_uid='{$uid}' AND role<>'' AND role_icon<>'' AND tid IN (".$in.")");
            while($row = $db->fetch_array($rq)) { if(empty($icon_map[(int)$row['tid']][$row['role']])) { $icon_map[(int)$row['tid']][$row['role']] = $row['role_icon']; } }
        }
        $default_roles = thread_collaboration_get_default_roles();
        $default_role_icon = array();
        foreach($default_roles as $r){ $default_role_icon[$r['name']] = $r['icon']; }
        
        foreach ($threads_data as $tid => $thread_data) {
            $thread_subject = htmlspecialchars_uni($thread_data['subject']);
            $collaborator_count = $thread_data['collaborator_count'];
            // Build roles with icons and dynamic CSS classes
            $role_labels = array();
            foreach($thread_data['roles'] as $role_name){
                $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                $label = htmlspecialchars_uni($role_name);
                $icon = '';
                if(!empty($icon_map[$tid][$role_name])) { $icon = $icon_map[$tid][$role_name]; }
                elseif(!empty($default_role_icon[$role_name])) { $icon = $default_role_icon[$role_name]; }
                
                $icon_html = '';
                if(!empty($icon)) { 
                    $icon_html = '<i class="'.htmlspecialchars_uni($icon).'"></i> '; 
                }
                
                // Wrap each role in a span with dynamic class and default class
                $role_labels[] = '<span class="default-role ' . $role_class . '">' . $icon_html . $label . '</span>';
            }
            $roles = implode(', ', $role_labels);
            $pending_count = $thread_data['pending_count'];
            $accepted_count = $thread_data['accepted_count'];
            $declined_count = $thread_data['declined_count'];
            
            $status_summary = "";
            if ($pending_count > 0) {
                $status_summary .= '<span class="pending-count">' . $pending_count . ' pending</span> ';
            }
            if ($accepted_count > 0) {
                $status_summary .= '<span class="accepted-count">' . $accepted_count . ' accepted</span> ';
            }
            if ($declined_count > 0) {
                $status_summary .= '<span class="declined-count">' . $declined_count . ' declined</span>';
            }
            
            $rows .= '<tr><td class="trow1"><a href="showthread.php?tid=' . (int)$tid . '">' . $thread_subject . '</a></td><td class="trow1">' . $collaborator_count . '</td><td class="trow1">' . $roles . '</td><td class="trow1">' . $status_summary . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="4" style="text-align: center; padding: 20px; color: #666;">No threads with collaborators found.</td></tr>';
    }
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_threads_with_collaborators") . "\";");
    
    // Use the generic page template
    $page_title = "Threads with Collaborators";
    $page_content = $content;
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}
// Handle invitations received view
function thread_collaboration_handle_invitations_received_view()
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    $lang->load("thread_collaboration");
    
    $rows = '';
    
    $uid = $mybb->user['uid'];
    
    // Fetch all invitations received by current user
    $query = $db->simple_select("collaboration_invitations ci, " . TABLE_PREFIX . "threads t, " . TABLE_PREFIX . "users u", 
        "ci.tid, t.subject, ci.role, ci.role_icon, ci.status, ci.invite_date, u.username as inviter_username", 
        "ci.invitee_uid='{$uid}' AND ci.tid=t.tid AND u.uid=ci.inviter_uid", 
        array("order_by" => "ci.invite_date DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($inv_data = $db->fetch_array($query)) {
            $thread_subject = htmlspecialchars_uni($inv_data['subject']);
            $inviter_username = htmlspecialchars_uni($inv_data['inviter_username']);
            $role = htmlspecialchars_uni($inv_data['role']);
            $role_icon = $inv_data['role_icon'];
            $status = $inv_data['status'];
            $invite_date = my_date('relative', $inv_data['invite_date']);
            
            // Add role icon if available
            $role_with_icon = $role;
            if (!empty($role_icon)) {
                $role_with_icon = '<i class="' . htmlspecialchars_uni($role_icon) . '"></i> ' . $role;
            }
            
            // Status styling
            $status_class = '';
            switch($status) {
                case 'pending':
                    $status_class = 'pending-count';
                    break;
                case 'accepted':
                    $status_class = 'accepted-count';
                    break;
                case 'declined':
                    $status_class = 'declined-count';
                    break;
            }
            
            $rows .= '<tr><td class="trow1"><a href="showthread.php?tid=' . (int)$inv_data['tid'] . '">' . $thread_subject . '</a></td><td class="trow1">' . $inviter_username . '</td><td class="trow1">' . $role_with_icon . '</td><td class="trow1"><span class="' . $status_class . '">' . ucfirst($status) . '</span></td><td class="trow1">' . $invite_date . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="5" style="text-align: center; padding: 20px; color: #666;">No invitations received yet.</td></tr>';
    }
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_invitations_received") . "\";");
    
    // Use the generic page template
    $page_title = "Total Invitations Received";
    $page_content = $content;
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}
// Handle total requests sent view
function thread_collaboration_handle_total_requests_sent_view()
{
    global $mybb, $db, $templates, $lang, $theme, $header, $headerinclude, $footer, $usercpnav;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    $rows = '';
    
    $uid = $mybb->user['uid'];
    
    // Fetch all requests sent by current user
    $query = $db->simple_select("collaboration_requests cr, " . TABLE_PREFIX . "threads t, " . TABLE_PREFIX . "users u", 
        "cr.tid, t.subject, cr.role, cr.role_icon, cr.status, cr.request_date, u.username as owner_username", 
        "cr.requester_uid='{$uid}' AND cr.tid=t.tid AND u.uid=cr.thread_owner_uid", 
        array("order_by" => "cr.request_date DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($request_data = $db->fetch_array($query)) {
            $thread_subject = htmlspecialchars_uni($request_data['subject']);
            $owner_username = htmlspecialchars_uni($request_data['owner_username']);
            $role = htmlspecialchars_uni($request_data['role']);
            $role_icon = $request_data['role_icon'];
            $status = $request_data['status'];
            $request_date = my_date('relative', $request_data['request_date']);
            
            // Add role icon if available
            $role_with_icon = $role;
            if (!empty($role_icon)) {
                $role_with_icon = '<i class="' . htmlspecialchars_uni($role_icon) . '"></i> ' . $role;
            }
            
            // Status styling
            $status_class = '';
            switch($status) {
                case 'pending':
                    $status_class = 'pending-count';
                    break;
                case 'approved':
                    $status_class = 'accepted-count';
                    break;
                case 'rejected':
                    $status_class = 'declined-count';
                    break;
            }
            
            $rows .= '<tr><td class="trow1"><a href="showthread.php?tid=' . (int)$request_data['tid'] . '">' . $thread_subject . '</a></td><td class="trow1">' . $owner_username . '</td><td class="trow1">' . $role_with_icon . '</td><td class="trow1"><span class="' . $status_class . '">' . ucfirst($status) . '</span></td><td class="trow1">' . $request_date . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="5" style="text-align: center; padding: 20px; color: #666;">No requests sent yet.</td></tr>';
    }
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_total_requests_sent") . "\";");
    
    // Use the generic page template
    $page_title = "Total Requests Sent";
    $page_content = $content;
    require_once MYBB_ROOT . "inc/functions_user.php";
    usercp_menu();
    eval("\$page = \"" . $templates->get("threadcollaboration_generic_page") . "\";");
    output_page($page);
    exit;
}

// AJAX handler for unique inviters modal
function thread_collaboration_ajax_unique_inviters()
{
    global $mybb, $db, $lang, $templates;
    
    // Load language file
    $lang->load("thread_collaboration");
    
    $rows = '';
    
    $uid = $mybb->user['uid'];
    $query = $db->simple_select("collaboration_invitations ci, " . TABLE_PREFIX . "users u", 
        "ci.invitee_uid, u.username, COUNT(*) as total_invitations, 
         SUM(CASE WHEN ci.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN ci.status='accepted' THEN 1 ELSE 0 END) as accepted_count,
         SUM(CASE WHEN ci.status='declined' THEN 1 ELSE 0 END) as declined_count", 
        "ci.inviter_uid='{$uid}'", 
        array("group_by" => "ci.invitee_uid", "order_by" => "total_invitations DESC"));
    
    if ($db->num_rows($query) > 0) {
        while ($inviter_data = $db->fetch_array($query)) {
            $username = htmlspecialchars_uni($inviter_data['username']);
            $total_invitations = $inviter_data['total_invitations'];
            $pending_count = $inviter_data['pending_count'];
            $accepted_count = $inviter_data['accepted_count'];
            $declined_count = $inviter_data['declined_count'];
            
            $status_summary = "";
            if ($pending_count > 0) {
                $status_summary .= '<span class="pending-count">' . $pending_count . ' pending</span> ';
            }
            if ($accepted_count > 0) {
                $status_summary .= '<span class="accepted-count">' . $accepted_count . ' accepted</span> ';
            }
            if ($declined_count > 0) {
                $status_summary .= '<span class="declined-count">' . $declined_count . ' declined</span>';
            }
            
            $rows .= '<tr><td class="trow1">' . $username . '</td><td class="trow1">' . $total_invitations . '</td><td class="trow1">' . $status_summary . '</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align: center; padding: 20px; color: #666;">You haven\'t invited any users to collaborate yet.</td></tr>';
    }
    eval("\$content = \"" . $templates->get("threadcollaboration_ucp_unique_inviters") . "\";");
    echo $content;
    exit;
}

// MyBB default modal content for unique inviters
function thread_collaboration_modal_unique_inviters()
{
    global $mybb, $db, $lang, $templates;
    
    $lang->load("thread_collaboration");

    $uid = (int)$mybb->user['uid'];

    // Fetch unique inviters for the current user (people who invited me)
    $query = $db->simple_select("collaboration_invitations ci, ".TABLE_PREFIX."users u",
        "ci.inviter_uid, u.username, COUNT(*) as total_invitations, 
         SUM(CASE WHEN ci.status='pending' THEN 1 ELSE 0 END) as pending_count,
         SUM(CASE WHEN ci.status='accepted' THEN 1 ELSE 0 END) as accepted_count,
         SUM(CASE WHEN ci.status='declined' THEN 1 ELSE 0 END) as declined_count",
        "ci.invitee_uid='{$uid}' AND u.uid=ci.inviter_uid",
        array("group_by" => "ci.inviter_uid", "order_by" => "total_invitations DESC")
    );

    $rows = '';
    if($db->num_rows($query) > 0)
    {
        while($row = $db->fetch_array($query))
        {
            $username = htmlspecialchars_uni($row['username']);
            $status_summary = '';
            if((int)$row['pending_count'] > 0) $status_summary .= '<span class="pending-count">'.(int)$row['pending_count'].' pending</span> ';
            if((int)$row['accepted_count'] > 0) $status_summary .= '<span class="accepted-count">'.(int)$row['accepted_count'].' accepted</span> ';
            if((int)$row['declined_count'] > 0) $status_summary .= '<span class="declined-count">'.(int)$row['declined_count'].' declined</span>';
            $rows .= '<tr><td class="trow1">'.$username.'</td><td class="trow1">'.(int)$row['total_invitations'].'</td><td class="trow1">'.$status_summary.'</td></tr>';
        }
    }
    else
    {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align:center; padding: 20px; color:#666;">'.$lang->no_pending_invitations.'</td></tr>';
    }

    $content = '<div class="modal">'
        .'<table border="0" cellspacing="1" cellpadding="4" class="tborder">'
        .'<tr><td class="thead" colspan="3"><strong>'.htmlspecialchars_uni($lang->unique_inviters ?: 'Unique Inviters').'</strong></td></tr>'
        .'<tr>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->username ?: 'Username').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->invitations ?: 'Invitations').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->status ?: 'Status').'</strong></td>'
        .'</tr>'
        .$rows
        .'</table>';
    echo $content;
    exit;
}
// Modal for declined invitations
function thread_collaboration_modal_declined_invitations()
{
    global $mybb, $db, $lang, $templates;
    
    $lang->load("thread_collaboration");
    $uid = (int)$mybb->user['uid'];

    // Fetch declined invitations sent by current user
    $query = $db->simple_select("collaboration_invitations ci, ".TABLE_PREFIX."users u, ".TABLE_PREFIX."threads t",
        "ci.invitee_uid, u.username, ci.tid, t.subject, ci.invite_date",
        "ci.inviter_uid='{$uid}' AND ci.status='declined' AND u.uid=ci.invitee_uid AND t.tid=ci.tid",
        array("order_by" => "ci.invite_date DESC")
    );

    $rows = '';
    if($db->num_rows($query) > 0) {
        while($row = $db->fetch_array($query)) {
            $username = htmlspecialchars_uni($row['username']);
            $thread_subject = htmlspecialchars_uni($row['subject']);
            $invite_date = my_date('relative', $row['invite_date']);
            $rows .= '<tr><td class="trow1">'.$username.'</td><td class="trow1"><a href="showthread.php?tid='.$row['tid'].'">'.$thread_subject.'</a></td><td class="trow1">'.$invite_date.'</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align:center; padding: 20px; color:#666;">No declined invitations found.</td></tr>';
    }

    $content = '<div class="modal">'
        .'<table border="0" cellspacing="1" cellpadding="4" class="tborder">'
        .'<tr><td class="thead" colspan="3"><strong>'.htmlspecialchars_uni($lang->declined_invitations ?: 'Declined Invitations').'</strong></td></tr>'
        .'<tr>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->username ?: 'Username').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->thread ?: 'Thread').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->invite_date ?: 'Invite Date').'</strong></td>'
        .'</tr>'
        .$rows
        .'</table>';
    echo $content;
    exit;
}

// Modal for collaborators gained
function thread_collaboration_modal_collaborators_gained()
{
    global $mybb, $db, $lang, $templates;
    
    $lang->load("thread_collaboration");
    $uid = (int)$mybb->user['uid'];

    // Fetch accepted invitations (collaborators gained)
    $query = $db->simple_select("collaboration_invitations ci, ".TABLE_PREFIX."users u, ".TABLE_PREFIX."threads t",
        "ci.invitee_uid, u.username, ci.tid, t.subject, ci.role, ci.accept_date",
        "ci.inviter_uid='{$uid}' AND ci.status='accepted' AND u.uid=ci.invitee_uid AND t.tid=ci.tid",
        array("order_by" => "ci.accept_date DESC")
    );

    $rows = '';
    if($db->num_rows($query) > 0) {
        while($row = $db->fetch_array($query)) {
            $username = htmlspecialchars_uni($row['username']);
            $thread_subject = htmlspecialchars_uni($row['subject']);
            $role = htmlspecialchars_uni($row['role']);
            $accept_date = my_date('relative', $row['accept_date']);
            $rows .= '<tr><td class="trow1">'.$username.'</td><td class="trow1"><a href="showthread.php?tid='.$row['tid'].'">'.$thread_subject.'</a></td><td class="trow1">'.$role.'</td><td class="trow1">'.$accept_date.'</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="4" style="text-align:center; padding: 20px; color:#666;">No collaborators gained yet.</td></tr>';
    }

    $content = '<div class="modal">'
        .'<table border="0" cellspacing="1" cellpadding="4" class="tborder">'
        .'<tr><td class="thead" colspan="4"><strong>'.htmlspecialchars_uni($lang->collaborators_gained ?: 'Collaborators Gained').'</strong></td></tr>'
        .'<tr>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->username ?: 'Username').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->thread ?: 'Thread').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->role ?: 'Role').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->accept_date ?: 'Accept Date').'</strong></td>'
        .'</tr>'
        .$rows
        .'</table>';
    echo $content;
    exit;
}
// Modal for total collaborators gained (with clickable usernames)
function thread_collaboration_modal_total_collaborators_gained()
{
    global $mybb, $db, $lang, $templates;
    
    $lang->load("thread_collaboration");
    $uid = (int)$mybb->user['uid'];

    // Fetch accepted invitations (collaborators gained from invitations)
    $invitations_query = $db->simple_select("collaboration_invitations ci, ".TABLE_PREFIX."users u",
        "ci.role, ci.role_icon, ci.invitee_uid, u.username",
        "ci.inviter_uid='{$uid}' AND ci.role<>'' AND ci.role IS NOT NULL AND ci.status='accepted' AND u.uid=ci.invitee_uid",
        array("order_by" => "ci.role")
    );
    
    // Fetch approved requests (collaborators gained from requests)
    $requests_query = $db->simple_select("collaboration_requests cr, ".TABLE_PREFIX."users u",
        "cr.role, cr.role_icon, cr.requester_uid, u.username",
        "cr.thread_owner_uid='{$uid}' AND cr.role<>'' AND cr.role IS NOT NULL AND cr.status='approved' AND u.uid=cr.requester_uid",
        array("order_by" => "cr.role")
    );

    $rows = '';
    $collaborators_data = array();
    
    // Process invitations
    if($db->num_rows($invitations_query) > 0) {
        while($row = $db->fetch_array($invitations_query)) {
            $role = $row['role'];
            $role_icon = $row['role_icon'];
            $username = htmlspecialchars_uni($row['username']);
            $user_link = '<a href="member.php?action=profile&uid='.$row['invitee_uid'].'">'.$username.'</a>';
            
            if(!isset($collaborators_data[$role])) {
                $collaborators_data[$role] = array('count' => 0, 'usernames' => array(), 'icon' => $role_icon);
            }
            $collaborators_data[$role]['count']++;
            if(!in_array($user_link, $collaborators_data[$role]['usernames'])) {
                $collaborators_data[$role]['usernames'][] = $user_link;
            }
        }
    }
    
    // Process requests
    if($db->num_rows($requests_query) > 0) {
        while($row = $db->fetch_array($requests_query)) {
            $role = $row['role'];
            $role_icon = $row['role_icon'];
            $username = htmlspecialchars_uni($row['username']);
            $user_link = '<a href="member.php?action=profile&uid='.$row['requester_uid'].'">'.$username.'</a>';
            
            if(!isset($collaborators_data[$role])) {
                $collaborators_data[$role] = array('count' => 0, 'usernames' => array(), 'icon' => $role_icon);
            }
            $collaborators_data[$role]['count']++;
            if(!in_array($user_link, $collaborators_data[$role]['usernames'])) {
                $collaborators_data[$role]['usernames'][] = $user_link;
            }
        }
    }
    
    if(!empty($collaborators_data)) {
        foreach($collaborators_data as $role => $data) {
            $role_display = htmlspecialchars_uni($role);
            $role_class = strtolower(str_replace(' ', '-', $role)); // Convert "Graphic Designer" to "graphic-designer"
            $count = $data['count'];
            $usernames = implode(', ', $data['usernames']);
            
            // Build role with dynamic CSS class and icon
            $icon_html = '';
            if(!empty($data['icon'])) {
                $icon_html = '<i class="' . htmlspecialchars_uni($data['icon']) . '"></i> ';
            }
            
            $role_with_icon = '<span class="default-role ' . $role_class . '">' . $icon_html . $role_display . '</span>';
            
            $rows .= '<tr><td class="trow1">'.$role_with_icon.'</td><td class="trow1">'.$count.'</td><td class="trow1">'.$usernames.'</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align:center; padding: 20px; color:#666;">No collaborators gained yet.</td></tr>';
    }

    $content = '<div class="modal">'
        .'<table border="0" cellspacing="1" cellpadding="4" class="tborder">'
        .'<tr><td class="thead" colspan="3"><strong>'.htmlspecialchars_uni($lang->total_collaborators_gained ?: 'Total Collaborators Gained').'</strong></td></tr>'
        .'<tr>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->role ?: 'Role').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->count ?: 'Count').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->users ?: 'Users').'</strong></td>'
        .'</tr>'
        .$rows
        .'</table>';
    echo $content;
    exit;
}
// Modal for roles assigned
function thread_collaboration_modal_roles_assigned()
{
    global $mybb, $db, $lang, $templates;
    
    $lang->load("thread_collaboration");
    $uid = (int)$mybb->user['uid'];

    // Fetch all accepted invitations with roles by current user
    $invitations_query = $db->simple_select("collaboration_invitations ci, ".TABLE_PREFIX."users u",
        "ci.role, ci.role_icon, ci.invitee_uid, u.username",
        "ci.inviter_uid='{$uid}' AND ci.role<>'' AND ci.role IS NOT NULL AND ci.status='accepted' AND u.uid=ci.invitee_uid",
        array("order_by" => "ci.role")
    );
    
    // Fetch all approved requests with roles where current user is thread owner
    $requests_query = $db->simple_select("collaboration_requests cr, ".TABLE_PREFIX."users u",
        "cr.role, cr.role_icon, cr.requester_uid, u.username",
        "cr.thread_owner_uid='{$uid}' AND cr.role<>'' AND cr.role IS NOT NULL AND cr.status='approved' AND u.uid=cr.requester_uid",
        array("order_by" => "cr.role")
    );

    $rows = '';
    $roles_data = array();
    
    // Process invitations
    if($db->num_rows($invitations_query) > 0) {
        while($row = $db->fetch_array($invitations_query)) {
            $role = $row['role'];
            $role_icon = $row['role_icon'];
            $username = htmlspecialchars_uni($row['username']);
            
            if(!isset($roles_data[$role])) {
                $roles_data[$role] = array('count' => 0, 'usernames' => array(), 'icon' => $role_icon);
            }
            $roles_data[$role]['count']++;
            if(!in_array($username, $roles_data[$role]['usernames'])) {
                $roles_data[$role]['usernames'][] = $username;
            }
        }
    }
    
    // Process requests
    if($db->num_rows($requests_query) > 0) {
        while($row = $db->fetch_array($requests_query)) {
            $role = $row['role'];
            $role_icon = $row['role_icon'];
            $username = htmlspecialchars_uni($row['username']);
            
            if(!isset($roles_data[$role])) {
                $roles_data[$role] = array('count' => 0, 'usernames' => array(), 'icon' => $role_icon);
            }
            $roles_data[$role]['count']++;
            if(!in_array($username, $roles_data[$role]['usernames'])) {
                $roles_data[$role]['usernames'][] = $username;
            }
        }
    }
    
    if(!empty($roles_data)) {
        foreach($roles_data as $role => $data) {
            $role_display = htmlspecialchars_uni($role);
            $role_class = strtolower(str_replace(' ', '-', $role)); // Convert "Graphic Designer" to "graphic-designer"
            $count = $data['count'];
            $usernames = implode(', ', $data['usernames']);
            
            // Build role with dynamic CSS class and icon
            $icon_html = '';
            if(!empty($data['icon'])) {
                $icon_html = '<i class="' . htmlspecialchars_uni($data['icon']) . '"></i> ';
            }
            
            $role_with_icon = '<span class="default-role ' . $role_class . '">' . $icon_html . $role_display . '</span>';
            
            $rows .= '<tr><td class="trow1">'.$role_with_icon.'</td><td class="trow1">'.$count.'</td><td class="trow1">'.$usernames.'</td></tr>';
        }
    } else {
        $rows = '<tr><td class="trow1" colspan="3" style="text-align:center; padding: 20px; color:#666;">No roles assigned yet.</td></tr>';
    }

    $content = '<div class="modal">'
        .'<table border="0" cellspacing="1" cellpadding="4" class="tborder">'
        .'<tr><td class="thead" colspan="3"><strong>'.htmlspecialchars_uni($lang->roles_assigned ?: 'Roles Assigned').'</strong></td></tr>'
        .'<tr>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->role ?: 'Role').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->count ?: 'Count').'</strong></td>'
            .'<td class="tcat"><strong>'.htmlspecialchars_uni($lang->users ?: 'Users').'</strong></td>'
        .'</tr>'
        .$rows
        .'</table>';
    echo $content;
    exit;
}
// Admin tasks hook
function thread_collaboration_admin_tasks(&$task)
{
    // Add cleanup tasks for expired invitations and requests
    $task['thread_collaboration_cleanup'] = array(
        'title' => 'Thread Collaboration Cleanup',
        'description' => 'Clean up expired collaboration invitations and requests.',
        'file' => 'thread_collaboration_cleanup',
        'logfile' => 'thread_collaboration_cleanup',
        'minute' => '0',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'weekday' => '*'
    );
}

// Admin config settings hooks
function thread_collaboration_admin_config_settings_start()
{
    global $mybb, $db;
    // Ensure Owner Role Visibility renders as a select with proper options
    $setting = $db->simple_select('settings', 'sid, optionscode, value', "name='thread_collaboration_show_owner_role_mode'");
    if($setting && ($row = $db->fetch_array($setting)))
    {
        $expected = "select\nalways=Always show\nwhen_collaborators=Show only when collaborators exist";
        $needs_update = ($row['optionscode'] !== $expected);
        $current_value = (string)$row['value'];
        if($current_value !== 'always' && $current_value !== 'when_collaborators')
        {
            $needs_update = true; // also normalize invalid/legacy value
            $db->update_query('settings', array('value' => 'always'), "sid='".$db->escape_string($row['sid'])."'");
        }
        if($needs_update)
        {
            $db->update_query('settings', array('optionscode' => $db->escape_string($expected)), "sid='".$db->escape_string($row['sid'])."'");
            if(function_exists('rebuild_settings') === false)
            {
                require_once MYBB_ROOT.'inc/functions.php';
            }
            rebuild_settings();
        }
    }
}

function thread_collaboration_admin_config_settings_change()
{
    global $mybb;
    // This function can be used to handle settings changes if needed
}

// Member profile hook to show collaboration information
function thread_collaboration_member_profile_end()
{
    global $mybb, $db, $templates, $memprofile, $collaboration_profile_info;
    
    // Only show for users who are currently collaborators
    $user_id = (int)$memprofile['uid'];
    if (!$user_id) {
        return;
    }
    
    // Check if user is currently a collaborator on any thread OR owns threads with collaborators
    $query = $db->simple_select("thread_collaborators", "COUNT(*) as count", "uid='{$user_id}'");
    $collaboration_count = $db->fetch_field($query, 'count');
    
    // Also check if user owns threads that have collaborators
    $query = $db->query("
        SELECT COUNT(*) as count 
        FROM " . TABLE_PREFIX . "threads t 
        INNER JOIN " . TABLE_PREFIX . "thread_collaborators tc ON t.tid = tc.tid 
        WHERE t.uid='{$user_id}'
    ");
    $owner_with_collaborators_count = $db->fetch_field($query, 'count');
    
    if ($collaboration_count == 0 && $owner_with_collaborators_count == 0) {
        return; // Don't show the table if user is not a collaborator and doesn't own threads with collaborators
    }
    
    // Get collaboration data
    $collaboration_data = thread_collaboration_get_user_collaboration_data($user_id);
    
    // Compute unique threads and total collaborations (unique threads)
    $unique_threads = array();
    if (!empty($collaboration_data['threads'])) {
        foreach ($collaboration_data['threads'] as $t) {
            $tid_key = (int)$t['tid'];
            if (!isset($unique_threads[$tid_key])) {
                $unique_threads[$tid_key] = $t['subject'];
            }
        }
    }
    $total_collaborations = count($unique_threads);
    // Set template var for Total Collaborations
    $collaboration_count = $total_collaborations;
    
    // Format current roles with icons and CSS classes (same styling as showthread page)
    $current_roles = '';
    if (!empty($collaboration_data['roles'])) {
        $role_parts = array();
        foreach ($collaboration_data['roles'] as $role) {
            $role_name = htmlspecialchars_uni($role['role']);
            $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
            
            $icon_html = '';
            if (!empty($role['role_icon'])) {
                $icon_html = ' <i class="' . htmlspecialchars_uni($role['role_icon']) . '"></i>'; 
            }
            
            // Wrap each role in a span with dynamic class and default class (same as showthread)
            $role_parts[] = '<span class="default-role ' . $role_class . '">' . $role_name . $icon_html . '</span>';
        }
        $current_roles = implode(', ', $role_parts);
    } else {
        $current_roles = 'None';
    }
    
    // Determine collaboration mode
    $collaboration_mode = 'Mixed';
    if ($collaboration_data['invited_count'] > 0 && $collaboration_data['requested_count'] == 0) {
        $collaboration_mode = 'Invited';
    } elseif ($collaboration_data['requested_count'] > 0 && $collaboration_data['invited_count'] == 0) {
        // Check if this is owner collaborations or actual requests
        $is_owner_collaboration = false;
        $query = $db->query("
            SELECT COUNT(*) as count 
            FROM " . TABLE_PREFIX . "threads t 
            INNER JOIN " . TABLE_PREFIX . "thread_collaborators tc ON t.tid = tc.tid 
            WHERE t.uid='{$user_id}'
        ");
        $owner_collaboration_count = $db->fetch_field($query, 'count');
        if ($owner_collaboration_count > 0) {
            $is_owner_collaboration = true;
        }
        
        if ($is_owner_collaboration) {
            $collaboration_mode = 'Owner';
        } else {
            $collaboration_mode = 'Requested';
        }
    }
    
    // Format active threads (unique only)
    $active_threads = '';
    if (!empty($unique_threads)) {
        $thread_parts = array();
        foreach ($unique_threads as $tid => $subject) {
            $thread_parts[] = '<a href="showthread.php?tid=' . (int)$tid . '">' . htmlspecialchars_uni($subject) . '</a>';
        }
        $active_threads = implode(', ', $thread_parts);
    } else {
        $active_threads = 'None';
    }
    
    // Parse the template
    eval("\$collaboration_profile_info = \"" . $templates->get("threadcollaboration_profile_info") . "\";");
}

// Helper function to get user collaboration data
function thread_collaboration_get_user_collaboration_data($user_id)
{
    global $db;
    
    $user_id = (int)$user_id;
    $data = array(
        'roles' => array(),
        'threads' => array(),
        'invited_count' => 0,
        'requested_count' => 0
    );
    
    // Get current collaborations with roles (where user is a collaborator)
    $query = $db->query("
        SELECT tc.role, tc.role_icon, tc.tid, t.subject 
        FROM " . TABLE_PREFIX . "thread_collaborators tc 
        LEFT JOIN " . TABLE_PREFIX . "threads t ON tc.tid = t.tid 
        WHERE tc.uid='{$user_id}' AND t.tid IS NOT NULL
    ");
    
    if ($query && $db->num_rows($query) > 0) {
        while ($collaboration = $db->fetch_array($query)) {
            $data['roles'][] = array(
                'role' => $collaboration['role'],
                'role_icon' => $collaboration['role_icon']
            );
            $data['threads'][] = array(
                'tid' => $collaboration['tid'],
                'subject' => $collaboration['subject']
            );
        }
    }
    
    // Also get threads where user is the owner and has collaborators (add "Owner" role)
    $query = $db->query("
        SELECT t.tid, t.subject 
        FROM " . TABLE_PREFIX . "threads t 
        INNER JOIN " . TABLE_PREFIX . "thread_collaborators tc ON t.tid = tc.tid 
        WHERE t.uid='{$user_id}'
    ");
    
    if ($query && $db->num_rows($query) > 0) {
        while ($thread = $db->fetch_array($query)) {
            // Check if this thread is not already in the threads array (to avoid duplicates)
            $thread_exists = false;
            foreach ($data['threads'] as $existing_thread) {
                if ($existing_thread['tid'] == $thread['tid']) {
                    $thread_exists = true;
                    break;
                }
            }
            
            if (!$thread_exists) {
                // Add "Owner" role for threads where user is owner and has collaborators
                $data['roles'][] = array(
                    'role' => 'Owner',
                    'role_icon' => 'fas fa-crown' // Default owner icon
                );
                $data['threads'][] = array(
                    'tid' => $thread['tid'],
                    'subject' => $thread['subject']
                );
            }
        }
    }
    
    // Count invitations (where user was invited and accepted)
    $query = $db->simple_select("collaboration_invitations", "COUNT(*) as count", 
        "invitee_uid='{$user_id}' AND status='accepted'");
    $data['invited_count'] = $db->fetch_field($query, 'count');
    
    // Count requests (where user requested and was approved)
    $query = $db->simple_select("collaboration_requests", "COUNT(*) as count", 
        "requester_uid='{$user_id}' AND status='approved'");
    $data['requested_count'] = $db->fetch_field($query, 'count');
    
    // Count threads where user is owner and has collaborators (for owner collaboration mode)
    $query = $db->query("
        SELECT COUNT(*) as count 
        FROM " . TABLE_PREFIX . "threads t 
        INNER JOIN " . TABLE_PREFIX . "thread_collaborators tc ON t.tid = tc.tid 
        WHERE t.uid='{$user_id}'
    ");
    $owner_collaboration_count = $db->fetch_field($query, 'count');
    
    // Add owner collaboration count to the appropriate counter
    if ($owner_collaboration_count > 0) {
        // If user has both invited and owner collaborations, show as "Mixed"
        // If user only has owner collaborations, show as "Owner"
        if ($data['invited_count'] == 0 && $data['requested_count'] == 0) {
            $data['requested_count'] = $owner_collaboration_count; // Use requested_count to represent owner collaborations
        }
    }
    
    return $data;
}
?>