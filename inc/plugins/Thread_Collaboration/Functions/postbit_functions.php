<?php
// Disallow direct access
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Main postbit function - displays collaborator roles in postbit
if (!function_exists('thread_collaboration_postbit'))
{
function thread_collaboration_postbit(&$post)
{
    global $mybb, $db, $thread, $templates;
    
    // Safety check: ensure $post is an array
    if (!is_array($post)) {
        return;
    }
    
    // Only proceed if we have a thread and the user is logged in
    if (!isset($thread['tid']) || !isset($post['uid']) || $post['uid'] == 0) {
        return;
    }
    
    // Check if collaboration is allowed in current forum
    if (!thread_collaboration_is_forum_allowed($thread['fid'])) {
        return;
    }
    
    // Check if showing roles in postbit is enabled
    if (!$mybb->settings['thread_collaboration_show_in_postbit']) {
        return;
    }

    // Decide whether to show owner role even without collaborators (yes/no setting)
    $show_owner_role_always = !empty($mybb->settings['thread_collaboration_show_owner_role_mode']);
    
    // Check if we're using postbit_classic template
    $is_postbit_classic = false;
    if (isset($mybb->settings['postlayout']) && $mybb->settings['postlayout'] == 'classic') {
        $is_postbit_classic = true;
    }
    
    // Check if this user is a collaborator in this thread
    try {
        // Get ALL roles for this user in this thread
        $query = $db->simple_select("thread_collaborators", "role, role_icon", "tid='{$thread['tid']}' AND uid='{$post['uid']}'");
        
        if ($query && $db->num_rows($query) > 0) {
            // Get user's role display preferences
            $user_settings = $db->simple_select('collaboration_user_settings', 'show_all_roles, postbit_roles', "uid='{$post['uid']}'", array('limit' => 1));
            $show_all_roles = true;
            $selected_roles = array();
            
            if ($db->num_rows($user_settings) > 0) {
                $settings = $db->fetch_array($user_settings);
                if ($settings['show_all_roles'] == 0 && !empty($settings['postbit_roles'])) {
                    $show_all_roles = false;
                    $selected_roles = explode(',', $settings['postbit_roles']);
                }
            }
            
            // Collect all roles to display
            $roles_to_display = array();
            $role_index = 0;
            
            while ($collaborator = $db->fetch_array($query)) {
                $role_index++;
            $role_name = htmlspecialchars_uni($collaborator['role']);
            $role_icon = htmlspecialchars_uni($collaborator['role_icon']);
                
                // Check if this role should be displayed
                $show_role = true;
                if (!$show_all_roles && !empty($selected_roles)) {
                    if (!in_array($role_index, $selected_roles)) {
                        $show_role = false;
                    }
                }
                
                if ($show_role) {
                    $roles_to_display[] = array(
                        'name' => $role_name,
                        'icon' => $role_icon
                    );
                }
            }
            
            // If no roles to display, return
            if (empty($roles_to_display)) {
                return;
            }
            
            // Process all roles to display
            $role_parts = array();
            
            foreach ($roles_to_display as $role_data) {
                $role_name = $role_data['name'];
                $role_icon = $role_data['icon'];
            
            // Fallbacks for missing icon: accepted invitations -> approved requests -> default roles map
            if (empty($role_icon)) {
                // Accepted invitation icon
                $inv = $db->simple_select('collaboration_invitations', 'role_icon', "tid='{$thread['tid']}' AND invitee_uid='{$post['uid']}' AND status='accepted'", array('order_by' => 'invite_id', 'order_dir' => 'DESC', 'limit' => 1));
                if ($inv && $db->num_rows($inv) > 0) {
                    $inv_row = $db->fetch_array($inv);
                    if (!empty($inv_row['role_icon'])) {
                        $role_icon = htmlspecialchars_uni($inv_row['role_icon']);
                    }
                }
            }
            if (empty($role_icon)) {
                // Approved request icon
                $req = $db->simple_select('collaboration_requests', 'role_icon', "tid='{$thread['tid']}' AND requester_uid='{$post['uid']}' AND status='approved'", array('order_by' => 'request_id', 'order_dir' => 'DESC', 'limit' => 1));
                if ($req && $db->num_rows($req) > 0) {
                    $req_row = $db->fetch_array($req);
                    if (!empty($req_row['role_icon'])) {
                        $role_icon = htmlspecialchars_uni($req_row['role_icon']);
                    }
                }
            }
            if (empty($role_icon)) {
                // Map from default roles setting
                $defaults = thread_collaboration_get_default_roles();
                foreach ($defaults as $def) {
                    if (mb_strtolower($def['name']) == mb_strtolower($role_name)) {
                        $role_icon = htmlspecialchars_uni($def['icon']);
                        break;
                    }
                }
            }
            
                // Generate dynamic role class and default class
                $role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Graphic Designer" to "graphic-designer"
                $combined_class = 'default-role ' . $role_class;
                
                // Add role part with name and icon
                $role_parts[] = array(
                    'name' => htmlspecialchars_uni($role_name),
                    'icon' => htmlspecialchars_uni($role_icon),
                    'class' => htmlspecialchars_uni($combined_class)
                );
            }
            
            // Generate single role display with all roles
            $tpl = $templates->get("threadcollaboration_postbit_role");
            if (!$tpl) {
                // Fallback if template doesn't exist
                $roles_html = array();
                foreach ($role_parts as $role) {
                    $roles_html[] = '<span class="collaborator-role ' . $role['class'] . '"><strong>' . $role['name'] . '</strong> <i class="' . $role['icon'] . '"></i></span>';
                }
                
                if ($is_postbit_classic) {
                    // For postbit_classic: display roles vertically without "Role:" label
                    $post['collaborator_role'] = '<div class="collaborator-roles" style="margin: 2px 0; display: block;">' . implode('<br />', $roles_html) . '</div>';
            } else {
                    // For regular postbit: display roles horizontally with "Role:" label
                    $post['collaborator_role'] = '<span class="collaborator-roles" style="margin: 2px 0"><strong>Roles:</strong> ' . implode(', ', $roles_html) . '</span><br />';
                }
            } else {
                // Build roles string
                $roles_html = array();
                foreach ($role_parts as $role) {
                    $role_name = $role['name'];
                    $role_icon = $role['icon'];
                    $combined_class = $role['class'];
                    eval("\$role_html = \"" . $tpl . "\";");
                    $roles_html[] = $role_html;
                }
                
                if ($is_postbit_classic) {
                    // For postbit_classic: display roles vertically without "Role:" label
                    $post['collaborator_role'] = '<div class="collaborator-roles" style="margin: 2px 0; display: block;">' . implode('<br />', $roles_html) . '</div>';
                } else {
                    // For regular postbit: display roles horizontally with "Role:" label
                    $post['collaborator_role'] = '<span class="collaborator-roles" style="margin: 2px 0"><strong>Roles:</strong> ' . implode(', ', $roles_html) . '</span><br />';
                }
            }
            

        		} else {
			// If thread owner has no explicit role, show default Owner role
			if ((int)$post['uid'] === (int)$thread['uid'] && ($show_owner_role_always || (int)$db->fetch_field($db->simple_select('thread_collaborators', 'COUNT(*) AS c', "tid='{$thread['tid']}'"), 'c') > 0)) {
				$role_name = 'Owner';
				$role_icon = '';
				// Prefer user-specific owner icon, otherwise admin default
				$owner_icon_row = $db->fetch_array($db->simple_select('collaboration_user_settings', 'owner_icon', "uid='{$post['uid']}'", array('limit' => 1)));
				if (!empty($owner_icon_row['owner_icon'])) {
					$role_icon = htmlspecialchars_uni($owner_icon_row['owner_icon']);
				} elseif (!empty($mybb->settings['thread_collaboration_owner_default_icon'])) {
					$role_icon = htmlspecialchars_uni($mybb->settings['thread_collaboration_owner_default_icon']);
				}
				
				// Generate dynamic role class and default class for owner
				$role_class = strtolower(str_replace(' ', '-', $role_name)); // Convert "Owner" to "owner"
				$combined_class = 'default-role ' . $role_class;
				
				$tpl = $templates->get("threadcollaboration_postbit_role");
				if (!$tpl) {
					$role_html = '<span class="collaborator-role ' . $combined_class . '"><strong>' . $role_name . '</strong> ' . (!empty($role_icon) ? '<i class="' . $role_icon . '"></i>' : '') . '</span>';
					
					if ($is_postbit_classic) {
						// For postbit_classic: display role vertically without "Role:" label
						$post['collaborator_role'] = '<div class="collaborator-roles" style="margin: 2px 0; display: block;">' . $role_html . '</div>';
				} else {
						// For regular postbit: display role horizontally with "Role:" label
						$post['collaborator_role'] = '<span class="collaborator-roles" style="margin: 2px 0"><strong>Role:</strong> ' . $role_html . '</span><br />';
					}
				} else {
					// Set template variables for the role display
					$role_name_escaped = htmlspecialchars_uni($role_name);
					$role_icon_escaped = htmlspecialchars_uni($role_icon);
					$combined_class_escaped = htmlspecialchars_uni($combined_class);
					eval("\$role_html = \"" . $tpl . "\";");
					
					if ($is_postbit_classic) {
						// For postbit_classic: display role vertically without "Role:" label
						$post['collaborator_role'] = '<div class="collaborator-roles" style="margin: 2px 0; display: block;">' . $role_html . '</div>';
					} else {
						// For regular postbit: display role horizontally with "Role:" label
						$post['collaborator_role'] = '<span class="collaborator-roles" style="margin: 2px 0"><strong>Role:</strong> ' . $role_html . '</span><br />';
					}
				}

			} else {
				// Set empty collaborator role if user is not a collaborator
				$post['collaborator_role'] = '';
			}
		}
		
		// Add edit history button for collaborators (separate from role display)
		$post['collaborator_edit_history'] = ''; // Initialize as empty
		
		// Check if current user can see edit history
		$can_see_edit_history = false;
		
		if ($post['uid'] && $mybb->user['uid']) {
			// Check if current user is thread owner
			$is_thread_owner = ($mybb->user['uid'] == $thread['uid']);
			
			// Check if current user is a collaborator in this thread
			$is_current_user_collaborator = false;
			$collaborator_query = $db->simple_select("thread_collaborators", "cid", "tid='{$thread['tid']}' AND uid='{$mybb->user['uid']}'", array('limit' => 1));
			if ($collaborator_query && $db->num_rows($collaborator_query) > 0) {
				$is_current_user_collaborator = true;
			}
			
			// Check if current user is in moderator/management usergroups
			$is_management_user = false;
			$management_groups = array();
			if (!empty($mybb->settings['thread_collaboration_moderator_usergroups'])) {
				$management_groups = explode(',', $mybb->settings['thread_collaboration_moderator_usergroups']);
			}
			if (in_array($mybb->user['usergroup'], $management_groups)) {
				$is_management_user = true;
			}
			
			// Check if there are any collaborators in this thread (for thread owner to see their own post history)
			$has_collaborators = false;
			$collaborators_count_query = $db->simple_select("thread_collaborators", "COUNT(*) as count", "tid='{$thread['tid']}'");
			$collaborators_count = $db->fetch_array($collaborators_count_query);
			if ($collaborators_count['count'] > 0) {
				$has_collaborators = true;
			}
			
        // Determine if user can see edit history
        if ($is_thread_owner && $has_collaborators) {
            // Thread owner can see edit history on all posts if there are collaborators (they are default collaborator)
            $can_see_edit_history = true;
        } elseif ($is_management_user) {
            // Management users can see edit history on all posts
            $can_see_edit_history = true;
        } elseif ($is_current_user_collaborator) {
            // Collaborators can see edit history on all posts (including their own)
            $can_see_edit_history = true;
        }
			
			// Show edit history button if user can see it
			if ($can_see_edit_history) {
				$edit_history_url = "collaboration_edit_history.php?pid={$post['pid']}&tid={$thread['tid']}";
				$post['collaborator_edit_history'] = '<a href="' . $edit_history_url . '" class="collaboration-edit-history-btn" title="View Edit History" style="font-size: 11px; color: #666; text-decoration: none; margin-left: 5px;"><i class="fas fa-history"></i> History</a>';
			}
		}
		
	} catch (Exception $e) {
        // Log error but don't break the page
        error_log("Thread Collaboration Plugin Error in postbit: " . $e->getMessage());
        $post['collaborator_role'] = '';
        $post['collaborator_edit_history'] = '';
        $post['collaboration_contributions'] = '';
    }
    
    // Add contribution display for this post
    if (function_exists('thread_collaboration_get_post_contributions') && is_array($post) && isset($post['pid'])) {
        $post['collaboration_contributions'] = thread_collaboration_get_post_contributions($post['pid'], $thread['tid']);
    } else {
        $post['collaboration_contributions'] = '';
    }
    
}
}

// Handle postbit role display settings update
if (!function_exists('thread_collaboration_handle_postbit_settings_update'))
{
function thread_collaboration_handle_postbit_settings_update()
{
    global $mybb, $db;
    
    // Handle postbit role display settings update
    if (isset($mybb->input['update_postbit_roles']) && $mybb->input['update_postbit_roles'] == 1) {
        $show_all_roles = (int)$mybb->input['show_all_roles'];
        $selected_roles = $mybb->input['selected_roles'];
        
        
        // Prepare selected roles data
        $postbit_roles = '';
        if ($show_all_roles == 0 && !empty($selected_roles)) {
            $postbit_roles = implode(',', array_map('intval', $selected_roles));
        }
        
        $existing = $db->simple_select('collaboration_user_settings', 'uid', "uid='{$mybb->user['uid']}'");
        if ($db->num_rows($existing) > 0) {
            $db->update_query('collaboration_user_settings', array(
                'show_all_roles' => $show_all_roles,
                'postbit_roles' => $postbit_roles,
                'updated_date' => TIME_NOW
            ), "uid='{$mybb->user['uid']}'");
        } else {
            $db->insert_query('collaboration_user_settings', array(
                'uid' => $mybb->user['uid'],
                'show_all_roles' => $show_all_roles,
                'postbit_roles' => $postbit_roles,
                'updated_date' => TIME_NOW
            ));
        }
        
        redirect("usercp.php?action=collaboration_invitations", "Postbit role display settings updated successfully.");
    }
}
}

// Generate postbit role display settings for User CP
if (!function_exists('thread_collaboration_generate_postbit_settings'))
{
function thread_collaboration_generate_postbit_settings($user_roles, $user_settings)
{
    global $mybb, $db;
    
    $available_roles_checkboxes = '';
    $selected_roles = array();
    
    if (!empty($user_roles)) {
        // Get user's selected roles for postbit display
        if ($db->num_rows($user_settings) > 0) {
            $settings = $db->fetch_array($user_settings);
            if (!empty($settings['postbit_roles'])) {
                $selected_roles = explode(',', $settings['postbit_roles']);
            }
        }
        
        // Generate checkboxes for available roles
        foreach ($user_roles as $index => $role_data) {
            $role_id = $index + 1; // Simple ID based on array position
            $checked = '';
            if (in_array($role_id, $selected_roles)) {
                $checked = 'checked';
            }
            
            $role_name = htmlspecialchars_uni($role_data['role']);
            $role_icon = htmlspecialchars_uni($role_data['role_icon']);
            $thread_subject = htmlspecialchars_uni($role_data['subject']);
            
            $icon_html = '';
            if (!empty($role_icon)) {
                $icon_html = ' <i class="' . $role_icon . '"></i>';
            }
            
            $available_roles_checkboxes .= '<label style="display: block; margin: 5px 0;">
                <input type="checkbox" name="selected_roles[]" value="' . $role_id . '" ' . $checked . ' />
                ' . $role_name . $icon_html . ' <small>(in: ' . $thread_subject . ')</small>
            </label>';
        }
    }
    
    if (empty($available_roles_checkboxes)) {
        $available_roles_checkboxes = '<p><em>You don\'t have any collaboration roles yet.</em></p>';
    }
    
    return $available_roles_checkboxes;
}
}

// Generate postbit role display visibility settings
if (!function_exists('thread_collaboration_generate_postbit_visibility_settings'))
{
function thread_collaboration_generate_postbit_visibility_settings($user_roles)
{
    // Check if user has multiple roles (more than 1 unique role)
    $has_multiple_roles = (count($user_roles) > 1);
    $has_multiple_roles_display = $has_multiple_roles ? 'table-row' : 'none';
    $no_multiple_roles_display = $has_multiple_roles ? 'none' : 'table-row';
    $role_count = count($user_roles);
    
    // Set appropriate message for users without multiple roles
    if ($role_count == 0) {
        $no_multiple_roles_message = 'Postbit Role Display settings are only available when you have multiple collaboration roles. You don\'t have any collaboration roles yet.';
    } elseif ($role_count == 1) {
        $no_multiple_roles_message = 'Postbit Role Display settings are only available when you have multiple collaboration roles. Currently, you have 1 role.';
    } else {
        $no_multiple_roles_message = 'Postbit Role Display settings are only available when you have multiple collaboration roles. Currently, you have ' . $role_count . ' roles.';
    }
    
    return array(
        'has_multiple_roles' => $has_multiple_roles,
        'has_multiple_roles_display' => $has_multiple_roles_display,
        'no_multiple_roles_display' => $no_multiple_roles_display,
        'role_count' => $role_count,
        'no_multiple_roles_message' => $no_multiple_roles_message
    );
}
}
