<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!function_exists('thread_collaboration_newthread_start'))
{
function thread_collaboration_newthread_start()
{
    global $mybb, $templates, $collaboration_fields, $lang;
    $lang->load("thread_collaboration");

    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
    if (!$mybb->user['uid'] || !in_array($mybb->user['usergroup'], $allowed_groups)) { return; }
    if (!thread_collaboration_is_forum_allowed()) { return; }

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

    eval("\$collaborator_fields_content = \"" . $templates->get("threadcollaboration_input_field") . "\";");

    $additional_role_row_template = '<div class="additional-role-row">'
        .'<label>Additional Role: <input type="text" name="collaborator_additional_role[]" class="textbox collaborator_additional_role" style="width: 200px;" /></label>'
        .'<label class="additional-icon-field-label" style="display: none;">Icon: <input type="text" name="collaborator_additional_role_icon[]" class="textbox collaborator_additional_role_icon" style="width: 120px;" placeholder="fas fa-icon" /></label>'
        .'<button type="button" class="add_additional_role">Add More</button>'
        .'<button type="button" class="remove_additional_role" style="display: none;">Remove</button>'
        .'</div>';

    // Load external JS asset and pass roles via a global variable
    $script_url = $mybb->settings['bburl'] . '/inc/plugins/Thread_Collaboration/Assets/newthread_collaboration.js';
    $collaboration_javascript = '<script type="text/javascript">window.threadCollabDefaultRoles = ' . json_encode($default_roles) . ';</script>'
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($script_url) . '"></script>';

    eval("\$container_content = \"" . $templates->get("threadcollaboration_input_container") . "\";");
    $container_content = str_replace('{$collaborator_fields_content}', $collaborator_fields_content, $container_content);
    $container_content = str_replace('{$collaboration_javascript}', $collaboration_javascript, $container_content);
    $container_content = str_replace('{$default_roles_json}', json_encode($default_roles), $container_content);
    
    $collaboration_fields = $container_content;
}
}

if (!function_exists('thread_collaboration_newthread_do_newthread_end'))
{
function thread_collaboration_newthread_do_newthread_end()
{
    global $mybb, $db, $thread_info, $lang;
    $lang->load("thread_collaboration");

    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
    if ($mybb->request_method != "post" || !$mybb->user['uid'] || !isset($thread_info['tid']) || !in_array($mybb->user['usergroup'], $allowed_groups)) { return; }
    if (!thread_collaboration_is_forum_allowed($thread_info['fid'])) { return; }

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

    $collaborator_usernames = $processed_usernames;
    $collaborator_roles = $processed_roles;
    $collaborator_role_icons = $processed_role_icons;

    if (empty($thread_info['subject'])) {
        try {
            $thread_query = $db->simple_select("threads", "subject", "tid='".(int)$thread_info['tid']."'", array('limit' => 1));
            $thread_data = $db->fetch_array($thread_query);
            if ($thread_data && !empty($thread_data['subject'])) { $thread_info['subject'] = $thread_data['subject']; }
        } catch (Exception $e) { }
    }

    if ($mybb->settings['thread_collaboration_invitation_system'] == '1') {
        $invite_as_owner = false;
        if ($mybb->user['uid'] == $thread_info['uid']) { $invite_as_owner = false; }
        else {
            $management_groups = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) { $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']); }
            if (in_array($mybb->user['usergroup'], $management_groups) && $mybb->settings['thread_collaboration_manager_invite_as_owner'] == '1') {
                $invite_as_owner = isset($mybb->input['invite_as_owner']) && $mybb->input['invite_as_owner'] == '1';
            }
        }
        thread_collaboration_process_invitations($collaborator_usernames, $collaborator_roles, $collaborator_role_icons, $thread_info['tid'], $thread_info['subject'], $thread_info['fid'], $invite_as_owner);
    } else {
        $invite_as_owner = false;
        if ($mybb->user['uid'] == $thread_info['uid']) { $invite_as_owner = false; }
        else {
            $management_groups = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) { $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']); }
            if (in_array($mybb->user['usergroup'], $management_groups) && $mybb->settings['thread_collaboration_manager_invite_as_owner'] == '1') {
                $invite_as_owner = isset($mybb->input['invite_as_owner']) && $mybb->input['invite_as_owner'] == '1';
            }
        }
        thread_collaboration_process_direct_collaborators($collaborator_usernames, $collaborator_roles, $collaborator_role_icons, $thread_info['tid'], $thread_info['subject'], $invite_as_owner);
    }
}
}


