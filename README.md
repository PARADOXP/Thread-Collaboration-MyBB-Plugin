# Thread Collaboration — MyBB Plugin

Allows thread creators to invite users to collaborate on threads with assigned roles and custom icons. Includes forum-specific permissions and admin controls.

Turns a thread into a shared workspace: the owner assigns roles such as Writer or Artist, collaborators can post and edit on behalf of the thread, every edit is tracked, and the whole thing can happen in a private draft before publication.

## Features

- **Collaborators with roles** — per-thread collaborator list, each with a free-text role and a Font Awesome icon. Default roles are configurable (`Writer|fas fa-pen`, `Artist|…`).
- **Role display in postbit** — collaborator role and icon render under the username, with an owner crown icon option.
- **Invitation system** — the thread owner invites users, who accept or decline from a User CP page. Invitations expire after a configurable number of days and are cleaned up on `global_start`.
- **Collaboration requests** — users can request to join a thread; owners/managers approve or reject. Requests have their own expiry and cleanup.
- **Collaboration chat page** — a dedicated per-thread chat (`collaboration_draft.php` / enabled per forum), with chat settings and message storage.
- **Draft page** — collaborators build a thread privately, with draft collaborators, contributor posts, and publication into a real thread.
- **Contribution tracking** — records which collaborator contributed which post, rendered in a contribution box under the post.
- **Edit history** — per-post edit log with a UI on `collaboration_edit_history.php` and an edit-history button in the postbit.
- **Collaborator post editing** — optionally let collaborators edit any post in the thread, with an approval requirement for "protected" owners.
- **Tabbed User CP interface** — current collaborations, invitations received, requests sent, unique owners/inviters, threads with collaborators, forum and role breakdowns, plus aggregate stats.
- **Forum-scoped permissions** — allowed usergroups, allowed forums, separate forum lists for the chat page and draft page, and a moderator/management usergroup list.
- **Limits** — max collaborators per thread, max threads per user, max roles per user.
- **PM notifications** — on invitation, on update, and on requests, with a customisable PM message template.
- **Reversible core edits** via PluginLibrary `edit_core()` — `editpost.php`, `search.php`, `xmlhttp.php`, `inc/functions_post.php` — with *Apply core edits* / *Revert core edits* links in the plugin row after an upgrade.
- 60 registered templates, so the entire UI is themeable from the ACP.

## Requirements

| Requirement | Version / note |
| --- | --- |
| MyBB | 1.8.x (`compatibility: 18*`) |
| PHP | 7.4+ (works on PHP 8.x) |
| PluginLibrary | Bundled — `inc/plugins/pluginlibrary.php` (v13, LGPL-3.0+, © Andreas Klauer) |
| Select2 | Stock MyBB ACP asset (`jscripts/select2`) used by the collaborator picker |
| Font Awesome 7 | Loaded from CDN (cdnjs) — activation injects the `<link>` into `headerinclude` |
| MySQL/MariaDB | 13 tables, `utf8mb4`, mixed MyISAM/InnoDB per `database.sql` |

## Installation

1. Extract the repository into your MyBB root (the directory containing `index.php`). The two root-level pages must sit in the forum root.
2. **Admin CP → Configuration → Plugins** → **Thread Collaboration** → **Install**, then **Activate**.
3. **Configuration → Settings → Thread Collaboration Settings** and set allowed usergroups/forums, roles, limits, and notification options.
4. If the plugin row reports *Core edits missing* (typical after a MyBB upgrade), click **Apply core edits**.

Activation applies template edits to `newthread`, `editpost`, `showthread`, `postbit`, `postbit_classic`, `usercp_nav_misc`, `member_profile`, and `headerinclude`, registers the templates and stylesheet, and applies the four core edits.
Deactivate reverts the template edits, removes the Font Awesome injection, and deactivates the stylesheet. Uninstall additionally drops the tables and settings group.

## Repository layout

Root-level pages (copy straight into the forum root):

```
collaboration_draft.php                             # per-thread collaboration/draft + chat page
collaboration_edit_history.php                      # per-post edit history page
```

Plugin files:

```
inc/plugins/thread_collaboration.php                # bootstrap: 22 hooks, core edits, settings, ACP plugin handling
inc/plugins/pluginlibrary.php                       # bundled PluginLibrary (LGPL-3.0+)
inc/plugins/Thread_Collaboration/install.php        # schema, settings, templates, stylesheet, template edits
inc/plugins/Thread_Collaboration/uninstall.php      # revert template edits, drop tables, delete settings
inc/plugins/Thread_Collaboration/database.sql       # 13 tables
inc/plugins/Thread_Collaboration/settings.json      # the 35 settings (PluginLibrary)
inc/plugins/Thread_Collaboration/Functions/         # 8 domain files
    editpost_functions.php      invitation_functions.php
    newthread_functions.php     postbit_functions.php
    post_functions.php          reputation_functions.php
    showthread_functions.php    usercp_functions.php
inc/plugins/Thread_Collaboration/Assets/            # 9 JS files + collaboration.css
inc/plugins/Thread_Collaboration/Templates/         # 60 templates
inc/languages/english/thread_collaboration.lang.php        # 205 front-end phrases
inc/languages/english/admin/thread_collaboration.lang.php  # 139 ACP phrases
```

## Database

| Table | Purpose |
| --- | --- |
| `thread_collaborators` | Collaborator rows: thread, user, role, role icon, joined date, joined via, source id. |
| `collaboration_invitations` | Pending/accepted/declined invitations with expiry. |
| `collaboration_requests` | User requests to join a thread, with expiry. |
| `collaboration_user_settings` | Per-user preferences (tabbed UCP, notifications). |
| `collab_post_edits` | Post edits made by collaborators (approval workflow). |
| `collab_edit_history` | Per-post revision log. |
| `collab_chat_messages` | Collaboration chat messages. |
| `collab_chat_settings` | Per-thread chat configuration. |
| `collab_drafts` | Draft threads before publication. |
| `collab_draft_contributions` | Posts contributed into a draft. |
| `collab_draft_settings` | Per-draft configuration. |
| `collab_draft_edit_logs` | Edit log for drafts. |
| `collab_contributor_posts` | Post ↔ contributor map used by the contribution box. |

## Settings (35)

**Access and scope**

| Setting | Default |
| --- | --- |
| Allowed Usergroups | `2,3,4` |
| Allowed Forums | all |
| Moderator & Management Usergroups | `3,4,6` |
| Max Collaborators | `10` |
| Max Threads Per User | `3` |
| Max Roles Per User | `3` |

**Roles and display**

| Setting | Default |
| --- | --- |
| Default Roles | `Writer\|fas fa-pen`, `Artist\|…` |
| Default Owner Icon | `fas fa-crown` |
| Show Role Icons | `1` |
| Show User Avatars | `1` |
| Show Role in Postbit | `1` |
| Owner Role Visibility Always | `1` |
| Show Owner Role Form Always | `0` |

**Invitations and requests**

| Setting | Default |
| --- | --- |
| Enable Invitation System | `1` |
| Enable Collaboration Requests | `1` |
| Invitation Expiry Days | `30` |
| Request Expiry Days | `30` |
| Auto Cleanup Expired Invitations | `1` |
| Auto Cleanup Expired Requests | `1` |
| Show Pending Invitations | `1` |
| Pending Invitations Viewing Usergroups | `2,3,4,6` |
| Manager Invite as Owner | `1` |

**Notifications**

| Setting | Default |
| --- | --- |
| PM Notifications | `1` |
| PM Notifications on Update | `1` |
| Request PM Notifications | `1` |
| Custom PM Message Template | *(empty)* |

**Editing and approval**

| Setting | Default |
| --- | --- |
| Enable Collaborator Post Editing | `1` |
| Allow Collaborators To Edit Any Posts | `0` |
| Require approval for protected owners | `1` |

**Pages**

| Setting | Default |
| --- | --- |
| Enable Chat Page | `1` |
| Chat Page Allowed Forums | all |
| Enable Draft Page | `1` |
| Draft Page Allowed Forums | all |

**User CP**

| Setting | Default |
| --- | --- |
| Enable User Collaboration Settings | `1` |
| Enable Tabbed User CP Interface | `1` |

Setting names are stored as `thread_collaboration_<key>`; the table above uses the short key.

## How it works

- `newthread_start` / `newthread_do_newthread_end` add the collaboration fields to thread creation and persist them.
- `showthread_start` / `showthread_end` render the collaborator box, the chat/draft buttons, and the request button.
- `editpost_action_start`, `editpost_do_editpost_start` / `_end` gate and record collaborator edits, with approval logic for protected owners.
- `postbit` / `postbit_pm` inject the role span, the edit-history button, the contribution box, and multi-author attribution.
- `usercp_start` serves the tabbed collaboration pages and the invitation list.
- `global_start` cleans expired invitations and requests and loads the language file.
- `member_profile_end`, `reputation_start` / `reputation_do_add_end`, `search_do_search_process`, and `xmlhttp_edit_post_end` extend the surrounding features (profile info, reputation from collaborations, search attribution, quick-edit approval).
- Core edits hook the plugin into `editpost.php`, `search.php`, `xmlhttp.php`, and `inc/functions_post.php`; all four are applied and reverted through `$PL->edit_core()` with `PL:thread_collaboration` markers.

## Notes

- **Root-level PHP pages** (`collaboration_draft.php`, `collaboration_edit_history.php`) are part of the plugin. They are not in `inc/plugins/` and must be copied to the forum root; they define `IN_MYBB` and bootstrap `global.php` themselves.
- **Assets are injected as hardcoded `<script>` tags** built from `$mybb->settings['bburl']` / `$mybb->asset_url` in `Functions/newthread_functions.php`, `Functions/editpost_functions.php`, and `Functions/showthread_functions.php` (Select2 + several plugin JS files), with inline JS carrying `json_encode()`d role data. No `*_js.html` template is used for these.
- **Font Awesome 7 is pulled from cdnjs** by a `find_replace_templatesets()` edit on `headerinclude`. The `<link>` is inserted and removed by activate/deactivate, but it adds a third-party CDN request to every page on the board.
- **The `postbit` contribution-box edit uses a regex on the last `</div>`** in the template. It is the most fragile edit in the plugin — a theme with different nesting can silently break the contribution display.
- **Four core file edits** (`editpost.php`, `search.php`, `xmlhttp.php`, `inc/functions_post.php`) mean the plugin is tied to the MyBB version it was installed against. After any upgrade, re-apply from the plugin row.
- A stale, zero-byte `collaboration.db` existed in the plugin folder and is **not** shipped here; the plugin uses the MySQL tables from `database.sql`.
- The previous repository also shipped `collaboration_chats.php`, which no longer exists in the current codebase (chat is served by `collaboration_draft.php`), so it is not part of this version.

## Author

**PARAD0X** — [MyBB Community profile](https://community.mybb.com/user-134311.html)

- Website: https://mybb.epizy.com
- Version: 1.3

## License

GPL-3.0 — see [LICENSE](LICENSE).
