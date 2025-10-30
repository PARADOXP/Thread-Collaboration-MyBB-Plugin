<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!function_exists('thread_collaboration_showthread_end'))
{
function thread_collaboration_showthread_end()
{
    global $mybb, $db, $thread, $templates, $lang, $collaboration_box, $collaboration_chat_button, $collaboration_draft_button;
    
    
    $lang->load("thread_collaboration");
    

	

    // Check if collaboration is allowed in current forum
    if (!thread_collaboration_is_forum_allowed($thread['fid']))
    {
        return;
    }

    $collaborators_list = '';
    $pending_invitations_list = '';

    // Decide if current viewer should see the Actions column
    $is_thread_owner = ($mybb->user['uid'] == $thread['uid']);
    $is_forum_moderator = is_moderator($thread['fid']);
    $management_groups = array();
    if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
        $management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
    }
    $is_management_user = in_array($mybb->user['usergroup'], $management_groups);
    $is_thread_collaborator = false;
    if ($mybb->user['uid']) {
        $__tcq = $db->simple_select('thread_collaborators', 'uid', "tid='".(int)$thread['tid']."' AND uid='".(int)$mybb->user['uid']."'", array('limit' => 1));
        if ($__tcq && $db->num_rows($__tcq) > 0) {
            $is_thread_collaborator = true;
        }
    }
    $show_actions_column = ($is_thread_owner || $is_forum_moderator || $is_management_user || $is_thread_collaborator);
    
    // Check if pending invitations should be shown
    $show_pending_invitations = $mybb->settings['thread_collaboration_show_pending_invitations'] == '1';
    
    // Check if user has permission to see pending invitations
    $can_see_pending_invitations = false;
    if ($show_pending_invitations) {
        // Get usergroups that can view pending invitations
        $pending_invitations_groups = array();
        if (!empty($mybb->settings['thread_collaboration_pending_invitations_usergroups'])) {
            $pending_invitations_groups = explode(',', $mybb->settings['thread_collaboration_pending_invitations_usergroups']);
        }
        
        // Check if user is in the allowed usergroups
        $can_see_pending_invitations = in_array($mybb->user['usergroup'], $pending_invitations_groups);
        
        // Thread creators can always see pending invitations for their threads
        if (!$can_see_pending_invitations && $mybb->user['uid'] == $thread['uid']) {
            $can_see_pending_invitations = true;
        }
        
        // Forum moderators can see pending invitations in forums they moderate
        if (!$can_see_pending_invitations && is_moderator($thread['fid'])) {
            $can_see_pending_invitations = true;
        }
    }
    
    // Try to query the table with proper error handling
    try {
		$sql = "
			SELECT tc.*, u.username, u.uid, u.avatar, u.avatardimensions 
			FROM " . TABLE_PREFIX . "thread_collaborators tc 
			LEFT JOIN " . TABLE_PREFIX . "users u ON tc.uid = u.uid 
			WHERE tc.tid='{$thread['tid']}'
		";
		
		$query = $db->query($sql);
		
        if ($query && $db->num_rows($query) > 0)
        {
            $rendered_uids = array();
            while ($collaborator = $db->fetch_array($query))
            {
                $collaborator_username = htmlspecialchars_uni($collaborator['username']);
                $collaborator_uid = (int)$collaborator['uid'];
                if (isset($rendered_uids[$collaborator_uid])) { continue; }
                $rendered_uids[$collaborator_uid] = true;
                // Build combined roles list for this user in this thread with dynamic CSS classes
                $roles_parts = array();
                $rq = $db->simple_select('thread_collaborators', 'role, role_icon', "tid='".(int)$thread['tid']."' AND uid='".$collaborator_uid."'");
                while ($r = $db->fetch_array($rq)) {
                    $role_name = htmlspecialchars_uni($r['role']);
                    $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                    
                    $icon_html = '';
                    if (!empty($r['role_icon'])) { 
                        $icon_html = ' <i class="' . htmlspecialchars_uni($r['role_icon']) . '"></i>'; 
                    }
                    
                    // Wrap each role in a span with dynamic class and default class
                    $roles_parts[] = '<span class="default-role ' . $role_class . '">' . $role_name . $icon_html . '</span>';
                }
                $collaborator_role = implode(', ', $roles_parts);
                $collaborator_role_icon = '';
                
                // Get join date and method
                $collaborator_joined_date = '';
                $collaborator_joined_via = 'direct';
                
                if (!empty($collaborator['joined_date']) && $collaborator['joined_date'] > 0) {
                    $collaborator_joined_date = my_date('relative', $collaborator['joined_date']);
                } else {
                    // Fallback for existing records without join date
                    $collaborator_joined_date = 'Unknown';
                }
                
                if (!empty($collaborator['joined_via'])) {
                    $collaborator_joined_via = ucfirst($collaborator['joined_via']);
                }
                
				// Generate avatar HTML for template
				$collaborator_avatar = '';
				if ($mybb->settings['thread_collaboration_show_avatars'] == '1') {
					if (!empty($collaborator['avatar'])) {
						$collaborator_avatar = '<a href="' . get_profile_link($collaborator_uid) . '" class="tc-avatar-link"><img class="tc-avatar" src="'.htmlspecialchars_uni($collaborator['avatar']).'" alt="" /></a>';
						error_log("Thread Collaboration: Avatar found for " . $collaborator_username . ": " . $collaborator['avatar']);
					} else {
						// Fallback to colored div with initial
						$collaborator_avatar = '<a href="' . get_profile_link($collaborator_uid) . '" class="tc-avatar-link"><div class="tc-avatar tc-avatar-initial">' . strtoupper(substr($collaborator_username, 0, 1)) . '</div></a>';
						error_log("Thread Collaboration: No avatar for " . $collaborator_username . ", using initial");
					}
                }
                
                // Generate clickable username link
                $collaborator_username_link = build_profile_link($collaborator_username, $collaborator_uid);
                
                // Generate icon HTML properly
                $collaborator_role_icon_html = '';
                if (!empty($collaborator_role_icon)) {
                    $collaborator_role_icon_html = '<i class="' . $collaborator_role_icon . '"></i>';
                }
                
				// Determine role-specific CSS class
                $role_class = '';
				$role_lower = strtolower($collaborator_role);
				if (strpos($role_lower, 'writer') !== false) {
					$role_class = 'role-writer';
				} elseif (strpos($role_lower, 'artist') !== false) {
					$role_class = 'role-artist';
				} elseif (strpos($role_lower, 'designer') !== false || strpos($role_lower, 'graphic') !== false) {
					$role_class = 'role-designer';
				} elseif (strpos($role_lower, 'script') !== false) {
					$role_class = 'role-script';
				} elseif (strpos($role_lower, 'translator') !== false) {
					$role_class = 'role-translator';
				} elseif (strpos($role_lower, 'announcer') !== false) {
					$role_class = 'role-announcer';
				}
                
                // Prepare action buttons (only for permitted viewers)
                $collaborator_actions = '';
                if ($show_actions_column) {
                    // Check if current user is the collaborator themselves
                    $is_self = ($mybb->user['uid'] == $collaborator_uid);
                    
                    // Add Edit button for thread owners, moderators, and management users
                    if ($mybb->user['uid'] == $thread['uid'] || is_moderator($thread['fid']) || $is_management_user) {
                        error_log("Thread Collaboration: Adding edit button for collaborator UID: " . $collaborator_uid . ", Role: " . $collaborator_role);
                        $collaborator_actions .= '<button type="button" class="button small edit-collaborator-btn" data-uid="'. (int)$collaborator_uid .'" data-role="'. htmlspecialchars_uni($collaborator_role) .'" data-icon="'. htmlspecialchars_uni($collaborator_role_icon) .'" data-type="collaborator">Edit</button> ';
                        
                        // Only show Remove button if the user is NOT the collaborator themselves
                        if (!$is_self) {
                        $collaborator_actions .= '<form method="post" action="showthread.php?action=remove_collaborator&amp;tid='. (int)$thread['tid'] .'" style="display:inline;">'
                            .'<input type="hidden" name="my_post_key" value="'. $mybb->post_code .'" />'
                            .'<input type="hidden" name="uid" value="'. (int)$collaborator_uid .'" />'
                            .'<button type="submit" class="button small" onclick="return confirm(\'Remove this collaborator?\');">Remove</button>'
                            .'</form>';
                    }
                    }
                    
                    // Quit button for the collaborator themselves
                    if ($is_self) {
						if (!empty($collaborator_actions)) {
							$collaborator_actions .= ' ';
						}
                        $collaborator_actions .= '<form method="post" action="showthread.php?action=quit_collaboration&amp;tid='. (int)$thread['tid'] .'" style="display:inline;">'
                            .'<input type="hidden" name="my_post_key" value="'. $mybb->post_code .'" />'
                            .'<button type="submit" class="button small" onclick="return confirm(\'Quit this collaboration?\');">Quit</button>'
                            .'</form>';
                    }
                }
                
                // Use proper MyBB template handling
                eval("\$template_content = \"" . $templates->get("threadcollaboration_show_collaborators_item") . "\";");
				$template_content = str_replace('{$collaborator_avatar}', $collaborator_avatar, $template_content);
                $template_content = str_replace('{$collaborator_username_link}', $collaborator_username_link, $template_content);
                $template_content = str_replace('{$collaborator_role}', $collaborator_role, $template_content);
                $template_content = str_replace('{$collaborator_role_icon_html}', $collaborator_role_icon_html, $template_content);
                $template_content = str_replace('{$collaborator_joined_date}', $collaborator_joined_date, $template_content);
                $template_content = str_replace('{$collaborator_joined_via}', $collaborator_joined_via, $template_content);
				if ($show_actions_column) {
					$template_content = str_replace('{$collaborator_actions}', $collaborator_actions, $template_content);
				} else {
					// Remove the actions cell when the column is hidden
					$template_content = preg_replace('#<td class=\"trow1\"[^>]*>\s*\{\$collaborator_actions\}\s*<\/td>#i', '', $template_content);
				}
				
				// Add role-specific class if available
				if (!empty($role_class)) {
					$template_content = str_replace('class="collaborator-item"', 'class="collaborator-item ' . $role_class . '"', $template_content);
				}
				
                $collaborators_list .= $template_content;
            }
        }
    } catch (Exception $e) {
		// Log error but don't break the page
        error_log("Thread Collaboration Plugin Error: " . $e->getMessage());
    }

	// Fetch pending invitations and requests if setting is enabled and user has permission
    if ($show_pending_invitations && $can_see_pending_invitations) {
        try {
			// Get pending invitations
			$pending_invitations_sql = "
				SELECT ci.invite_id as id, ci.tid, ci.inviter_uid, ci.invitee_uid as user_uid, ci.role, ci.role_icon, ci.status, ci.invite_date as date, NULL as message, u.username, u.avatar, u.avatardimensions, 'invitation' as type, NULL as request_id
				FROM " . TABLE_PREFIX . "collaboration_invitations ci 
				LEFT JOIN " . TABLE_PREFIX . "users u ON ci.invitee_uid = u.uid 
				WHERE ci.tid='{$thread['tid']}' AND ci.status='pending'
			";
			
			// Get pending requests
			$pending_requests_sql = "
				SELECT cr.request_id as id, cr.tid, cr.thread_owner_uid as inviter_uid, cr.requester_uid as user_uid, cr.role, cr.role_icon, cr.status, cr.request_date as date, cr.message, u.username, u.avatar, u.avatardimensions, 'request' as type, cr.request_id
				FROM " . TABLE_PREFIX . "collaboration_requests cr 
				LEFT JOIN " . TABLE_PREFIX . "users u ON cr.requester_uid = u.uid 
				WHERE cr.tid='{$thread['tid']}' AND cr.status='pending'
			";
			
			// Combine both queries
			$combined_sql = "($pending_invitations_sql) UNION ALL ($pending_requests_sql) ORDER BY date DESC";
			
            $pending_query = $db->query($combined_sql);
			
            $grouped = array();
            if ($pending_query && $db->num_rows($pending_query) > 0) {
                while ($row = $db->fetch_array($pending_query)) {
                    // Handle both invitations and requests
                    $uid = (int)$row['user_uid'];
                    $date_value = $row['date'];
                    
                    if (!isset($grouped[$uid])) {
                        $grouped[$uid] = array(
                            'uid' => $uid, 
                            'username' => $row['username'], 
                            'avatar' => $row['avatar'], 
                            'type' => $row['type'],
                            'date' => $date_value,
                            'request_id' => $row['request_id'],
                            'roles' => array()
                        );
                    }
                    $grouped[$uid]['roles'][] = array('name' => $row['role'], 'icon' => $row['role_icon']);
                    if ($date_value > $grouped[$uid]['date']) { 
                        $grouped[$uid]['date'] = $date_value; 
                        $grouped[$uid]['type'] = $row['type']; // Update type to latest
                    }
                }
                foreach ($grouped as $data) {
                    $invitee_username = htmlspecialchars_uni($data['username']);
                    $invitee_uid = (int)$data['uid'];
                    $invitation_date = my_date('relative', $data['date']);
                    
                    // Set status text based on type
                    if ($data['type'] == 'request') {
                        $status_text = 'Requested ' . $invitation_date;
                    } else {
                        $status_text = 'Invited ' . $invitation_date;
                    }
					
					// Generate avatar HTML for template
					$invitee_avatar = '';
					if ($mybb->settings['thread_collaboration_show_avatars'] == '1') {
						if (!empty($data['avatar'])) {
							$invitee_avatar = '<a href="' . get_profile_link($invitee_uid) . '" class="tc-avatar-link"><img class="tc-avatar" src="'.htmlspecialchars_uni($data['avatar']).'" alt="" /></a>';
						} else {
							// Fallback to colored div with initial
							$invitee_avatar = '<a href="' . get_profile_link($invitee_uid) . '" class="tc-avatar-link"><div class="tc-avatar tc-avatar-initial tc-avatar-pending">' . strtoupper(substr($invitee_username, 0, 1)) . '</div></a>';
						}
					}
					
					// Generate clickable username link
                    $invitee_username_link = build_profile_link($invitee_username, $invitee_uid);
					
					// Build comma-separated roles with icons and dynamic CSS classes
                    $parts = array();
                    foreach ($data['roles'] as $r) {
                        $role_name = htmlspecialchars_uni($r['name']);
                        $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                        
                        $icon_html = '';
                        if (!empty($r['icon'])) { 
                            $icon_html = '<i class="' . htmlspecialchars_uni($r['icon']) . '"></i> '; 
                        }
                        
                        // Wrap each role in a span with dynamic class and default class
                        $parts[] = '<span class="default-role ' . $role_class . '">' . $role_name . ' ' . $icon_html . '</span>';
                    }
                    $roles_joined = implode(', ', $parts);
					
					// Prepare actions based on type
					$invitation_actions = '';
					if ($mybb->user['uid'] == $thread['uid'] || is_moderator($thread['fid']) || $is_management_user) {
						$first_role = $data['roles'][0];
						
						if ($data['type'] == 'request' && !empty($data['request_id'])) {
							// For requests, show edit/approve/reject buttons
							$request_id = (int)$data['request_id'];
							$invitation_actions = '<button type="button" class="button small edit-collaborator-btn" data-uid="'. (int)$invitee_uid .'" data-role="'. htmlspecialchars_uni($first_role['name']) .'" data-icon="'. htmlspecialchars_uni($first_role['icon']) .'" data-type="request" data-request-id="'. $request_id .'">Edit</button> ';
							$invitation_actions .= '<a href="usercp.php?action=collaboration_invitations&approve='. $request_id .'" class="button small" style="background: #28a745; color: white; border: 1px solid #28a745; margin-left: 5px;">Approve</a> ';
							$invitation_actions .= '<a href="usercp.php?action=collaboration_invitations&reject='. $request_id .'" class="button small" style="background: #dc3545; color: white; border: 1px solid #dc3545; margin-left: 5px;">Reject</a>';
						} else {
							// For invitations, show edit/revoke buttons
							$invitation_actions = '<button type="button" class="button small edit-collaborator-btn" data-uid="'. (int)$invitee_uid .'" data-role="'. htmlspecialchars_uni($first_role['name']) .'" data-icon="'. htmlspecialchars_uni($first_role['icon']) .'" data-type="invitation">Edit</button> ';
							$invitation_actions .= '<button type="button" class="button small revoke-invitation-btn" data-uid="'. (int)$invitee_uid .'" data-username="'. htmlspecialchars_uni($invitee_username) .'" style="background: #dc3545; color: white; border: 1px solid #dc3545; margin-left: 5px;">Revoke</button>';
						}
					}
					
					// Use template for pending invitation item
					eval("\$template_content = \"" . $templates->get("threadcollaboration_show_pending_invitation_item") . "\";");
					$template_content = str_replace('{$invitee_avatar}', $invitee_avatar, $template_content);
					$template_content = str_replace('{$invitee_username_link}', $invitee_username_link, $template_content);
					$template_content = str_replace('{$roles_joined}', $roles_joined, $template_content);
					$template_content = str_replace('{$invitation_date}', $invitation_date, $template_content);
					$template_content = str_replace('{$status_text}', $status_text, $template_content);
					$template_content = str_replace('{$invitation_actions}', $invitation_actions, $template_content);
					
                    $pending_invitations_list .= $template_content;
                }
            }
		} catch (Exception $e) {
			error_log("Thread Collaboration Plugin Error fetching pending invitations: " . $e->getMessage());
		}
    }
	// If current user is thread owner, render a small inline form to set own role
    if ($mybb->user['uid'] == $thread['uid']) {
        $owner_colspan = ($show_actions_column ? 5 : 4);
        $owner_role_form = '<tr><!-- owner_role_form -->'
            .'<td class="trow1" colspan="'.$owner_colspan.'" style="text-align: center; padding: 8px;">'
            .'<form id="owner_role_form" method="post" action="showthread.php?action=set_owner_role&amp;tid='.(int)$thread['tid'].'" style="margin:0;">'
            .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
            .'<div class="thread_collaboration_field">'
            .'<label>Role: <input type="text" name="owner_role" class="textbox collaborator_role" style="width: 200px;" placeholder="Set your role (optional)" /></label> '
            .'<label class="icon-field-label">Icon: <input type="text" name="owner_role_icon" class="textbox collaborator_role_icon" style="width: 120px;" placeholder="fas fa-icon" /></label> '
            .'<button type="submit" class="button small" style="margin-left:6px;">Save</button>'
            .'</div>'
            .'</form>'
            .'</td>'
            .'</tr>';
    }

    // Build the collaboration box content
    $collaboration_content = '';
	
	// Add collaborators section if there are any
    if (!empty($collaborators_list)) {
		// Count collaborators
		$collaborator_count = substr_count($collaborators_list, 'collaborator-card');
		
		// Use proper MyBB template handling for container
        eval("\$container_content = \"" . $templates->get("threadcollaboration_show_collaborators") . "\";");
        $container_content = str_replace('{$collaborators_list}', $collaborators_list, $container_content);
        $container_content = str_replace('{$collaborator_count}', $collaborator_count, $container_content);
        if (!$show_actions_column) { $container_content = str_replace('<td class="tcat" style="text-align: center; font-weight: bold;">Actions</td>', '', $container_content); $container_content = str_replace('colspan="5"', 'colspan="4"', $container_content); }
        if (isset($owner_role_form)) {
            $needle = '</tbody>';
            $pos = strpos($container_content, $needle);
			if ($pos !== false) {
				$container_content = substr($container_content, 0, $pos) . $owner_role_form . substr($container_content, $pos);
			}
		}
		// If owner form present, include Select2 assets and initialize role behavior like editpost
		if (isset($owner_role_form)) {
			// Build default roles array from settings
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
			$owner_js = '<link rel="stylesheet" href="'.$mybb->asset_url.'/jscripts/select2/select2.css?ver=1807">'
				.'<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/select2/select2.min.js?ver=1806"></script>'
				.'<script type="text/javascript">jQuery(document).ready(function($){'
				.'var defaultRoles = '.json_encode($default_roles).';'
				.'var $ownerRole=$("#owner_role_form .collaborator_role"), $icon=$("#owner_role_form .collaborator_role_icon"), $label=$("#owner_role_form .icon-field-label");'
				.'function updateIconVisibility(val){ var pre=defaultRoles.find(function(r){return r.name===val;}); if(pre && pre.icon){ $icon.val(pre.icon); $label.hide(); } else if($.trim(val)!==""){ $label.show(); $icon.val(""); } else { $label.hide(); $icon.val(""); } }'
				.'function initOwnerRole(){ try { if ($.fn.select2){ if (typeof MyBB !== "undefined" && MyBB.select2){ MyBB.select2(); } $ownerRole.select2({ placeholder: "Select a role or type custom...", minimumInputLength:0, multiple:false, allowClear:true, data: defaultRoles.map(function(r){return {id:r.name,text:r.name,icon:r.icon};}), initSelection:function(element, callback){ var value=$(element).val(); if(value!==""){ callback({id:value,text:value}); } }, createSearchChoice:function(term,data){ if($(data).filter(function(){return this.text.localeCompare(term)===0;}).length===0){ return {id:term,text:term}; } } }); $ownerRole.on("select2-selecting", function(e){ var txt=e.choice && (e.choice.text||e.choice.id) ? (e.choice.text||e.choice.id) : $ownerRole.val(); updateIconVisibility(txt); }); $ownerRole.on("change", function(){ updateIconVisibility($(this).val()); }); } } catch(e){ } updateIconVisibility($ownerRole.val()); }'
				.'initOwnerRole();  });</script>';
			$container_content .= $owner_js;
        }
        $collaboration_content .= $container_content;
    } else {
		// No collaborators, but check if we should show owner role form anyway
		$show_owner_form_always = $mybb->settings['thread_collaboration_show_owner_role_form_always'] == '1';
		if ($show_owner_form_always && isset($owner_role_form)) {
			// Use template for no collaborators state
			eval("\$container_content = \"" . $templates->get("threadcollaboration_no_collaborators") . "\";");
			$container_content = str_replace('{$owner_role_form}', $owner_role_form, $container_content);
			
			// Add Select2 assets and initialize role behavior
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
			$owner_js = '<link rel="stylesheet" href="'.$mybb->asset_url.'/jscripts/select2/select2.css?ver=1807">'
				.'<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/select2/select2.min.js?ver=1806"></script>'
				.'<script type="text/javascript">jQuery(document).ready(function($){'
				.'var defaultRoles = '.json_encode($default_roles).';'
				.'var $ownerRole=$("#owner_role_form .collaborator_role"), $icon=$("#owner_role_form .collaborator_role_icon"), $label=$("#owner_role_form .icon-field-label");'
				.'function updateIconVisibility(val){ var pre=defaultRoles.find(function(r){return r.name===val;}); if(pre && pre.icon){ $icon.val(pre.icon); $label.hide(); } else if($.trim(val)!==""){ $label.show(); $icon.val(""); } else { $label.hide(); $icon.val(""); } }'
				.'function initOwnerRole(){ try { if ($.fn.select2){ if (typeof MyBB !== "undefined" && MyBB.select2){ MyBB.select2(); } $ownerRole.select2({ placeholder: "Select a role or type custom...", minimumInputLength:0, multiple:false, allowClear:true, data: defaultRoles.map(function(r){return {id:r.name,text:r.name,icon:r.icon};}), initSelection:function(element, callback){ var value=$(element).val(); if(value!==""){ callback({id:value,text:value}); } }, createSearchChoice:function(term,data){ if($(data).filter(function(){return this.text.localeCompare(term)===0;}).length===0){ return {id:term,text:term}; } } }); $ownerRole.on("select2-selecting", function(e){ var txt=e.choice && (e.choice.text||e.choice.id) ? (e.choice.text||e.choice.id) : $ownerRole.val(); updateIconVisibility(txt); }); $ownerRole.on("change", function(){ updateIconVisibility($(this).val()); }); } } catch(e){ } updateIconVisibility($ownerRole.val()); }'
				.'initOwnerRole();  });</script>';
			$container_content .= $owner_js;
			$collaboration_content .= $container_content;
		}
	}
	
	// Add pending invitations section if there are any and setting is enabled
    if ($show_pending_invitations && $can_see_pending_invitations && !empty($pending_invitations_list)) {
        eval("\$pending_container = \"" . $templates->get("threadcollaboration_show_pending_invitations") . "\";");
        $pending_container = str_replace('{$pending_invitations_list}', $pending_invitations_list, $pending_container);
        $collaboration_content .= $pending_container;
    }
	
		// Add edit modal to collaboration content
		if (!empty($collaboration_content)) {
			// Load edit modal template
			eval("\$edit_modal = \"" . $templates->get("threadcollaboration_edit_modal") . "\";");
			$collaboration_content .= $edit_modal;
		
		// Process default roles in PHP (outside JavaScript scope)
		$default_roles = array();
		$default_roles_text = $mybb->settings['thread_collaboration_default_roles'];
		
		if (!empty($default_roles_text)) {
			$roles_lines = explode("\n", $default_roles_text);
			
			foreach ($roles_lines as $line) {
				$line = trim($line);
				
				if (!empty($line)) {
					$parts = explode('|', $line);
					
					if (count($parts) >= 2) {
						$role_name = trim($parts[0]);
						$role_icon = trim($parts[1]);
						$default_roles[] = array('name' => $role_name, 'icon' => $role_icon);
					}
				}
			}
		}
		
		// Add edit modal JavaScript and CSS
		$edit_js = '
		<link rel="stylesheet" href="'.$mybb->asset_url.'/jscripts/select2/select2.css?ver=1807">
		<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/select2/select2.min.js?ver=1806"></script>
		<script type="text/javascript">window.threadCollabEditRoles = ' . json_encode($default_roles) . ';</script>
		<script type="text/javascript" src="'.$mybb->asset_url.'/inc/plugins/Thread_Collaboration/Assets/edit_collaborator_modal.js"></script>';
		
		// Set the final collaboration box content
		$collaboration_box = $collaboration_content . $edit_js;
	}
	
	// Add collaboration contributions toggle JavaScript
	$collaboration_box .= '<script type="text/javascript" src="'.$mybb->asset_url.'/inc/plugins/Thread_Collaboration/Assets/collaboration_contributions.js"></script>';
	
	// Set chat button variable for template injection
	$collaboration_chat_button = thread_collaboration_get_chat_button($thread['tid']);
	
	// Set draft button variable for template injection
	$collaboration_draft_button = thread_collaboration_get_draft_button($thread['tid']);
	
	
}
}

if (!function_exists('thread_collaboration_showthread_start'))
{
function thread_collaboration_showthread_start()
{
    global $mybb, $db, $thread, $templates, $lang;
    
    
	// Check if collaboration requests are enabled
	if ($mybb->settings['thread_collaboration_enable_requests'] != '1') {
		return;
	}
	
	// Check if user is logged in and has permission
    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
	if (!$mybb->user['uid'] || !in_array($mybb->user['usergroup'], $allowed_groups)) {
		return;
	}
	
		// Check if collaboration is allowed in current forum
	if (!thread_collaboration_is_forum_allowed($thread['fid'])) {
		return;
	}
	
	// Handle collaborator removal
    if ($mybb->input['action'] == 'remove_collaborator' && $mybb->request_method == 'post') {
		// Ensure we are in showthread.php
        if(defined('THIS_SCRIPT') && THIS_SCRIPT !== 'showthread.php') { error_no_permission(); }
		// Ensure tid in URL matches context thread
		if ((int)$mybb->get_input('tid', MyBB::INPUT_INT) !== (int)$thread['tid']) {
			error_no_permission();
		}
		// Verify POST request
        verify_post_check($mybb->get_input('my_post_key'));
		
        $target_uid = $mybb->get_input('uid', MyBB::INPUT_INT);
		
		// Only thread owner or moderators can remove
		if ($mybb->user['uid'] != $thread['uid'] && !is_moderator($thread['fid'])) {
			error_no_permission();
		}
		
		// Do not allow removing thread owner
		if ($target_uid == $thread['uid']) {
			error("You cannot remove the thread owner as a collaborator.");
		}
		
		// Ensure the user is a collaborator on this thread
        $exists_q = $db->simple_select('thread_collaborators', 'uid', "tid='{$thread['tid']}' AND uid='{$target_uid}'", array('limit' => 1));
		if ($db->num_rows($exists_q) == 0) {
			error("Selected user is not a collaborator on this thread.");
		}
		
		// Remove collaborator
        $db->delete_query('thread_collaborators', "tid='{$thread['tid']}' AND uid='{$target_uid}'");
		
		// Send PM notification to removed user
        require_once MYBB_ROOT."inc/datahandlers/pm.php";
        $pmhandler = new PMDataHandler();
        $pm = array(
            "subject" => "You were removed from a collaboration",
            "message" => "You have been removed as a collaborator from the thread: [url=".$mybb->settings['bburl'].'/'.get_thread_link($thread['tid'])."]".htmlspecialchars_uni($thread['subject'])."[/url].",
            "touid" => (int)$target_uid,
            "fromid" => (int)$mybb->user['uid']
        );
		$pmhandler->set_data(array(
			"pm" => $pm,
			"fromid" => (int)$mybb->user['uid'],
			"subject" => $pm["subject"],
			"message" => $pm["message"],
			"toid" => array($target_uid)
		));
		if($pmhandler->validate_pm()) {
			$pmhandler->insert_pm();
		}
		
		
		
        redirect(get_thread_link($thread['tid']), "Collaborator removed.");
    }
    
	// Quit collaboration for current user
    if ($mybb->input['action'] == 'quit_collaboration' && $mybb->request_method == 'post') {
		// Verify POST request
        verify_post_check($mybb->get_input('my_post_key'));
		
		// Must be a collaborator to quit
        $exists_q = $db->simple_select('thread_collaborators', 'uid', "tid='{$thread['tid']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
		if ($db->num_rows($exists_q) == 0) {
			error("You are not a collaborator on this thread.");
		}
		
		// Remove current user from collaborators
        $db->delete_query('thread_collaborators', "tid='{$thread['tid']}' AND uid='{$mybb->user['uid']}'");
		
		// PM notify thread owner
        require_once MYBB_ROOT."inc/datahandlers/pm.php";
        $pmhandler = new PMDataHandler();
        $pm = array(
            "subject" => "A collaborator has quit",
            "message" => "[url=".$mybb->settings['bburl'].'/'.get_thread_link($thread['tid'])."]".htmlspecialchars_uni($thread['subject'])."[/url]: The user [b]".htmlspecialchars_uni($mybb->user['username'])."[/b] has quit the collaboration.",
            "touid" => (int)$thread['uid'],
            "fromid" => (int)$mybb->user['uid']
        );
		$pmhandler->set_data(array(
			"pm" => $pm,
			"fromid" => (int)$mybb->user['uid'],
			"subject" => $pm["subject"],
			"message" => $pm["message"],
			"toid" => array($thread['uid'])
		));
		if($pmhandler->validate_pm()) {
			$pmhandler->insert_pm();
		}
		
		
		
        redirect(get_thread_link($thread['tid']), "You have quit the collaboration.");
    }
    
	// Handle owner role set
    if ($mybb->input['action'] == 'set_owner_role' && $mybb->request_method == 'post') {
		// Ensure we are in showthread.php and correct thread
        if(defined('THIS_SCRIPT') && THIS_SCRIPT !== 'showthread.php') { error_no_permission(); }
        if ((int)$mybb->get_input('tid', MyBB::INPUT_INT) !== (int)$thread['tid']) { error_no_permission(); }
		
        verify_post_check($mybb->get_input('my_post_key'));
		
		if ($mybb->user['uid'] != $thread['uid']) {
			error_no_permission();
		}
		
        $owner_role = trim($mybb->get_input('owner_role'));
        $owner_role_icon = trim($mybb->get_input('owner_role_icon'));
		
		// Upsert owner role in thread_collaborators for thread owner
        $exists_q = $db->simple_select('thread_collaborators', 'uid', "tid='{$thread['tid']}' AND uid='{$thread['uid']}'", array('limit' => 1));
		$record = array(
			'role' => ($owner_role !== '' ? $db->escape_string($owner_role) : 'Owner'),
			'role_icon' => $db->escape_string($owner_role_icon)
		);
		if ($db->num_rows($exists_q) > 0) {
			$db->update_query('thread_collaborators', $record, "tid='{$thread['tid']}' AND uid='{$thread['uid']}'");
		} else {
			$record['tid'] = (int)$thread['tid'];
			$record['uid'] = (int)$thread['uid'];
			$record['joined_date'] = TIME_NOW;
			$record['joined_via'] = 'direct';
			$record['source_id'] = null;
			$db->insert_query('thread_collaborators', $record);
		}
		
		
		
		redirect(get_thread_link($thread['tid']), "Your role has been updated.");
	}
	
	// Handle edit collaborator
	if ($mybb->input['action'] == 'edit_collaborator' && $mybb->request_method == 'post') {
		// Ensure we are in showthread.php and correct thread
		if(defined('THIS_SCRIPT') && THIS_SCRIPT !== 'showthread.php') { error_no_permission(); }
		if ((int)$mybb->get_input('tid', MyBB::INPUT_INT) !== (int)$thread['tid']) { error_no_permission(); }
		
		// Verify POST request
		verify_post_check($mybb->get_input('my_post_key'));
		
		$edit_uid = $mybb->get_input('edit_uid', MyBB::INPUT_INT);
		$edit_type = $mybb->get_input('edit_type');
		$new_role = trim($mybb->get_input('edit_role'));
		$new_icon = trim($mybb->get_input('edit_icon'));
		$additional_roles = (array)$mybb->get_input('edit_additional_role', MyBB::INPUT_ARRAY);
		$additional_icons = (array)$mybb->get_input('edit_additional_role_icon', MyBB::INPUT_ARRAY);
		
		// Only thread owner or moderators can edit
		if ($mybb->user['uid'] != $thread['uid'] && !is_moderator($thread['fid'])) {
			error_no_permission();
		}
		
		// Validate primary role
		if (empty($new_role)) {
			error("Role cannot be empty.");
		}
		
		// Process all roles (primary + additional)
		$all_roles = array();
		$all_icons = array();
		
		// Add primary role
		$all_roles[] = $new_role;
		$all_icons[] = $new_icon;
		
		// Add additional roles
		foreach ($additional_roles as $index => $additional_role) {
			$additional_role = trim($additional_role);
			if (!empty($additional_role)) {
				$all_roles[] = $additional_role;
				$all_icons[] = isset($additional_icons[$index]) ? trim($additional_icons[$index]) : '';
			}
		}
		
		if ($edit_type == 'collaborator') {
			// Edit existing collaborator - remove all existing roles and add new ones
			$exists_q = $db->simple_select('thread_collaborators', 'uid', "tid='{$thread['tid']}' AND uid='{$edit_uid}'", array('limit' => 1));
			if ($db->num_rows($exists_q) == 0) {
				error("Selected user is not a collaborator on this thread.");
			}
			
			// Remove all existing roles for this user
			$db->delete_query('thread_collaborators', "tid='{$thread['tid']}' AND uid='{$edit_uid}'");
			
			// Add all new roles
			$insert_roles = array();
			foreach ($all_roles as $index => $role) {
				$insert_roles[] = array(
					'tid' => $thread['tid'],
					'uid' => $edit_uid,
					'role' => $db->escape_string($role),
					'role_icon' => $db->escape_string($all_icons[$index]),
					'joined_date' => TIME_NOW,
					'joined_via' => 'edit',
					'source_id' => null
				);
			}
			
			if (!empty($insert_roles)) {
				$db->insert_query_multiple('thread_collaborators', $insert_roles);
			}
			
			redirect(get_thread_link($thread['tid']), "Collaborator roles updated.");
			
		} elseif ($edit_type == 'invitation') {
			// Edit pending invitation - remove all existing invitations and add new ones
			$exists_q = $db->simple_select('collaboration_invitations', 'invite_id', "tid='{$thread['tid']}' AND invitee_uid='{$edit_uid}' AND status='pending'", array('limit' => 1));
			if ($db->num_rows($exists_q) == 0) {
				error("No pending invitation found for this user.");
			}
			
			// Remove all existing pending invitations for this user
			$db->delete_query('collaboration_invitations', "tid='{$thread['tid']}' AND invitee_uid='{$edit_uid}' AND status='pending'");
			
			// Add all new invitations
			$insert_invitations = array();
			foreach ($all_roles as $index => $role) {
				$insert_invitations[] = array(
					'tid' => $thread['tid'],
					'inviter_uid' => $mybb->user['uid'],
					'invitee_uid' => $edit_uid,
					'role' => $db->escape_string($role),
					'role_icon' => $db->escape_string($all_icons[$index]),
					'status' => 'pending',
					'invite_date' => TIME_NOW
				);
			}
			
			if (!empty($insert_invitations)) {
				$db->insert_query_multiple('collaboration_invitations', $insert_invitations);
			}
			
			redirect(get_thread_link($thread['tid']), "Invitation roles updated.");
			
		} elseif ($edit_type == 'request') {
			// Edit pending request - update the existing request
			$request_id = $mybb->get_input('edit_request_id', MyBB::INPUT_INT);
			if (empty($request_id)) {
				error("Request ID is required for editing requests.");
			}
			
			// Verify the request exists and belongs to this thread
			$exists_q = $db->simple_select('collaboration_requests', 'request_id', "request_id='{$request_id}' AND tid='{$thread['tid']}' AND status='pending'", array('limit' => 1));
			if ($db->num_rows($exists_q) == 0) {
				error("No pending request found with this ID.");
			}
			
			// Update the request with the new role and icon (only the first role for requests)
			$update_data = array(
				'role' => $db->escape_string($all_roles[0]),
				'role_icon' => $db->escape_string($all_icons[0])
			);
			
			$db->update_query('collaboration_requests', $update_data, "request_id='{$request_id}'");
			
			redirect(get_thread_link($thread['tid']), "Request role updated.");
			
		} else {
			error("Invalid edit type.");
		}
	}
	
	// Handle revoke invitation
	if ($mybb->input['action'] == 'revoke_invitation' && $mybb->request_method == 'post') {
		// Ensure we are in showthread.php and correct thread
		if(defined('THIS_SCRIPT') && THIS_SCRIPT !== 'showthread.php') { error_no_permission(); }
		if ((int)$mybb->get_input('tid', MyBB::INPUT_INT) !== (int)$thread['tid']) { error_no_permission(); }
		
		// Verify POST request
		verify_post_check($mybb->get_input('my_post_key'));
		
		$revoke_uid = $mybb->get_input('revoke_uid', MyBB::INPUT_INT);
		
		// Check permissions - only thread owner, moderators, or management users can revoke
		$management_groups = array();
		if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
			$management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
		}
		$is_management_user = in_array($mybb->user['usergroup'], $management_groups);
		
		if ($mybb->user['uid'] != $thread['uid'] && !is_moderator($thread['fid']) && !$is_management_user) {
			error_no_permission();
		}
		
		// Check if invitation exists
		$invitation_query = $db->simple_select('collaboration_invitations', 'invite_id, invitee_uid', "tid='{$thread['tid']}' AND invitee_uid='{$revoke_uid}' AND status='pending'", array('limit' => 1));
		if ($db->num_rows($invitation_query) == 0) {
			error("No pending invitation found for this user.");
		}
		
		$invitation = $db->fetch_array($invitation_query);
		
		// Get invitee username for notification
		$invitee_query = $db->simple_select('users', 'username', "uid='{$revoke_uid}'", array('limit' => 1));
		$invitee_username = '';
		if ($db->num_rows($invitee_query) > 0) {
			$invitee_data = $db->fetch_array($invitee_query);
			$invitee_username = $invitee_data['username'];
		}
		
		// Delete the invitation
		$db->delete_query('collaboration_invitations', "invite_id='{$invitation['invite_id']}'");
		
		// Send notification to invitee if they exist
		if (!empty($invitee_username)) {
			// Include PM handler if not already included
			if (!class_exists('PMDataHandler')) {
				require_once MYBB_ROOT . 'inc/datahandlers/pm.php';
			}
			
			$pm_subject = "Collaboration Invitation Revoked: {$thread['subject']}";
			$pm_message = "Your collaboration invitation for the thread \"{$thread['subject']}\" has been revoked by the thread owner.";
			
			$pm = array(
				"subject" => $pm_subject,
				"message" => $pm_message,
				"icon" => "",
				"toid" => array($revoke_uid),
				"fromid" => $mybb->user['uid'],
				"do" => "",
				"pmid" => ""
			);
			
			$pmhandler = new PMDataHandler();
			$pmhandler->set_data($pm);
			if ($pmhandler->validate_pm()) {
				$pmhandler->insert_pm();
			}
		}
		
		redirect(get_thread_link($thread['tid']), "Collaboration invitation has been revoked.");
	}
	
	// Check if this is a revoke request action
	if ($mybb->input['action'] == 'revoke_request') {
		$request_id = (int)$mybb->input['request_id'];
		
		// Verify the request belongs to the current user
		$request_query = $db->simple_select("collaboration_requests", "request_id", "request_id='{$request_id}' AND requester_uid='{$mybb->user['uid']}' AND status='pending'", array('limit' => 1));
		if ($db->num_rows($request_query) == 0) {
			error("Invalid request or request already processed.");
			return;
		}
		
		// Delete the request
		$db->delete_query("collaboration_requests", "request_id='{$request_id}'");
		
		redirect(get_thread_link($thread['tid']), "Collaboration request has been revoked. You can now submit a new request.");
	}
	
	// Check if this is a request collaboration action
	if ($mybb->input['action'] == 'request_collaboration') {
		// User cannot request to collaborate on their own thread
		if ($mybb->user['uid'] == $thread['uid']) {
			error("You cannot request to collaborate on your own thread.");
			return;
		}
		
		// Check if user is already a collaborator
		$existing_query = $db->simple_select("thread_collaborators", "uid", "tid='{$thread['tid']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
		if ($db->num_rows($existing_query) > 0) {
			error("You are already a collaborator on this thread.");
			return;
		}
		
		// Check if user already has a pending request
		$pending_query = $db->simple_select("collaboration_requests", "request_id", "tid='{$thread['tid']}' AND requester_uid='{$mybb->user['uid']}' AND status='pending'", array('limit' => 1));
		if ($db->num_rows($pending_query) > 0) {
			error("You already have a pending collaboration request for this thread.");
			return;
		}
		
		// Handle form submission
		if ($mybb->request_method == "post") {
			thread_collaboration_process_request();
		}
		
		// Display the request form
		$thread_tid = $thread['tid'];
		eval("\$request_form = \"" . $templates->get("threadcollaboration_request_form") . "\";");
		
		// Output the form
		output_page($request_form);
    }
}
}

if (!function_exists('thread_collaboration_showthread_request_button'))
{
function thread_collaboration_showthread_request_button()
{
    global $mybb, $db, $thread, $templates, $lang;
	
	// Check if collaboration requests are enabled
	if ($mybb->settings['thread_collaboration_enable_requests'] != '1') {
		return;
	}
	
	// Check if user is logged in and has permission
    $allowed_groups = explode(',', $mybb->settings['thread_collaboration_allowed_groups']);
	if (!$mybb->user['uid'] || !in_array($mybb->user['usergroup'], $allowed_groups)) {
		return;
	}
	
	// Check if collaboration is allowed in current forum
	if (!thread_collaboration_is_forum_allowed($thread['fid'])) {
		return;
	}
	
	// Don't show button if user is thread owner
	if ($mybb->user['uid'] == $thread['uid']) {
		return;
	}
	
	// Check if thread owner allows collaboration requests for this thread
	if (!thread_collaboration_are_requests_allowed($thread['tid'])) {
		return;
	}
	
	// Don't show button if user is already a collaborator
    $existing_query = $db->simple_select("thread_collaborators", "uid", "tid='{$thread['tid']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
	if ($db->num_rows($existing_query) > 0) {
		return;
	}
	
	// Check if user already has a pending request
    $pending_query = $db->simple_select("collaboration_requests", "request_id, role, role_icon, message, request_date", "tid='{$thread['tid']}' AND requester_uid='{$mybb->user['uid']}' AND status='pending'", array('limit' => 1));
	if ($db->num_rows($pending_query) > 0) {
		// Show pending request with revoke button instead of request button
		$pending_request = $db->fetch_array($pending_query);
		thread_collaboration_show_pending_request($pending_request);
		return;
	}
	
	// Display the request button and modal
    $thread_tid = $thread['tid'];
	
	// Evaluate templates with variables
    eval("\$request_button = \"" . $templates->get("threadcollaboration_request_button") . "\";");
    eval("\$request_modal = \"" . $templates->get("threadcollaboration_request_modal") . "\";");
	
	// Add the button, modal, and JavaScript to the page
	// Load Select2 CSS/JS and the external JavaScript file
	$modal_javascript = '
	<link rel="stylesheet" href="'.$mybb->asset_url.'/jscripts/select2/select2.css?ver=1807">
	<script type="text/javascript" src="'.$mybb->asset_url.'/jscripts/select2/select2.min.js?ver=1806"></script>
	<script type="text/javascript">
	// Set global variable for default roles
	window.threadCollabDefaultRoles = ' . json_encode(thread_collaboration_get_default_roles()) . ';
	</script>
	<script type="text/javascript" src="' . $mybb->settings['bburl'] . '/inc/plugins/Thread_Collaboration/Assets/request_collaboration_modal.js"></script>';
	
    global $collaboration_box;
	$collaboration_box .= $request_button . $request_modal . $modal_javascript;
}
}

if (!function_exists('thread_collaboration_show_pending_request'))
{
function thread_collaboration_show_pending_request($pending_request)
{
    global $mybb, $db, $thread, $templates, $lang;
    
    $lang->load("thread_collaboration");
    
    // Format the pending request data
    $request_id = (int)$pending_request['request_id'];
    $request_role = htmlspecialchars_uni($pending_request['role']);
    $request_role_icon = htmlspecialchars_uni($pending_request['role_icon']);
    $request_message = htmlspecialchars_uni($pending_request['message']);
    $request_date = my_date('relative', $pending_request['request_date']);
    
    // Build role with icon
    $request_role_icon_html = '';
    if (!empty($request_role_icon)) {
        $request_role_icon_html = ' <i class="' . $request_role_icon . '"></i>';
    }
    
    // Create role class for styling
    $role_class = strtolower(str_replace(' ', '-', $request_role));
    
    // Handle message display
    $message_display = '';
    if (!empty($request_message)) {
        $message_display = '<div style="margin-bottom: 8px;"><strong>Message:</strong> <span style="font-style: italic; color: #555;">' . $request_message . '</span></div>';
    }
    
    // Evaluate template
    eval("\$pending_request_display = \"" . $templates->get("threadcollaboration_pending_request") . "\";");
    
    global $collaboration_box;
    $collaboration_box .= $pending_request_display;
}
}

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
		$pm_message = "Hello,\n\n[b]{".$mybb->user['username'] ."}[/b] has requested to collaborate on your thread [b]{".$clickable_thread_link."}[/b] as a [b]{".$role."}[/b].\n\n";
		
		if (!empty($message)) {
			$pm_message .= "Message from [b]{".$mybb->user['username'] ."}[/b]:\n{".$message."}\n\n";
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
