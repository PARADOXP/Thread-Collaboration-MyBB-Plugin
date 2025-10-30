<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Editpost: process edit invitations
if (!function_exists('thread_collaboration_process_edit_invitations'))
{
function thread_collaboration_process_edit_invitations($usernames, $roles, $role_icons, $thread_id, $thread_subject, $forum_id, $invite_as_owner = false)
{
    global $mybb, $db, $lang;
    
    if (!is_array($usernames) || !is_array($roles) || !is_array($role_icons)) {
        error_log("Thread Collaboration: Invalid edit invitation data arrays for thread " . $thread_id);
        return;
    }
    
    $insert_invitations = array();
    $errors = array();
    $max_collaborators = (int)$mybb->settings['thread_collaboration_max_collaborators'];
    
    foreach ($usernames as $key => $username) {
        $username = trim($username);
        $role = trim($roles[$key]);
        $role_icon = trim($role_icons[$key]);
        
        if (!empty($username)) {
            if ($max_collaborators > 0 && count($insert_invitations) >= $max_collaborators) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_max_collaborators, $max_collaborators);
                break;
            }
            if (strlen($username) < 3 || strlen($username) > 50) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_username, htmlspecialchars_uni($username));
                continue;
            }
            if (strlen($role) < 1 || strlen($role) > 100) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_role, htmlspecialchars_uni($role));
                continue;
            }
            if (!empty($role_icon) && (strlen($role_icon) < 1 || strlen($role_icon) > 100)) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_icon, htmlspecialchars_uni($role_icon));
                continue;
            }
            
            $query = $db->simple_select("users", "uid", "username='".$db->escape_string($username)."'", array('limit' => 1));
            $user = $db->fetch_array($query);
            
            if ($user['uid']) {
                if ($user['uid'] == $mybb->user['uid']) {
                    $errors[] = $lang->sprintf($lang->thread_collaboration_error_self_invitation, htmlspecialchars_uni($username));
                    continue;
                }
                
                // Check user thread limit
                if (!thread_collaboration_check_user_thread_limit($user['uid'])) {
                    $max_threads = (int)$mybb->settings['thread_collaboration_max_threads_per_user'];
                    $current_threads = thread_collaboration_get_user_thread_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_threads} threads. Current: {$current_threads}";
                    continue;
                }
                
                // Check user role limit
                if (!thread_collaboration_check_user_role_limit($user['uid'])) {
                    $max_roles = (int)$mybb->settings['thread_collaboration_max_roles_per_user'];
                    $current_roles = thread_collaboration_get_user_role_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_roles} different roles. Current: {$current_roles}";
                    continue;
                }
                $existing_invitation = $db->simple_select("collaboration_invitations", "invite_id", "tid='{$thread_id}' AND invitee_uid='{$user['uid']}' AND status='pending'", array("limit" => 1));
                if ($existing_invitation && $db->num_rows($existing_invitation) > 0) {
                    $errors[] = $lang->sprintf($lang->thread_collaboration_error_invitation_exists, htmlspecialchars_uni($username));
                    continue;
                }
                $existing_query = $db->simple_select("thread_collaborators", "uid", "tid='{$thread_id}' AND uid='{$user['uid']}'", array('limit' => 1));
                if ($db->num_rows($existing_query) > 0) {
                    $errors[] = $lang->sprintf($lang->thread_collaboration_error_already_collaborator, htmlspecialchars_uni($username));
                    continue;
                }
                
                $inviter_uid = $mybb->user['uid'];
                if ($invite_as_owner) {
                    $thread_query = $db->simple_select("threads", "uid", "tid='{$thread_id}'", array('limit' => 1));
                    $thread_data = $db->fetch_array($thread_query);
                    if ($thread_data && $thread_data['uid']) {
                        $inviter_uid = $thread_data['uid'];
                    }
                }
                
                $insert_invitations[] = array(
                    "tid" => $thread_id,
                    "inviter_uid" => $inviter_uid,
                    "invitee_uid" => $user['uid'],
                    "role" => $db->escape_string($role),
                    "role_icon" => $db->escape_string($role_icon),
                    "status" => 'pending',
                    "invite_date" => TIME_NOW
                );
            } else {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_user_not_found, htmlspecialchars_uni($username));
            }
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            error($error);
        }
    }
    
    if (!empty($insert_invitations)) {
        try {
            $db->insert_query_multiple("collaboration_invitations", $insert_invitations);
            error_log("Thread Collaboration: Successfully inserted " . count($insert_invitations) . " edit invitations for thread " . $thread_id);
            $pm_sent_count = thread_collaboration_send_invitation_notifications($insert_invitations, $thread_id, $thread_subject);
            if ($pm_sent_count > 0) {
                error_log("Thread Collaboration: Successfully sent " . count($insert_invitations) . " edit invitation PMs for thread " . $thread_id);
            }
        } catch (Exception $e) {
            error($lang->sprintf($lang->thread_collaboration_error_db, $e->getMessage()));
        }
    }
}
}

// Editpost: process edit direct collaborators (legacy)
if (!function_exists('thread_collaboration_process_edit_direct_collaborators'))
{
function thread_collaboration_process_edit_direct_collaborators($usernames, $roles, $role_icons, $thread_id, $thread_subject, $invite_as_owner = false)
{
    global $mybb, $db, $lang;
    
    if (!is_array($usernames) || !is_array($roles) || !is_array($role_icons)) {
        error_log("Thread Collaboration: Invalid edit collaborator data arrays for thread " . $thread_id);
        return;
    }
    
    try {
        $db->delete_query("thread_collaborators", "tid='{$thread_id}'");
    } catch (Exception $e) {
        error($lang->sprintf($lang->thread_collaboration_error_db, $e->getMessage()));
        return;
    }
    
    $insert_collaborators = array();
    $errors = array();
    $max_collaborators = (int)$mybb->settings['thread_collaboration_max_collaborators'];
            
    foreach ($usernames as $key => $username) {
        $username = trim($username);
        $role = trim($roles[$key]);
        $role_icon = trim($role_icons[$key]);

        if (!empty($username)) {
            if ($max_collaborators > 0 && count($insert_collaborators) >= $max_collaborators) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_max_collaborators, $max_collaborators);
                break;
            }
            if (strlen($username) < 3 || strlen($username) > 50) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_username, htmlspecialchars_uni($username));
                continue;
            }
            if (strlen($role) < 1 || strlen($role) > 100) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_role, htmlspecialchars_uni($role));
                continue;
            }
            if (!empty($role_icon) && (strlen($role_icon) < 1 || strlen($role_icon) > 100)) {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_icon, htmlspecialchars_uni($role_icon));
                continue;
            }
            
            $query = $db->simple_select("users", "uid", "username='".$db->escape_string($username)."'", array('limit' => 1));
            $user = $db->fetch_array($query);

            if ($user['uid']) {
                if ($user['uid'] == $mybb->user['uid']) {
                    $errors[] = $lang->sprintf($lang->thread_collaboration_error_self_invitation, htmlspecialchars_uni($username));
                    continue;
                }
                
                $insert_collaborators[] = array(
                    "tid" => $thread_id,
                    "uid" => $user['uid'],
                    "role" => $db->escape_string($role),
                    "role_icon" => $db->escape_string($role_icon),
                    "joined_date" => TIME_NOW,
                    "joined_via" => 'direct',
                    "source_id" => null
                );
            } else {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_user_not_found, htmlspecialchars_uni($username));
            }
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            error($error);
        }
    }

    if (!empty($insert_collaborators)) {
        try {
            $db->insert_query_multiple("thread_collaborators", $insert_collaborators);
            error_log("Thread Collaboration: Successfully inserted " . count($insert_collaborators) . " edit collaborators for thread " . $thread_id);
            $pm_sent_count = thread_collaboration_send_pm_notifications($insert_collaborators, $thread_id, $thread_subject, $mybb->user['uid'], $mybb->user['username'], true);
            if ($pm_sent_count > 0) {
                error_log("Thread Collaboration: Successfully updated " . count($insert_collaborators) . " collaborators and sent " . $pm_sent_count . " PM notifications for thread " . $thread_id);
            } else {
                error_log("Thread Collaboration: Successfully updated " . count($insert_collaborators) . " collaborators for thread " . $thread_id . " (PM notifications disabled)");
            }
        } catch (Exception $e) {
            error($lang->sprintf($lang->thread_collaboration_error_db, $e->getMessage()));
        }
    }
}
}

// Newthread: process invitations
if (!function_exists('thread_collaboration_process_invitations'))
{
function thread_collaboration_process_invitations($usernames, $roles, $role_icons, $thread_id, $thread_subject, $forum_id, $invite_as_owner = false)
{
    global $mybb, $db, $lang;
    
    $unique_invited_uids = array();
    $existing_roles_by_user = array();
    $new_roles_by_user = array();
    $errors = array();
    $insert_invitations = array();
    $max_collaborators = (int)$mybb->settings['thread_collaboration_max_collaborators'];
    
    foreach ($usernames as $key => $username) {
        $username = trim($username);
        $role = trim($roles[$key]);
        $role_icon = trim($role_icons[$key]);
        
        if (!empty($username)) {
            if (strlen($username) < 3 || strlen($username) > 50) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_username, htmlspecialchars_uni($username)); continue; }
            if (strlen($role) < 1 || strlen($role) > 100) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_role, htmlspecialchars_uni($role)); continue; }
            if (!empty($role_icon) && (strlen($role_icon) < 1 || strlen($role_icon) > 100)) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_icon, htmlspecialchars_uni($role_icon)); continue; }
            
            $query = $db->simple_select("users", "uid", "username='".$db->escape_string($username)."'", array('limit' => 1));
            $user = $db->fetch_array($query);
            
            if ($user['uid']) {
                if ($user['uid'] == $mybb->user['uid']) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_self_invitation, htmlspecialchars_uni($username)); continue; }
                
                // Check user thread limit
                if (!thread_collaboration_check_user_thread_limit($user['uid'])) {
                    $max_threads = (int)$mybb->settings['thread_collaboration_max_threads_per_user'];
                    $current_threads = thread_collaboration_get_user_thread_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_threads} threads. Current: {$current_threads}";
                    continue;
                }
                
                // Check user role limit
                if (!thread_collaboration_check_user_role_limit($user['uid'])) {
                    $max_roles = (int)$mybb->settings['thread_collaboration_max_roles_per_user'];
                    $current_roles = thread_collaboration_get_user_role_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_roles} different roles. Current: {$current_roles}";
                    continue;
                }
                
                $existing_query = $db->simple_select("thread_collaborators", "uid", "tid='{$thread_id}' AND uid='{$user['uid']}'", array('limit' => 1));
                if ($db->num_rows($existing_query) > 0) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_already_collaborator, htmlspecialchars_uni($username)); continue; }
                if (!isset($existing_roles_by_user[$user['uid']])) {
                    $existing_roles_by_user[$user['uid']] = array();
                    $pend_q = $db->simple_select("collaboration_invitations", "role", "tid='{$thread_id}' AND invitee_uid='{$user['uid']}' AND status='pending'");
                    while ($pr = $db->fetch_array($pend_q)) { $existing_roles_by_user[$user['uid']][] = mb_strtolower(trim($pr['role'])); }
                }
                if (!isset($new_roles_by_user[$user['uid']])) { $new_roles_by_user[$user['uid']] = array(); }
                $role_key = mb_strtolower($role);
                if (in_array($role_key, $existing_roles_by_user[$user['uid']], true) || in_array($role_key, $new_roles_by_user[$user['uid']], true)) { continue; }
                if ($max_collaborators > 0 && !isset($unique_invited_uids[$user['uid']])) {
                    if (count($unique_invited_uids) >= $max_collaborators) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_max_collaborators, $max_collaborators); break; }
                    $unique_invited_uids[$user['uid']] = true;
                }
                $inviter_uid = $mybb->user['uid'];
                if ($invite_as_owner) { $thread_query = $db->simple_select("threads", "uid", "tid='{$thread_id}'", array('limit' => 1)); $thread_data = $db->fetch_array($thread_query); if ($thread_data && $thread_data['uid']) { $inviter_uid = $thread_data['uid']; } }
                $insert_invitations[] = array("tid"=>$thread_id,"inviter_uid"=>$inviter_uid,"invitee_uid"=>$user['uid'],"role"=>$db->escape_string($role),"role_icon"=>$db->escape_string($role_icon),"status"=>'pending',"invite_date"=>TIME_NOW);
                $new_roles_by_user[$user['uid']][] = $role_key;
            } else {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_user_not_found, htmlspecialchars_uni($username));
            }
        }
    }
    
    if (!empty($errors)) { foreach ($errors as $error) { error($error); } }
    
    if (!empty($insert_invitations)) {
        try {
            $db->insert_query_multiple("collaboration_invitations", $insert_invitations);
            error_log("Thread Collaboration: Successfully inserted " . count($insert_invitations) . " invitations for thread " . $thread_id);
            $pm_sent_count = thread_collaboration_send_invitation_notifications($insert_invitations, $thread_id, $thread_subject);
            if ($pm_sent_count > 0) { error_log("Thread Collaboration: Successfully sent " . $pm_sent_count . " invitation PMs for thread " . $thread_id); }
        } catch (Exception $e) { error($lang->sprintf($lang->thread_collaboration_error_db, $e->getMessage())); error_log("Thread Collaboration: Database error - " . $e->getMessage()); }
    } else { error_log("Thread Collaboration: No invitations to insert for thread " . $thread_id); }
}
}

// Newthread: process direct collaborators (legacy)
if (!function_exists('thread_collaboration_process_direct_collaborators'))
{
function thread_collaboration_process_direct_collaborators($usernames, $roles, $role_icons, $thread_id, $thread_subject, $invite_as_owner = false)
{
    global $mybb, $db, $lang;
    
    if (!is_array($usernames) || !is_array($roles) || !is_array($role_icons)) {
        error_log("Thread Collaboration: Invalid collaborator data arrays for thread " . $thread_id);
        return;
    }
    
    $insert_collaborators = array();
    $errors = array();
    $max_collaborators = (int)$mybb->settings['thread_collaboration_max_collaborators'];
    
    foreach ($usernames as $key => $username) {
        $username = trim($username);
        $role = trim($roles[$key]);
        $role_icon = trim($role_icons[$key]);
        
        if (!empty($username)) {
            if ($max_collaborators > 0 && count($insert_collaborators) >= $max_collaborators) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_max_collaborators, $max_collaborators); break; }
            if (strlen($username) < 3 || strlen($username) > 50) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_username, htmlspecialchars_uni($username)); continue; }
            if (strlen($role) < 1 || strlen($role) > 100) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_role, htmlspecialchars_uni($role)); continue; }
            if (!empty($role_icon) && (strlen($role_icon) < 1 || strlen($role_icon) > 100)) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_invalid_icon, htmlspecialchars_uni($role_icon)); continue; }
            
            $query = $db->simple_select("users", "uid", "username='".$db->escape_string($username)."'", array('limit' => 1));
            $user = $db->fetch_array($query);
            
            if ($user['uid']) {
                if ($user['uid'] == $mybb->user['uid']) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_self_invitation, htmlspecialchars_uni($username)); continue; }
                
                // Check user thread limit
                if (!thread_collaboration_check_user_thread_limit($user['uid'])) {
                    $max_threads = (int)$mybb->settings['thread_collaboration_max_threads_per_user'];
                    $current_threads = thread_collaboration_get_user_thread_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_threads} threads. Current: {$current_threads}";
                    continue;
                }
                
                // Check user role limit
                if (!thread_collaboration_check_user_role_limit($user['uid'])) {
                    $max_roles = (int)$mybb->settings['thread_collaboration_max_roles_per_user'];
                    $current_roles = thread_collaboration_get_user_role_count($user['uid']);
                    $errors[] = "User {$username} has reached the maximum limit of {$max_roles} different roles. Current: {$current_roles}";
                    continue;
                }
                
                $existing_query = $db->simple_select("thread_collaborators", "uid", "tid='{$thread_id}' AND uid='{$user['uid']}'", array('limit' => 1));
                if ($db->num_rows($existing_query) > 0) { $errors[] = $lang->sprintf($lang->thread_collaboration_error_already_collaborator, htmlspecialchars_uni($username)); continue; }
                $insert_collaborators[] = array(
                    "tid" => $thread_id,
                    "uid" => $user['uid'],
                    "role" => $db->escape_string($role),
                    "role_icon" => $db->escape_string($role_icon),
                    "joined_date" => TIME_NOW,
                    "joined_via" => 'direct',
                    "source_id" => null
                );
                error_log("Thread Collaboration: Adding collaborator - Username: $username, UID: {$user['uid']}, Role: $role, Icon: $role_icon");
            } else {
                $errors[] = $lang->sprintf($lang->thread_collaboration_error_user_not_found, htmlspecialchars_uni($username));
                error_log("Thread Collaboration: User not found - Username: $username");
            }
        }
    }
    
    if (!empty($errors)) { foreach ($errors as $error) { error($error); } }
    
    if (!empty($insert_collaborators)) {
        try {
            $db->insert_query_multiple("thread_collaborators", $insert_collaborators);
            error_log("Thread Collaboration: Successfully inserted " . count($insert_collaborators) . " collaborators for thread " . $thread_id);
            $pm_sent_count = thread_collaboration_send_pm_notifications($insert_collaborators, $thread_id, $thread_subject, $mybb->user['uid'], $mybb->user['username'], false);
            if ($pm_sent_count > 0) { error_log("Thread Collaboration: Successfully added " . count($insert_collaborators) . " collaborators and sent " . $pm_sent_count . " PM notifications for thread " . $thread_id); }
            else { error_log("Thread Collaboration: Successfully added " . count($insert_collaborators) . " collaborators for thread " . $thread_id . " (PM notifications disabled)"); }
        } catch (Exception $e) { error($lang->sprintf($lang->thread_collaboration_error_db, $e->getMessage())); error_log("Thread Collaboration: Database error - " . $e->getMessage()); }
    } else { error_log("Thread Collaboration: No collaborators to insert for thread " . $thread_id); }
}
}

// Helper: send invitation notifications (shared)
if (!function_exists('thread_collaboration_send_invitation_notifications'))
{
function thread_collaboration_send_invitation_notifications($invitations, $thread_id, $thread_subject)
{
    global $mybb, $lang, $db;
    
    if (empty($thread_subject)) {
        try {
            $thread_query = $db->simple_select("threads", "subject", "tid='{$thread_id}'", array('limit' => 1));
            $thread_data = $db->fetch_array($thread_query);
            if ($thread_data && !empty($thread_data['subject'])) { $thread_subject = $thread_data['subject']; }
        } catch (Exception $e) { /* ignore */ }
    }
    
    $lang->load("thread_collaboration");
    if ($mybb->settings['thread_collaboration_pm_notifications'] != '1') { return 0; }
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    
    $pm_sent_count = 0; $errors = array();
    $by_user = array();
    foreach ($invitations as $inv) {
        $uid = (int)$inv['invitee_uid'];
        if (!isset($by_user[$uid])) { $by_user[$uid] = array(); }
        $by_user[$uid][] = $inv;
    }
    
    foreach ($by_user as $uid => $items) {
        try {
            // Get the inviter information from the invitation data (respects invite_as_owner)
            $first_invitation = $items[0];
            $inviter_uid = $first_invitation['inviter_uid'];
            
            // Get the actual inviter's username
            $inviter_query = $db->simple_select("users", "username", "uid='{$inviter_uid}'", array('limit' => 1));
            $inviter_data = $db->fetch_array($inviter_query);
            $inviter_username = $inviter_data ? $inviter_data['username'] : 'Unknown User';
            
            // Create proper clickable links
            $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . $thread_id;
            $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
            $ucp_url = $mybb->settings['bburl'] . "/usercp.php?action=collaboration_invitations";
            $clickable_ucp_link = "[url=" . $ucp_url . "]Collaboration Invitations[/url]";
            
            // Build roles list for the first invitation (since we group by user, all roles are for the same user)
            $role = htmlspecialchars_uni($first_invitation['role']);
            
            $pm_subject = $lang->sprintf($lang->thread_collaboration_invitation_pm_subject, $thread_subject);
            
            // Use the language string with proper placeholders
            $pm_message = $lang->sprintf(
                $lang->thread_collaboration_invitation_pm_message,
                $inviter_username,                 // {1} - inviter username (appears twice in message)
                $thread_subject,                   // {2} - thread subject (plain text)
                $role,                             // {3} - role
                $clickable_thread_link,            // {4} - clickable thread link
                $clickable_ucp_link                // {5} - clickable UCP link
            );
            
            $pm = array("subject"=>$pm_subject,"message"=>$pm_message,"icon"=>"","toid"=>array($uid),"fromid"=>$inviter_uid,"do"=>"","pmid"=>"");
            $pmhandler = new PMDataHandler();
            $pmhandler->set_data($pm);
            if ($pmhandler->validate_pm()) { $pmhandler->insert_pm(); $pm_sent_count++; }
        } catch (Exception $e) { $errors[] = $e->getMessage(); }
    }
    if (!empty($errors)) { error_log("Thread Collaboration: PM errors: " . implode(", ", $errors)); }
    return $pm_sent_count;
}
}

// Handle invitation accept/decline
if (!function_exists('thread_collaboration_handle_invitation_response'))
{
function thread_collaboration_handle_invitation_response($invite_id, $action)
{
    global $mybb, $db, $lang;
    
    $lang->load("thread_collaboration");
    
    $query = $db->simple_select("collaboration_invitations", "*", "invite_id='".(int)$invite_id."' AND invitee_uid='".(int)$mybb->user['uid']."' AND status='pending'", array('limit' => 1));
    if (!$query || $db->num_rows($query) == 0) {
        error("Invalid invitation or invitation already processed.");
        return;
    }
    $invitation = $db->fetch_array($query);
    
    if ($action == 'accept') {
        try {
            $tid = (int)$invitation['tid'];
            $invitee_uid = (int)$invitation['invitee_uid'];
            $all = $db->simple_select("collaboration_invitations", "invite_id, role, role_icon, inviter_uid, invite_date", "tid='{$tid}' AND invitee_uid='{$invitee_uid}' AND status='pending'");
            while ($row = $db->fetch_array($all)) {
                $exists_q = $db->simple_select('thread_collaborators', 'cid', "tid='{$tid}' AND uid='{$invitee_uid}' AND role='".$db->escape_string($row['role'])."'", array('limit' => 1));
                if ($db->num_rows($exists_q) == 0) {
                    $db->insert_query("thread_collaborators", array(
                        "tid" => $tid,
                        "uid" => $invitee_uid,
                        "role" => $db->escape_string($row['role']),
                        "role_icon" => $db->escape_string($row['role_icon']),
                        "joined_date" => TIME_NOW,
                        "joined_via" => 'invitation',
                        "source_id" => (int)$row['invite_id']
                    ));
                }
                $db->update_query("collaboration_invitations", array(
                    "status" => 'accepted',
                    "response_date" => TIME_NOW
                ), "invite_id='".(int)$row['invite_id']."'");
            }
            thread_collaboration_send_acceptance_notification($invitation);
            redirect("usercp.php?action=collaboration_invitations", "Invitation accepted successfully. You are now a collaborator on this thread.");
        } catch (Exception $e) { error("Error accepting invitation: " . $e->getMessage()); }
    } elseif ($action == 'decline') {
        try {
            $db->update_query("collaboration_invitations", array(
                "status" => 'declined',
                "response_date" => TIME_NOW
            ), "invite_id='".(int)$invite_id."'");
            thread_collaboration_send_declination_notification($invitation);
            redirect("usercp.php?action=collaboration_invitations", "Invitation declined successfully.");
        } catch (Exception $e) { error("Error declining invitation: " . $e->getMessage()); }
    }
}
}

// Send acceptance notification to inviter
if (!function_exists('thread_collaboration_send_acceptance_notification'))
{
function thread_collaboration_send_acceptance_notification($invitation)
{
    global $mybb, $db, $lang;
    if ($mybb->settings['thread_collaboration_pm_notifications'] != '1') { return; }
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    $thread_query = $db->simple_select("threads", "subject", "tid='".(int)$invitation['tid']."'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    $thread_subject = $thread_data['subject'] ?: 'Unknown Thread';
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . (int)$invitation['tid'];
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    try {
        $pm_subject = $lang->sprintf($lang->thread_collaboration_acceptance_pm_subject, $thread_subject);
        $pm_message = $lang->sprintf($lang->thread_collaboration_acceptance_pm_message, $mybb->user['username'], $thread_subject, htmlspecialchars_uni($invitation['role']), $clickable_thread_link);
        $pm = array("subject"=>$pm_subject,"message"=>$pm_message,"icon"=>"","toid"=>array((int)$invitation['inviter_uid']),"fromid"=>$mybb->user['uid'],"do"=>"","pmid"=>"");
        $pmhandler = new PMDataHandler(); $pmhandler->set_data($pm); if ($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
    } catch (Exception $e) { error_log("Thread Collaboration: Error sending acceptance PM: " . $e->getMessage()); }
}
}

// Send declination notification to inviter
if (!function_exists('thread_collaboration_send_declination_notification'))
{
function thread_collaboration_send_declination_notification($invitation)
{
    global $mybb, $db, $lang;
    if ($mybb->settings['thread_collaboration_pm_notifications'] != '1') { return; }
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";
    $thread_query = $db->simple_select("threads", "subject", "tid='".(int)$invitation['tid']."'", array('limit' => 1));
    $thread_data = $db->fetch_array($thread_query);
    $thread_subject = $thread_data['subject'] ?: 'Unknown Thread';
    $thread_url = $mybb->settings['bburl'] . "/showthread.php?tid=" . (int)$invitation['tid'];
    $clickable_thread_link = "[url=" . $thread_url . "]" . htmlspecialchars_uni($thread_subject) . "[/url]";
    try {
        $pm_subject = $lang->sprintf($lang->thread_collaboration_declination_pm_subject, $thread_subject);
        $pm_message = $lang->sprintf($lang->thread_collaboration_declination_pm_message, $mybb->user['username'], $thread_subject, htmlspecialchars_uni($invitation['role']), $clickable_thread_link);
        $pm = array("subject"=>$pm_subject,"message"=>$pm_message,"icon"=>"","toid"=>array((int)$invitation['inviter_uid']),"fromid"=>$mybb->user['uid'],"do"=>"","pmid"=>"");
        $pmhandler = new PMDataHandler(); $pmhandler->set_data($pm); if ($pmhandler->validate_pm()) { $pmhandler->insert_pm(); }
    } catch (Exception $e) { error_log("Thread Collaboration: Error sending declination PM: " . $e->getMessage()); }
}
}

// Maintenance: cleanup expired invitations
if (!function_exists('thread_collaboration_cleanup_expired_invitations'))
{
function thread_collaboration_cleanup_expired_invitations()
{
    global $mybb, $db;
    if ($mybb->settings['thread_collaboration_auto_cleanup'] != '1') { return; }
    $expiry_days = (int)$mybb->settings['thread_collaboration_invitation_expiry'];
    if ($expiry_days <= 0) { return; }
    $expiry_time = TIME_NOW - ($expiry_days * 24 * 60 * 60);
    try {
        $query = $db->simple_select("collaboration_invitations", "invite_id", "status='pending' AND invite_date < '".$expiry_time."'");
        $expired_count = 0;
        if ($query && $db->num_rows($query) > 0) { while ($invitation = $db->fetch_array($query)) { $db->delete_query("collaboration_invitations", "invite_id='".(int)$invitation['invite_id']."'"); $expired_count++; } }
        if ($expired_count > 0) { error_log("Thread Collaboration: Cleaned up {$expired_count} expired invitations"); }
    } catch (Exception $e) { error_log("Thread Collaboration: Error cleaning up expired invitations: " . $e->getMessage()); }
}
}

// Maintenance: cleanup expired collaboration requests
if (!function_exists('thread_collaboration_cleanup_expired_requests'))
{
function thread_collaboration_cleanup_expired_requests()
{
    global $mybb, $db;
    if ($mybb->settings['thread_collaboration_auto_cleanup_requests'] != '1') { return; }
    $expiry_days = (int)$mybb->settings['thread_collaboration_request_expiry'];
    if ($expiry_days <= 0) { return; }
    $expiry_time = TIME_NOW - ($expiry_days * 24 * 60 * 60);
    try {
        $query = $db->simple_select("collaboration_requests", "request_id", "status='pending' AND request_date < '".$expiry_time."'");
        $expired_count = 0;
        if ($query && $db->num_rows($query) > 0) { while ($request = $db->fetch_array($query)) { $db->delete_query("collaboration_requests", "request_id='".(int)$request['request_id']."'"); $expired_count++; } }
        if ($expired_count > 0) { error_log("Thread Collaboration: Cleaned up {$expired_count} expired collaboration requests"); }
    } catch (Exception $e) { error_log("Thread Collaboration: Error cleaning up expired requests: " . $e->getMessage()); }
}
}


