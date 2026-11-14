<?php
/**
 * Thread Collaboration Plugin Language File
 * English Language
 */
$l['thread_collaboration_panel_title'] = 'Collaboration';
$l['thread_collaboration_no_items'] = 'Nothing to show yet.';
$l['thread_collaboration_request_access'] = 'Request collaboration access';

$l['thread_collaboration'] = 'Thread Collaboration';

// Error messages
$l['thread_collaboration_error_max_collaborators'] = 'Maximum number of collaborators ({1}) reached for this thread.';
$l['thread_collaboration_error_invalid_username'] = 'Invalid username: {1}. Username must be between 3 and 50 characters.';
$l['thread_collaboration_error_invalid_role'] = 'Invalid role: {1}. Role must be between 1 and 100 characters.';
$l['thread_collaboration_error_invalid_icon'] = 'Invalid icon: {1}. Icon must be between 1 and 100 characters.';
$l['thread_collaboration_error_self_invitation'] = 'You cannot invite yourself to collaborate on your own thread.';
$l['thread_collaboration_error_already_collaborator'] = 'User {1} is already a collaborator on this thread.';
$l['thread_collaboration_error_user_not_found'] = 'User {1} not found.';
$l['thread_collaboration_error_invitation_exists'] = 'User {1} already has a pending invitation for this thread.';
$l['thread_collaboration_error_db'] = 'Database error: {1}';

// PM messages
$l['thread_collaboration_pm_subject'] = 'Collaboration Invitation: {1}';
$l['thread_collaboration_pm_message'] = 'Hello!

You have been invited by {1} to collaborate on the thread "{2}" with the role of {3}.

You can view the thread here: {4}

This is a direct collaboration invitation. You are now a collaborator on this thread.

Best regards,
{1}';

$l['thread_collaboration_invitation_pm_subject'] = 'Collaboration Invitation: {1}';
$l['thread_collaboration_invitation_pm_message'] = 'Hello,

[b]{1}[/b] has invited you to collaborate on the thread [b]{4}[/b] with the role of [b]{3}[/b].

To accept or decline this invitation, please visit your User Control Panel and go to {5}.

The [b]{2}[/b] thread is waiting for your contribution!

Best regards,
[b]{1}[/b]';

$l['thread_collaboration_acceptance_pm_subject'] = 'Collaboration Invitation Accepted: {1}';
$l['thread_collaboration_acceptance_pm_message'] = 'Hello!

[b]{1}[/b] has accepted your collaboration invitation for the thread [b]{4}[/b] with the role of [b]{3}[/b].

[b]{1}[/b] is now a collaborator on your thread.

Best regards,
[b]MyBB Thread Collaboration System[/b]';

$l['thread_collaboration_declination_pm_subject'] = 'Collaboration Invitation Declined: {1}';
$l['thread_collaboration_declination_pm_message'] = 'Hello!

[b]{1}[/b] has declined your collaboration invitation for the thread [b]{4}[/b] with the role of [b]{3}[/b].

[b]{1}[/b] will not be added as a collaborator on your thread.

Best regards,
[b]MyBB Thread Collaboration System[/b]';

// Edit approval workflow (used on editpost protect flow)
$l['collab_edit_pending'] = 'Pending Post Edit Requests';
$l['collab_edit_thread'] = 'Thread';
$l['collab_edit_preview'] = 'Edit preview';
$l['collab_edit_actions'] = 'Actions';
$l['collab_edit_by'] = 'Edited by';
$l['collab_edit_approve'] = 'Approve';
$l['collab_edit_reject'] = 'Reject';
$l['collab_edit_none_pending'] = 'No pending edit requests.';
$l['collab_edit_saved_as_draft'] = 'Your edit was saved as a draft and sent to the post owner for approval.';
$l['collab_edit_pm_subject'] = '{1} edited your post in thread "{2}"';
$l['collab_edit_pm_message'] = 'A collaborator ({1}) edited your post. Review and approve/reject it in User CP → Collaboration Settings.';
$l['collab_edit_pm_approved_subject'] = 'Your edit was approved';
$l['collab_edit_pm_approved_message'] = 'Your submitted edit was approved by {1} and has been applied.';
$l['collab_edit_pm_rejected_subject'] = 'Your edit was rejected';
$l['collab_edit_pm_rejected_message'] = 'Your submitted edit was rejected by {1} and was not applied.';
$l['collab_edit_approved_success'] = 'The edit has been approved and applied.';
$l['collab_edit_rejected_success'] = 'The edit has been rejected.';

// Navigation and UI
$l['ucp_nav_collaboration_invitations'] = 'Collaboration Invitations';
$l['collaboration_invitations'] = 'Collaboration Invitations';
$l['no_pending_invitations'] = 'You have no pending collaboration invitations.';

// Collaboration Requests
$l['collaboration_requests'] = 'Collaboration Requests';
$l['no_pending_requests'] = 'You have no pending collaboration requests.';
$l['request_statistics'] = 'Request Statistics';
$l['request_summary'] = 'Request Summary';
$l['total_requests_sent'] = 'Total Requests Sent';
$l['pending_requests_sent'] = 'Pending Requests Sent';
$l['approved_requests_sent'] = 'Approved Requests Sent';
$l['rejected_requests_sent'] = 'Rejected Requests Sent';
$l['requests_received_summary'] = 'Requests Received Summary';
$l['total_requests_received'] = 'Total Requests Received';
$l['pending_requests_received'] = 'Pending Requests Received';
$l['approved_requests_received'] = 'Approved Requests Received';
$l['rejected_requests_received'] = 'Rejected Requests Received';

// Statistics
$l['collaboration_statistics'] = 'Collaboration Statistics';
$l['invitation_summary'] = 'Invitation Summary';
$l['collaboration_summary'] = 'Collaboration Summary';
$l['total_invitations'] = 'Total Invitations';
$l['pending_invitations'] = 'Pending';
$l['accepted_invitations'] = 'Accepted';
$l['declined_invitations'] = 'Declined';
$l['total_collaborations'] = 'Total Collaborations';
$l['unique_roles'] = 'Unique Roles';
$l['unique_threads'] = 'Unique Threads';
$l['unique_inviters'] = 'Unique Inviters';
$l['roles_played'] = 'Roles You\'ve Played';
$l['people_invited_you'] = 'People Who Invited You';
$l['threads_collaborated'] = 'Threads You\'ve Collaborated On';

// Inviter Statistics (for thread creators)
$l['inviter_statistics'] = 'Inviter Statistics';
$l['invitations_sent_summary'] = 'Invitations Sent Summary';
$l['total_invitations_sent'] = 'Total Invitations Sent';
$l['pending_invitations_sent'] = 'Pending Sent';
$l['accepted_invitations_sent'] = 'Accepted Sent';
$l['declined_invitations_sent'] = 'Declined Sent';
$l['total_collaborators_gained'] = 'Total Collaborators Gained';
$l['unique_threads_with_collaborators'] = 'Unique Threads with Collaborators';
$l['unique_roles_assigned'] = 'Unique Roles Assigned';
$l['collaborators_gained'] = 'Collaborators You\'ve Gained';
$l['threads_with_collaborators'] = 'Threads Where You Have Collaborators';
$l['roles_you_assigned'] = 'Roles You\'ve Assigned';

// Settings descriptions
$l['thread_collaboration_invitation_system_desc'] = 'Enable the collaboration invitation system where users must accept invitations before becoming collaborators.';
$l['thread_collaboration_invitation_expiry_desc'] = 'Number of days after which pending invitations automatically expire (0 for no expiry).';
$l['thread_collaboration_auto_cleanup_desc'] = 'Automatically remove expired invitations from the database.';

// Pending Invitations Display
$l['pending_collaboration_invitations'] = 'Pending Collaboration Invitations';
$l['invited_on'] = 'Invited';
$l['pending_invitations_view_groups'] = 'Pending Invitations View Groups';
$l['pending_invitations_view_groups_desc'] = 'Select which usergroups can see pending collaboration invitations. Default: Administrators, Super Moderators, Moderators, and Thread Creators.';

// Edit History Page
$l['edit_history_title'] = 'Edit History';
$l['edit_history_thread'] = 'Thread:';
$l['edit_history_post_id'] = 'Post ID:';
$l['edit_history_return_to_post'] = 'Return to Post';
$l['edit_history_changes_comparison'] = 'Changes Comparison';
$l['edit_history_previous_version'] = 'Previous Version';
$l['edit_history_new_version'] = 'New Version';
$l['edit_history_close'] = 'Close';
$l['edit_history_confirm_restore'] = 'Confirm Restore';
$l['edit_history_restore_warning'] = 'Are you sure you want to restore this post to the previous version?';
$l['edit_history_restore_warning_detail'] = 'Warning: This action will overwrite the current content and cannot be undone.';
$l['edit_history_cancel'] = 'Cancel';
$l['edit_history_yes_restore'] = 'Yes, Restore';
$l['edit_history_by'] = 'by';
$l['edit_history_view_changes'] = 'View changes';
$l['edit_history_view_diff'] = 'View Diff';
$l['edit_history_content_preview'] = 'Content Preview:';
$l['edit_history_no_history'] = 'No edit history found for this post.';

// Collaboration Chat
$l['collaboration_chat_room'] = 'Chat Room';
$l['collaboration_chat_title'] = 'Collaboration Chat';
$l['collaboration_chat_subtitle'] = 'Discuss your collaboration work with other team members';
$l['collaboration_chat_message_placeholder'] = 'Type your message here...';
$l['collaboration_chat_send'] = 'Send';
$l['collaboration_chat_online'] = 'Online';
$l['collaboration_chat_typing'] = 'is typing...';
$l['collaboration_chat_no_messages'] = 'No messages yet. Start the conversation!';
$l['collaboration_chat_system_joined'] = 'joined the chat';
$l['collaboration_chat_system_left'] = 'left the chat';
$l['collaboration_chat_system_role_changed'] = 'role changed to';
$l['collaboration_chat_system_collaborator_added'] = 'was added as a collaborator';
$l['collaboration_chat_system_collaborator_removed'] = 'was removed as a collaborator';
$l['collaboration_chat_error_no_permission'] = 'You do not have permission to access this chat room.';
$l['collaboration_chat_error_invalid_thread'] = 'Invalid thread specified.';
$l['collaboration_chat_error_message_empty'] = 'Message cannot be empty.';
$l['collaboration_chat_error_message_too_long'] = 'Message is too long. Maximum 1000 characters allowed.';
$l['collaboration_chat_draft_post'] = 'Draft Post';

// Draft system language strings
$l['collaboration_draft_title'] = 'Collaborative Draft';
$l['collaboration_draft_subtitle'] = 'Write and edit posts together with your team';
$l['collaboration_draft_back_to_chat'] = 'Back to Chat';
$l['collaboration_draft_view_thread'] = 'View Thread';
$l['collaboration_draft_drafts'] = 'Drafts';
$l['collaboration_draft_loading'] = 'Loading drafts...';
$l['collaboration_draft_new_draft'] = 'New Draft';
$l['collaboration_draft_collaborators'] = 'Collaborators';
$l['collaboration_draft_you'] = 'You';
$l['collaboration_draft_subject_placeholder'] = 'Enter post subject...';
$l['collaboration_draft_save'] = 'Save';
$l['collaboration_draft_publish'] = 'Publish';
$l['collaboration_draft_cancel'] = 'Cancel';
$l['collaboration_draft_content_placeholder'] = 'Write your collaborative post here...';
$l['collaboration_draft_contributions'] = 'Contributions';
$l['collaboration_draft_contributors'] = 'Contributors';
$l['collaboration_draft_edits'] = 'Edits';
$l['collaboration_draft_no_contributions'] = 'No contributions yet. Start writing to see collaboration stats!'; 