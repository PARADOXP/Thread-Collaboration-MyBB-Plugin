<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Add collaboration invitations to UserCP navigation
if (!function_exists('thread_collaboration_usercp_nav'))
{
function thread_collaboration_usercp_nav(&$usercpnav)
{
    global $mybb, $lang;
    
    // Check if invitation system is enabled
    if ($mybb->settings['thread_collaboration_invitation_system'] != '1') {
        return;
    }
    
    // Load language file
    $lang->load("thread_collaboration");
    
    // Add the collaboration invitations link to the misc section
    // Insert the link before the last </tbody> tag (the one with navigation items)
    $collaboration_link = '	<tr><td class="trow1 smalltext"><a href="usercp.php?action=collaboration_invitations" class="usercp_nav_item usercp_nav_collaboration_invitations">' . $lang->ucp_nav_collaboration_invitations . '</a></td></tr>
</tbody>';
    
    // Find the last occurrence of </tbody> and replace it
    $last_pos = strrpos($usercpnav, '</tbody>');
    if ($last_pos !== false) {
        $usercpnav = substr_replace($usercpnav, $collaboration_link, $last_pos, 8);
    }
}
}

// Handle UserCP collaboration invitations action
if (!function_exists('thread_collaboration_usercp_start'))
{
function thread_collaboration_usercp_start()
{
    global $mybb, $db, $templates, $lang, $usercpnav, $header, $headerinclude, $footer, $theme;
    
    if ($mybb->input['action'] == 'collaboration_invitations') {
        // Load language
        thread_collaboration_load_language();
        
        		// Handle settings update
		if (isset($mybb->input['update_owner_icon']) && $mybb->input['update_owner_icon'] == 1) {
			$owner_icon = $db->escape_string(trim($mybb->input['owner_icon']));
			$existing = $db->simple_select('collaboration_user_settings', 'uid', "uid='{$mybb->user['uid']}'");
			if ($db->num_rows($existing) > 0) {
				$db->update_query('collaboration_user_settings', array('owner_icon' => $owner_icon, 'updated_date' => TIME_NOW), "uid='{$mybb->user['uid']}'");
			} else {
				$db->insert_query('collaboration_user_settings', array('uid' => $mybb->user['uid'], 'owner_icon' => $owner_icon, 'updated_date' => TIME_NOW));
			}
			redirect("usercp.php?action=collaboration_invitations", "Owner icon updated.");
		}
		
		
		// Call the moved function
		thread_collaboration_handle_postbit_settings_update();
		if (isset($mybb->input['update_settings']) && $mybb->input['update_settings'] == 1) {
            $requests_enabled = (int)$mybb->input['collaboration_requests_enabled'];
            $thread_ids = $db->escape_string(trim($mybb->input['collaboration_thread_ids']));
            
            // If enabling collaboration requests and thread_ids is empty, set to "0" (all threads)
            if ($requests_enabled == 1 && empty($thread_ids)) {
                $thread_ids = '0';
            }
            
            // Schema columns are already defined in database.sql
            // Check if user settings exist
            $existing_settings = $db->simple_select('collaboration_user_settings', '*', "uid = '{$mybb->user['uid']}'");
            
            if ($db->num_rows($existing_settings) > 0) {
                // Update existing settings
                $db->update_query('collaboration_user_settings', array(
                    'requests_enabled' => $requests_enabled,
                    'thread_ids' => $thread_ids,
                    'updated_date' => TIME_NOW
                ), "uid = '{$mybb->user['uid']}'");
            } else {
                // Insert new settings
                $db->insert_query('collaboration_user_settings', array(
                    'uid' => $mybb->user['uid'],
                    'requests_enabled' => $requests_enabled,
                    'thread_ids' => $thread_ids,
                    'updated_date' => TIME_NOW
                ));
            }
            
            redirect("usercp.php?action=collaboration_invitations", "Settings updated successfully.");
        }
        
        // Get user settings for display
        $user_settings = $db->simple_select('collaboration_user_settings', '*', "uid = '{$mybb->user['uid']}'");
        $owner_icon_value = '';
        $show_all_roles_checked = 'checked';
        $show_selected_roles_checked = '';
        $role_selection_display = 'none';
        $available_roles_checkboxes = '';
        
        if ($db->num_rows($user_settings) > 0) {
            $tmp = $db->fetch_array($user_settings);
            $owner_icon_value = htmlspecialchars_uni($tmp['owner_icon']);
            
            // Handle role display settings
            if (isset($tmp['show_all_roles'])) {
                if ($tmp['show_all_roles'] == 0) {
                    $show_all_roles_checked = '';
                    $show_selected_roles_checked = 'checked';
                    $role_selection_display = 'table-row';
                }
            }
            
            // reset pointer by requerying for later fetch
            $user_settings = $db->simple_select('collaboration_user_settings', '*', "uid = '{$mybb->user['uid']}'");
        }
        
        // Get user's available roles from all their collaborations
        $user_roles_sql = "
            SELECT DISTINCT tc.role, tc.role_icon, tc.tid, t.subject
            FROM " . TABLE_PREFIX . "thread_collaborators tc
            LEFT JOIN " . TABLE_PREFIX . "threads t ON tc.tid = t.tid
            WHERE tc.uid = '{$mybb->user['uid']}'
            ORDER BY tc.role
        ";
        
        $user_roles_query = $db->query($user_roles_sql);
        
        $user_roles = array();
        $selected_roles = array();
        
        if ($db->num_rows($user_roles_query) > 0) {
            while ($role_data = $db->fetch_array($user_roles_query)) {
                $user_roles[] = $role_data;
            }
        }
        
        // Always call the moved functions to ensure visibility variables are set
        $available_roles_checkboxes = thread_collaboration_generate_postbit_settings($user_roles, $user_settings);
        $postbit_visibility = thread_collaboration_generate_postbit_visibility_settings($user_roles);
        $has_multiple_roles = $postbit_visibility['has_multiple_roles'];
        $has_multiple_roles_display = $postbit_visibility['has_multiple_roles_display'];
        $no_multiple_roles_display = $postbit_visibility['no_multiple_roles_display'];
        $role_count = $postbit_visibility['role_count'];
        $no_multiple_roles_message = $postbit_visibility['no_multiple_roles_message'];
        
        $collaboration_requests_enabled = '';
        $collaboration_requests_disabled = 'selected';
        $collaboration_thread_ids = '';
        
        if ($db->num_rows($user_settings) > 0) {
            $settings = $db->fetch_array($user_settings);
            $collaboration_thread_ids = htmlspecialchars_uni($settings['thread_ids']);
            
            if ($settings['requests_enabled'] == 1) {
                $collaboration_requests_enabled = 'selected';
                $collaboration_requests_disabled = '';
                
                // If thread_ids is empty but requests are enabled, show "0" as default
                if (empty($collaboration_thread_ids)) {
                    $collaboration_thread_ids = '0';
                }
            }
        }
        
        // Handle MyBB default modal requests
        if (isset($mybb->input['modal'])) {
            switch($mybb->input['modal']) {
                case 'unique_inviters':
                    thread_collaboration_modal_unique_inviters();
                    exit;
                case 'declined_invitations':
                    thread_collaboration_modal_declined_invitations();
                    exit;
                case 'collaborators_gained':
                    thread_collaboration_modal_collaborators_gained();
                    exit;
                case 'total_collaborators_gained':
                    thread_collaboration_modal_total_collaborators_gained();
                    exit;
                case 'roles_assigned':
                    thread_collaboration_modal_roles_assigned();
                    exit;
            }
        }

        // Handle AJAX requests
        if (isset($mybb->input['ajax']) && $mybb->input['ajax'] == 'unique_inviters') {
            thread_collaboration_ajax_unique_inviters();
            exit;
        }
        
        // Handle actions
        if (isset($mybb->input['accept']) && is_numeric($mybb->input['accept'])) {
            thread_collaboration_handle_invitation_response($mybb->input['accept'], 'accept');
        } elseif (isset($mybb->input['decline']) && is_numeric($mybb->input['decline'])) {
            thread_collaboration_handle_invitation_response($mybb->input['decline'], 'decline');
        } elseif (isset($mybb->input['approve_edit']) && is_numeric($mybb->input['approve_edit'])) {
            thread_collaboration_handle_post_edit_approval((int)$mybb->input['approve_edit'], 'approve');
        } elseif (isset($mybb->input['reject_edit']) && is_numeric($mybb->input['reject_edit'])) {
            thread_collaboration_handle_post_edit_approval((int)$mybb->input['reject_edit'], 'reject');
        } elseif (isset($mybb->input['approve']) && is_numeric($mybb->input['approve'])) {
            thread_collaboration_handle_request_response($mybb->input['approve'], 'approve');
        } elseif (isset($mybb->input['reject']) && is_numeric($mybb->input['reject'])) {
            thread_collaboration_handle_request_response($mybb->input['reject'], 'reject');
        }
        
        // Handle view parameters for detailed statistics
        if (isset($mybb->input['view'])) {
            $view = $mybb->input['view'];
            switch($view) {
                case 'threads_with_collaborators':
                    thread_collaboration_handle_threads_with_collaborators_view();
                    exit;
                case 'total_requests_sent':
                    thread_collaboration_handle_total_requests_sent_view();
                    exit;
                case 'sent':
                case 'pending_sent':
                case 'accepted_sent':
                case 'declined_sent':
                case 'collaborators_gained':
                case 'roles_assigned':
                case 'received':
                    thread_collaboration_handle_invitations_received_view();
                    exit;
                case 'pending_received':
                case 'accepted_received':
                case 'declined_received':
                case 'unique_inviters':
                    // These views will be implemented in the comprehensive stats
                    break;
            }
        }
        
        // Regular invitations page logic continues...
        $invitations_content = '';
        $query = $db->simple_select('collaboration_invitations', '*', "invitee_uid = '{$mybb->user['uid']}' AND status = 'pending'", array('order_by' => 'invite_date', 'order_dir' => 'DESC'));
        
        if ($db->num_rows($query) > 0) {
            // Group rows by thread id so each thread shows a single invitation with combined roles
            $by_thread = array();
            while ($row = $db->fetch_array($query)) {
                $tid = (int)$row['tid'];
                if (!isset($by_thread[$tid])) {
                    $by_thread[$tid] = array(
                        'tid' => $tid,
                        'inviter_uid' => (int)$row['inviter_uid'],
                        'invite_date' => $row['invite_date'],
                        'roles' => array(),
                        'latest_invite_id' => (int)$row['invite_id'],
                    );
                }
                $by_thread[$tid]['roles'][] = array('name' => $row['role'], 'icon' => $row['role_icon']);
                if ($row['invite_date'] > $by_thread[$tid]['invite_date']) { $by_thread[$tid]['invite_date'] = $row['invite_date']; }
                if ((int)$row['invite_id'] > $by_thread[$tid]['latest_invite_id']) { $by_thread[$tid]['latest_invite_id'] = (int)$row['invite_id']; }
                $by_thread[$tid]['inviter_uid'] = (int)$row['inviter_uid'];
            }
            
            foreach ($by_thread as $tid => $data) {
                $thread_info = $db->fetch_array($db->simple_select('threads', 'subject', "tid = '{$tid}'"));
                $inviter_info = $db->fetch_array($db->simple_select('users', 'username, avatar, avatardimensions', "uid = '{$data['inviter_uid']}'"));
                
                $invitation_tid = $tid;
                $invitation_thread_subject = htmlspecialchars_uni($thread_info['subject']);
                $invitation_date_formatted = my_date('relative', $data['invite_date']);
                
                // Generate avatar HTML for inviter
                $invitation_inviter_avatar = '';
                if ($mybb->settings['thread_collaboration_show_avatars'] == '1') {
                    if (!empty($inviter_info['avatar'])) {
                        $invitation_inviter_avatar = '<a href="' . get_profile_link($data['inviter_uid']) . '" class="tc-avatar-link"><img class="tc-avatar" src="'.htmlspecialchars_uni($inviter_info['avatar']).'" alt="" /></a>';
                    } else {
                        // Fallback to colored div with initial
                        $invitation_inviter_avatar = '<a href="' . get_profile_link($data['inviter_uid']) . '" class="tc-avatar-link"><div class="tc-avatar tc-avatar-initial">' . strtoupper(substr($inviter_info['username'], 0, 1)) . '</div></a>';
                    }
                }
                
                // Build comma-separated roles with icons and dynamic CSS classes
                $parts = array();
                foreach ($data['roles'] as $r) {
                    $role_name = htmlspecialchars_uni($r['name']);
                    $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                    
                    $icon_html = '';
                    if (!empty($r['icon'])) { 
                        $icon_html = ' <i class="' . htmlspecialchars_uni($r['icon']) . '"></i>'; 
                    }
                    
                    // Wrap each role in a span with dynamic class and default class
                    $parts[] = '<span class="default-role ' . $role_class . '">' . $role_name . $icon_html . '</span>';
                }
                $invitation_role = implode(', ', $parts);
                $invitation_role_icon_html = '';
                $invitation_inviter_username = build_profile_link($inviter_info['username'], $data['inviter_uid']);
                $invitation_id = $data['latest_invite_id'];
                
                eval("\$template_content = \"" . $templates->get("threadcollaboration_invitation_item") . "\";");
                $invitations_content .= $template_content;
            }
        } else {
            eval("\$invitations_content = \"" . $templates->get("threadcollaboration_no_invitations") . "\";");
        }
        
        // Handle collaboration requests
        $requests_content = '';
        $query = $db->simple_select('collaboration_requests', '*', "thread_owner_uid = '{$mybb->user['uid']}' AND status = 'pending'", array('order_by' => 'request_date', 'order_dir' => 'DESC'));
        
        if ($db->num_rows($query) > 0) {
            while ($request = $db->fetch_array($query)) {
                $thread_info = $db->fetch_array($db->simple_select('threads', 'subject', "tid = '{$request['tid']}'"));
                $requester_info = $db->fetch_array($db->simple_select('users', 'username, avatar, avatardimensions', "uid = '{$request['requester_uid']}'"));
                
                $request_tid = $request['tid'];
                $request_thread_subject = htmlspecialchars_uni($thread_info['subject']);
                $request_date_formatted = my_date('relative', $request['request_date']);
                
                // Build role with dynamic CSS class
                $role_name = htmlspecialchars_uni($request['role']);
                $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                
                $request_role_icon_html = '';
                if (!empty($request['role_icon'])) {
                    $request_role_icon_html = ' <i class="' . htmlspecialchars_uni($request['role_icon']) . '"></i>';
                }
                
                // Wrap role in span with dynamic class and default class
                $request_role = '<span class="default-role ' . $role_class . '">' . $role_name . $request_role_icon_html . '</span>';
                
                // Generate avatar HTML for requester
                $request_requester_avatar = '';
                if ($mybb->settings['thread_collaboration_show_avatars'] == '1') {
                    if (!empty($requester_info['avatar'])) {
                        $request_requester_avatar = '<a href="' . get_profile_link($request['requester_uid']) . '" class="tc-avatar-link"><img class="tc-avatar" src="'.htmlspecialchars_uni($requester_info['avatar']).'" alt="" /></a>';
                    } else {
                        // Fallback to colored div with initial
                        $request_requester_avatar = '<a href="' . get_profile_link($request['requester_uid']) . '" class="tc-avatar-link"><div class="tc-avatar tc-avatar-initial tc-avatar-pending">' . strtoupper(substr($requester_info['username'], 0, 1)) . '</div></a>';
                    }
                }
                
                $request_requester_username = build_profile_link($requester_info['username'], $request['requester_uid']);
                $request_message = htmlspecialchars_uni($request['message']);
                $request_id = $request['request_id'];
                
                eval("\$template_content = \"" . $templates->get("threadcollaboration_request_item") . "\";");
                $requests_content .= $template_content;
            }
        } else {
            eval("\$requests_content = \"" . $templates->get("threadcollaboration_no_requests") . "\";");
        }
        
        // Generate comprehensive statistics
        $comprehensive_stats = thread_collaboration_generate_comprehensive_stats($mybb->user['uid']);
        $comprehensive_stats_content = thread_collaboration_render_comprehensive_stats($comprehensive_stats);
        
    // Open specific stats links in MyBB modal
    $comprehensive_stats_content .= '<script type="text/javascript">jQuery(function($){
        $(document).on("click", "a[data-tc-modal]", function(e){
            e.preventDefault();
            var target = $(this).data("tc-modal");
            if(target){ MyBB.popupWindow("/usercp.php?action=collaboration_invitations&modal="+target); }
        });
    });</script>';
        
        // Do not pre-render any custom modal; rely on MyBB default modal via popupWindow
        $thread_collaboration_modal = '';
        
        // Build pending edit approvals using templates only for protected groups (require edit approval)
        $pending_edits_section = '';
        $__tc_is_protected_user = false;
        if ((int)$mybb->settings['thread_collaboration_require_approval_for_protected'] === 1) {
            $__tc_protected = array();
            if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
                foreach (explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']) as $gid) {
                    $gid = (int)trim($gid);
                    if ($gid > 0) { $__tc_protected[] = $gid; }
                }
            }
            if (!empty($__tc_protected)) {
                $__tc_user_groups = array((int)$mybb->user['usergroup']);
                if (!empty($mybb->user['additionalgroups'])) {
                    foreach (explode(',', $mybb->user['additionalgroups']) as $ag) { $ag = (int)trim($ag); if ($ag > 0) { $__tc_user_groups[] = $ag; } }
                }
                foreach ($__tc_user_groups as $g) { if (in_array($g, $__tc_protected, true)) { $__tc_is_protected_user = true; break; } }
            }
        }
        if ($__tc_is_protected_user) {
        $pending_edits_content = '';
        $pending = $db->simple_select('collab_post_edits', '*', "owner_uid='{$mybb->user['uid']}' AND status='pending'", array('order_by' => 'dateline', 'order_dir' => 'DESC'));
        if ($pending && $db->num_rows($pending) > 0)
        {
            while ($row = $db->fetch_array($pending))
            {
                $post_info = $db->fetch_array($db->simple_select('posts', 'subject, tid', "pid='{$row['pid']}'"));
                $editor_info = $db->fetch_array($db->simple_select('users', 'username', "uid='{$row['editor_uid']}'"));
                $thread_info = $db->fetch_array($db->simple_select('threads', 'subject', "tid='{$row['tid']}'"));
                
                $edit_id = $row['id'];
                $edit_thread_subject = htmlspecialchars_uni($thread_info['subject']);
                $edit_post_subject = htmlspecialchars_uni($post_info['subject']);
                $edit_editor_username = htmlspecialchars_uni($editor_info['username']);
                $edit_date_formatted = my_date('relative', $row['dateline']);
                // Parse the draft JSON to get the edited message
                $draft = @json_decode($row['draft'], true);
                
                if (is_array($draft)) {
                    // Try both possible field names for backward compatibility
                    $edited_content = '';
                    if (isset($draft['edited_message'])) {
                        $edited_content = $draft['edited_message'];
                    } elseif (isset($draft['message'])) {
                        $edited_content = $draft['message'];
                    }
                    
                    if (!empty($edited_content)) {
                        // Clean the content and create preview
                        $clean_content = strip_tags($edited_content);
                        $clean_content = preg_replace('/\s+/', ' ', $clean_content); // Normalize whitespace
                        $clean_content = trim($clean_content);
                        
                        if (strlen($clean_content) > 150) {
                            $edit_preview = htmlspecialchars_uni(substr($clean_content, 0, 150)) . '...';
                        } else {
                            $edit_preview = htmlspecialchars_uni($clean_content);
                        }
                    } else {
                        // Fallback: Show original post content if draft is empty
                        if (isset($draft['original_message']) && !empty($draft['original_message'])) {
                            $clean_content = strip_tags($draft['original_message']);
                            $clean_content = preg_replace('/\s+/', ' ', $clean_content);
                            $clean_content = trim($clean_content);
                            
                            if (strlen($clean_content) > 150) {
                                $edit_preview = 'Original: ' . htmlspecialchars_uni(substr($clean_content, 0, 150)) . '...';
                            } else {
                                $edit_preview = 'Original: ' . htmlspecialchars_uni($clean_content);
                            }
                        } else {
                            $edit_preview = 'Preview unavailable (empty content)';
                        }
                    }
                } else {
                    $edit_preview = 'Preview unavailable (invalid JSON)';
                }
                
                eval("\$template_content = \"" . $templates->get("threadcollaboration_pending_edit_item") . "\";");
                $pending_edits_content .= $template_content;
            }
        } else {
            eval("\$pending_edits_content = \"" . $templates->get("threadcollaboration_no_pending_edits") . "\";");
        }
        
        eval("\$pending_edits_section = \"" . $templates->get("threadcollaboration_pending_edits") . "\";");
        }
        
        // Check if user collaboration settings are enabled by admin and generate settings section
        $collaboration_settings_section = '';
        if ($mybb->settings['thread_collaboration_enable_user_settings'] == '1') {
            eval("\$collaboration_settings_section = \"" . $templates->get("threadcollaboration_settings_section") . "\";");
        }
        
        // Use tabbed or traditional template based on setting
        if ($mybb->settings['thread_collaboration_enable_tabbed_usercp'] == '1') {
            eval("\$page = \"" . $templates->get("threadcollaboration_usercp_tabbed") . "\";");
        } else {
            eval("\$page = \"" . $templates->get("threadcollaboration_usercp_invitations") . "\";");
        }
        output_page($page);
        exit;
    }
}
}

// Handle request approve/reject
if (!function_exists('thread_collaboration_handle_request_response'))
{
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
}

// Send request approval notification to requester
if (!function_exists('thread_collaboration_send_request_approval_notification'))
{
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
}

// Send request rejection notification to requester
if (!function_exists('thread_collaboration_send_request_rejection_notification'))
{
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
}

// Process collaboration request
if (!function_exists('thread_collaboration_process_request'))
{
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
}

// Send request notification to thread owner
if (!function_exists('thread_collaboration_send_request_notification'))
{
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
}