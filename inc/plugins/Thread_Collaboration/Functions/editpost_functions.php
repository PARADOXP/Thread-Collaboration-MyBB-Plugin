<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!function_exists('thread_collaboration_editpost_action_start'))
{
function thread_collaboration_editpost_action_start()
{
    global $mybb, $db, $thread, $post, $templates, $collaboration_fields, $lang;
    $lang->load("thread_collaboration");

    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
    if (!$mybb->user['uid'] || !in_array($mybb->user['usergroup'], $allowed_groups)) { return; }
    if (!thread_collaboration_is_forum_allowed($thread['fid'])) { return; }

    $mod_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) { $mod_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']); }
    $can_manage_collabs = ($mybb->user['uid'] == $thread['uid']) || is_moderator($thread['fid']) || (!empty($mod_groups) && in_array($mybb->user['usergroup'], $mod_groups));

    $management_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) { $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']); }
    $is_management_user = in_array($mybb->user['usergroup'], $management_groups);

    if ($post['pid'] == $thread['firstpost'] && $can_manage_collabs)
    {
        $default_roles = array();
        $default_roles_text = $mybb->settings['thread_collaboration_default_roles'];
        if (!empty($default_roles_text)) {
            $roles_lines = explode("\n", $default_roles_text);
            foreach ($roles_lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $parts = explode('|', $line);
                    if (count($parts) >= 2) {
                        $default_roles[] = array('name' => trim($parts[0]), 'icon' => trim($parts[1]));
                    }
                }
            }
        }

        // For editpost, always show empty fields for adding NEW collaborators
        // Don't pre-fill with existing collaborators - this is for inviting MORE people
        $collaborator_fields_content = '';
        
        // Add one empty field for new collaborator
        eval("\$template_content = \"" . $templates->get("threadcollaboration_input_field") . "\";");
        $collaborator_fields_content .= $template_content;

        $manager_invite_as_owner_toggle = '';
        if ($is_management_user && $mybb->settings['thread_collaboration_manager_invite_as_owner'] == '1' && (int)$thread['uid'] !== (int)$mybb->user['uid']) {
            $manager_invite_as_owner_toggle = '<div class="thread_collaboration_management_toggle" style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">'
                .'<label style="display: flex; align-items: center; font-weight: bold; color: #333;">'
                .'<input type="checkbox" name="invite_as_owner" value="1" style="margin-right: 8px;" />'
                .'Invite collaborators as thread owner (instead of as yourself)'
                .'</label>'
                .'<small style="display: block; margin-top: 5px; color: #666;">When checked, invitations will appear to come from the thread creator. When unchecked, invitations will show you as the inviter.</small>'
                .'</div>';
        }

        $default_roles_json = json_encode($default_roles);

        // Load external JS asset and pass roles via a global variable
        $script_url = $mybb->settings['bburl'] . '/inc/plugins/Thread_Collaboration/Assets/editpost_collaboration.js';
        $collaboration_javascript = '<script type="text/javascript">window.threadCollabDefaultRoles = ' . $default_roles_json . ';</script>'
            . '<script type="text/javascript" src="' . htmlspecialchars_uni($script_url) . '"></script>';

        eval("\$container_content = \"" . $templates->get("threadcollaboration_input_container") . "\";");
        $container_content = str_replace('{$collaborator_fields_content}', $collaborator_fields_content, $container_content);
        $container_content = str_replace('{$collaboration_javascript}', $collaboration_javascript, $container_content);
        $container_content = str_replace('{$default_roles_json}', $default_roles_json, $container_content);
        $container_content = str_replace('{$manager_invite_as_owner_toggle}', $manager_invite_as_owner_toggle, $container_content);

        $collaboration_fields = $container_content;
    }
}
}

if (!function_exists('thread_collaboration_editpost_do_editpost_start'))
{
function thread_collaboration_editpost_do_editpost_start()
{
    global $mybb, $db, $thread, $post, $lang;
    $lang->load("thread_collaboration");

    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
    if (!$mybb->user['uid'] || !in_array($mybb->user['usergroup'], $allowed_groups)) { 
        return; 
    }
    if (!thread_collaboration_is_forum_allowed($thread['fid'])) { 
        return; 
    }

    if ((int)$mybb->settings['thread_collaboration_require_approval_for_protected'] === 1)
    {
        if (!empty($post['pid']) && !empty($thread['tid']))
        {
            $owner_uid = (int)$post['uid'];
            $owner = $db->fetch_array($db->simple_select('users', 'uid,usergroup,additionalgroups', "uid='".$owner_uid."'", array('limit' => 1)));

            $protected = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups']))
            {
                foreach (explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']) as $gid)
                {
                    $gid = (int)trim($gid);
                    if ($gid > 0) { $protected[] = $gid; }
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
            if(function_exists('thread_collaboration_is_user_collaborator')) { $owner_is_collab = thread_collaboration_is_user_collaborator($owner_uid, $thread['tid']); }
            if((int)$owner_uid === (int)$thread['uid']) { $owner_is_collab = true; }
            $editor_is_collab = function_exists('thread_collaboration_is_user_collaborator') ? thread_collaboration_is_user_collaborator($mybb->user['uid'], $thread['tid']) : false;

            if ($is_owner_protected && !$is_editor_protected && $owner_is_collab && $editor_is_collab && (int)$mybb->user['uid'] !== $owner_uid)
            {
                $edited_message = isset($mybb->input['message']) ? $mybb->get_input('message') : '';
                $edited_subject = '';
                if ((int)$post['pid'] === (int)$thread['firstpost'] && isset($mybb->input['subject'])) { $edited_subject = $mybb->get_input('subject'); }

                $draft_payload = json_encode(array(
                    'tid' => (int)$thread['tid'],
                    'pid' => (int)$post['pid'],
                    'editor_uid' => (int)$mybb->user['uid'],
                    'edited_subject' => $edited_subject,
                    'edited_message' => $edited_message,
                    'original_subject' => $thread['subject'],
                    'original_message' => $post['message']
                ));
                
                try {
                    $db->insert_query('collab_post_edits', array(
                        'tid' => (int)$thread['tid'],
                        'pid' => (int)$post['pid'],
                        'editor_uid' => (int)$mybb->user['uid'],
                        'owner_uid' => $owner_uid,
                        'draft' => $db->escape_string($draft_payload),
                        'status' => 'pending',
                        'dateline' => TIME_NOW
                    ));
                } catch (Exception $e) { error_log('Thread Collaboration: Failed to insert draft: ' . $e->getMessage()); }

                require_once MYBB_ROOT . "inc/datahandlers/pm.php";
                try {
                    $editor_username = $mybb->user['username'];
                    $subject_txt = !empty($lang->collab_edit_pm_subject) ? $lang->sprintf($lang->collab_edit_pm_subject, htmlspecialchars_uni($editor_username), htmlspecialchars_uni($thread['subject'])) : $editor_username.' edited your post in thread "'.htmlspecialchars_uni($thread['subject']).'"';
                    $message_txt = !empty($lang->collab_edit_pm_message) ? $lang->sprintf($lang->collab_edit_pm_message, htmlspecialchars_uni($editor_username)) : 'A collaborator ('.htmlspecialchars_uni($editor_username).') edited your post. Review and approve/reject it in User CP → Collaboration Settings.';
                    $pm = array('subject' => $subject_txt,'message' => $message_txt,'icon' => '', 'toid' => array($owner_uid), 'fromid' => (int)$mybb->user['uid'], 'do' => '', 'pmid' => '');
                    $pmhandler = new PMDataHandler(); $pmhandler->set_data($pm); if($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
                } catch (Exception $e) {}

                require_once MYBB_ROOT."inc/class_parser.php";
                $parser = new postParser;
                $parser_options = array(
                    'allow_html' => (int)$GLOBALS['forum']['allowhtml'],
                    'allow_mycode' => (int)$GLOBALS['forum']['allowmycode'],
                    'allow_smilies' => (int)$GLOBALS['forum']['allowsmilies'],
                    'allow_imgcode' => (int)$GLOBALS['forum']['allowimgcode'],
                    'allow_videocode' => (int)$GLOBALS['forum']['allowvideocode'],
                    'me_username' => $post['username'],
                    'filter_badwords' => 1
                );
                if($post['smilieoff'] == 1) { $parser_options['allow_smilies'] = 0; }
                if($mybb->user['uid'] != 0 && $mybb->user['showimages'] != 1 || $mybb->settings['guestimages'] != 1 && $mybb->user['uid'] == 0) { $parser_options['allow_imgcode'] = 0; }
                if($mybb->user['uid'] != 0 && $mybb->user['showvideos'] != 1 || $mybb->settings['guestvideos'] != 1 && $mybb->user['uid'] == 0) { $parser_options['allow_videocode'] = 0; }
                $rendered_message = $parser->parse_message($post['message'], $parser_options);

                $pending_note = !empty($lang->collab_edit_saved_as_draft) ? $lang->collab_edit_saved_as_draft : 'Your edit was saved as a draft and sent to the post owner for approval.';
                redirect(get_post_link($post['pid']), $pending_note);
            }
        }
    }

    // If we reach here, proceed with collaboration invitation/direct-collaborator processing from editpost UI
    // Normalize incoming arrays
    $raw_usernames = array_values((array)$mybb->get_input('collaborator_username', MyBB::INPUT_ARRAY));
    $raw_roles = array_values((array)$mybb->get_input('collaborator_role', MyBB::INPUT_ARRAY));
    $raw_role_icons = array_values((array)$mybb->get_input('collaborator_role_icon', MyBB::INPUT_ARRAY));
    $raw_addl_roles = (array)$mybb->get_input('collaborator_additional_role', MyBB::INPUT_ARRAY);
    $raw_addl_icons = (array)$mybb->get_input('collaborator_additional_role_icon', MyBB::INPUT_ARRAY);

    $processed_usernames = array();
    $processed_roles = array();
    $processed_role_icons = array();

    $count_users = count($raw_usernames);
    for ($i = 0; $i < $count_users; $i++) {
        $username = isset($raw_usernames[$i]) ? trim($raw_usernames[$i]) : '';
        if ($username === '') { continue; }
        if (!empty($raw_roles[$i])) {
            $processed_usernames[] = $username;
            $processed_roles[] = $raw_roles[$i];
            $processed_role_icons[] = isset($raw_role_icons[$i]) ? $raw_role_icons[$i] : '';
        }
        if (isset($raw_addl_roles[$i]) && is_array($raw_addl_roles[$i])) {
            foreach ($raw_addl_roles[$i] as $r => $additional_role) {
                if (!empty($additional_role)) {
                    $processed_usernames[] = $username;
                    $processed_roles[] = $additional_role;
                    $processed_role_icons[] = isset($raw_addl_icons[$i][$r]) ? $raw_addl_icons[$i][$r] : '';
                }
            }
        }
    }

    // Early exit if nothing to process
    if (empty($processed_usernames)) { return; }

    // Determine manager invite-as-owner flag
    $management_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
        $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
    }
    $is_management_user = in_array($mybb->user['usergroup'], $management_groups);
    $invite_as_owner = false;
    if ($is_management_user && $mybb->settings['thread_collaboration_manager_invite_as_owner'] == '1') {
        $invite_as_owner = isset($mybb->input['invite_as_owner']) && $mybb->input['invite_as_owner'] == '1';
    }

    // Ensure thread subject present
    $thread_subject = $thread['subject'];
    if (empty($thread_subject)) {
        $tq = $db->simple_select('threads', 'subject', "tid='".(int)$thread['tid']."'", array('limit' => 1));
        $tr = $db->fetch_array($tq); if ($tr && !empty($tr['subject'])) { $thread_subject = $tr['subject']; }
    }

    // Dispatch to invitation/direct processing
    if ($mybb->settings['thread_collaboration_invitation_system'] == '1') {
        thread_collaboration_process_edit_invitations($processed_usernames, $processed_roles, $processed_role_icons, $thread['tid'], $thread_subject, $thread['fid'], $invite_as_owner);
    } else {
        thread_collaboration_process_edit_direct_collaborators($processed_usernames, $processed_roles, $processed_role_icons, $thread['tid'], $thread_subject, $invite_as_owner);
    }
}
}


