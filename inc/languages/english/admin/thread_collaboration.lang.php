<?php
/**
 * Thread Collaboration Plugin - Admin Language File
 * English Language Pack
 */
 $l['thread_collaboration_name'] = 'Thread Collaboration';
$l['thread_collaboration_desc'] = 'Collaborative tools for managing thread contributions.';
$l['thread_collaboration_setting_group'] = 'Thread Collaboration';
$l['thread_collaboration_setting_group_desc'] = 'Settings for Thread Collaboration plugin';
$l['thread_collaboration_enabled'] = 'Enable Collaboration';
$l['thread_collaboration_enabled_desc'] = 'Turn the plugin features on or off.';


$l['thread_collaboration'] = "Thread Collaboration";
$l['thread_collaboration_desc'] = "Allows users to collaborate on threads by adding collaborators with specific roles.";

// Settings
$l['thread_collaboration_settings'] = "Thread Collaboration Settings";
$l['thread_collaboration_settings_desc'] = "Configure thread collaboration features.";

$l['thread_collaboration_enabled'] = "Enable Thread Collaboration";
$l['thread_collaboration_enabled_desc'] = "Enable or disable the thread collaboration feature.";

$l['thread_collaboration_allowed_groups'] = "Allowed User Groups";
$l['thread_collaboration_allowed_groups_desc'] = "Comma-separated list of user group IDs that can use collaboration features.";

$l['thread_collaboration_allowed_forums'] = "Allowed Forums";
$l['thread_collaboration_allowed_forums_desc'] = "Comma-separated list of forum IDs where collaboration is allowed. Leave empty for all forums.";

$l['thread_collaboration_max_collaborators'] = "Maximum Collaborators";
$l['thread_collaboration_max_collaborators_desc'] = "Maximum number of collaborators allowed per thread. Set to 0 for unlimited.";

$l['thread_collaboration_default_roles'] = "Default Roles";
$l['thread_collaboration_default_roles_desc'] = "Default roles available for collaborators. Format: Role Name|Icon Class (one per line). Example: Writer|fa-pen, Artist|fa-palette";

$l['thread_collaboration_show_in_postbit'] = "Show in Postbit";
$l['thread_collaboration_show_in_postbit_desc'] = "Show collaborator information in the postbit area.";

$l['thread_collaboration_show_in_thread'] = "Show in Thread";
$l['thread_collaboration_show_in_thread_desc'] = "Show collaborator information at the top of threads.";

$l['thread_collaboration_pm_notifications'] = "PM Notifications";
$l['thread_collaboration_pm_notifications_desc'] = "Send private message notifications to collaborators when they are added.";

$l['thread_collaboration_pm_on_update'] = "PM Notifications on Update";
$l['thread_collaboration_pm_on_update_desc'] = "Send private message notifications when collaborator roles are updated (in addition to when they are first added).";

$l['thread_collaboration_custom_pm_message'] = "Custom PM Message Template";
$l['thread_collaboration_custom_pm_message_desc'] = "Customize the private message sent to collaborators. Use {1} for inviter username, {2} for thread subject, {3} for role, {4} for clickable thread link, {5} for UCP collaboration invitations URL. Leave empty to use default message.";

// Error Messages
$l['thread_collaboration_error_invalid_username'] = "Invalid username: {1}";
$l['thread_collaboration_error_invalid_role'] = "Invalid role: {1}";
$l['thread_collaboration_error_invalid_icon'] = "Invalid icon: {1}";
$l['thread_collaboration_error_user_not_found'] = "User not found: {1}";
$l['thread_collaboration_error_max_collaborators'] = "Maximum number of collaborators ({1}) reached.";
$l['thread_collaboration_error_db'] = "Database error: {1}";
$l['thread_collaboration_error_self_invitation'] = "You cannot invite yourself as a collaborator.";
$l['thread_collaboration_error_already_collaborator'] = "User '{1}' is already a collaborator on this thread.";

// Success Messages
$l['thread_collaboration_success_collaborator_added'] = "Collaborator added successfully.";
$l['thread_collaboration_success_collaborator_removed'] = "Collaborator removed successfully.";
$l['thread_collaboration_success_collaborator_updated'] = "Collaborator updated successfully.";

// PM Messages
$l['thread_collaboration_pm_subject'] = "You have been added as a collaborator on: {1}";
$l['thread_collaboration_pm_message'] = "Hello,

{1} has added you as a collaborator on the thread [b]{4}[/b] with the role of  [b]{3}[/b]

You can [b]accept[/b] or [b]deny[/b] the invitation in collaboration setting page in your user cp. [b]{2}[/b] thread waiting for your magic.

Best regards,
{1}";

// PM Notification Messages
$l['thread_collaboration_pm_sent_notification'] = "Private message notifications have been sent to {1} collaborator(s).";

// Admin Actions
$l['thread_collaboration_manage_collaborators'] = "Manage Collaborators";
$l['thread_collaboration_add_collaborator'] = "Add Collaborator";
$l['thread_collaboration_edit_collaborator'] = "Edit Collaborator";
$l['thread_collaboration_remove_collaborator'] = "Remove Collaborator";

// Form Labels
$l['thread_collaboration_username'] = "Username";
$l['thread_collaboration_role'] = "Role";
$l['thread_collaboration_role_icon'] = "Role Icon";
$l['thread_collaboration_thread'] = "Thread";
$l['thread_collaboration_forum'] = "Forum";
$l['thread_collaboration_date_added'] = "Date Added";

// Buttons
$l['thread_collaboration_submit'] = "Submit";
$l['thread_collaboration_cancel'] = "Cancel";
$l['thread_collaboration_delete'] = "Delete";
$l['thread_collaboration_edit'] = "Edit";

// Confirmations
$l['thread_collaboration_confirm_delete'] = "Are you sure you want to remove this collaborator?";
$l['thread_collaboration_confirm_delete_all'] = "Are you sure you want to remove all collaborators from this thread?";

// Statistics
$l['thread_collaboration_total_collaborators'] = "Total Collaborators";
$l['thread_collaboration_total_threads'] = "Total Collaboration Threads";
$l['thread_collaboration_most_active_role'] = "Most Active Role";

// Permissions
$l['thread_collaboration_can_add_collaborators'] = "Can add collaborators to threads";
$l['thread_collaboration_can_remove_collaborators'] = "Can remove collaborators from threads";
$l['thread_collaboration_can_edit_collaborators'] = "Can edit collaborator roles";
$l['thread_collaboration_can_view_collaborators'] = "Can view collaborator information";

// Help
$l['thread_collaboration_help'] = "Help";
$l['thread_collaboration_help_desc'] = "Learn how to use the Thread Collaboration plugin.";

$l['thread_collaboration_help_text'] = "
<h3>Thread Collaboration Plugin Help</h3>

<p><strong>What is Thread Collaboration?</strong></p>
<p>Thread Collaboration allows users to work together on threads by adding collaborators with specific roles such as writers, artists, designers, etc.</p>

<p><strong>How to use:</strong></p>
<ol>
<li>When creating a new thread, users can add collaborators by entering their usernames and roles</li>
<li>Collaborators will receive PM notifications when added</li>
<li>Collaborator information is displayed in threads and optionally in postbits</li>
</ol>

<p><strong>Configuration:</strong></p>
<ul>
<li>Set allowed user groups who can use collaboration features</li>
<li>Specify which forums allow collaboration</li>
<li>Set maximum number of collaborators per thread</li>
<li>Define default roles with icons</li>
</ul>
";
?> 