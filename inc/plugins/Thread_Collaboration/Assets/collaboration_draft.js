/**
 * Collaboration Draft System
 * Handles collaborative post writing and contribution tracking
 */

class CollaborationDraft {
    constructor(config) {
        this.tid = config.tid;
        this.currentUser = config.currentUser;
        this.currentUserId = config.currentUserId;
        this.currentDraftId = null;
        this.lastContent = '';
        this.lastLoggedContent = ''; // Track what was last logged to avoid duplicate logs
        this.editLogTimer = null; // Timer for batching edit logs
        this.canManageSettingsFlag = config.canManageSettings || false;
        this.isSourceView = false; // Track current view mode for WYSIWYG

        // Advanced tracking variables
        this.realTimeTrackingEnabled = true;
        this.analysisTimer = null;
        this.pendingChanges = null;
        this.liveContributionDisplay = null;

        // Template cache
        this.templateCache = {};

        this.init();
    }
    
    // Template loading utility
    async loadTemplate(templateName, variables = {}) {
        // Check cache first
        if (this.templateCache[templateName]) {
            return this.replaceTemplateVariables(this.templateCache[templateName], variables);
        }
        
        try {
            const response = await fetch(`collaboration_draft.php?action=get_template&template_name=${templateName}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const text = await response.text();
            const data = JSON.parse(text);
            
            if (data.success) {
                this.templateCache[templateName] = data.template;
                return this.replaceTemplateVariables(data.template, variables);
            } else {
                console.error('Failed to load template:', data.error);
                return '';
            }
        } catch (error) {
            console.error('Error loading template:', error);
            return '';
        }
    }
    
    // Replace template variables
    replaceTemplateVariables(template, variables) {
        let result = template;
        for (const [key, value] of Object.entries(variables)) {
            result = result.replace(new RegExp(`\\{\\$${key}\\}`, 'g'), value);
        }
        return result;
    }

    // Real-time change analysis
    analyzeChanges(contentBefore, contentAfter) {
        if (!this.realTimeTrackingEnabled || !this.currentDraftId) {
            return;
        }

        const formData = new FormData();
        formData.append('content_before', contentBefore);
        formData.append('content_after', contentAfter);

        fetch('collaboration_draft.php?action=analyze_changes', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(async data => {
            if (data.success) {
                await this.updateLiveContributionDisplay(data.summary);
                this.pendingChanges = data.summary;
            }
        })
        .catch(error => {
        });
    }

    // Update live contribution display
    async updateLiveContributionDisplay(changes) {
        if (!this.liveContributionDisplay) {
            this.createLiveContributionDisplay();
        }

        const display = document.getElementById('live-contribution-display');
        if (display && changes) {
            let summary = '';
            if (changes.chars_added > 0) {
                summary += `+${changes.chars_added} chars`;
            }
            if (changes.chars_removed > 0) {
                summary += ` -${changes.chars_removed} chars`;
            }
            if (changes.words_added > 0) {
                summary += ` (+${changes.words_added} words)`;
            }
            
            const actualAdditionsHtml = changes.actual_additions ? `<div class="actual-additions">Added: "${changes.actual_additions}"</div>` : '';
            const actualRemovalsHtml = changes.actual_removals ? `<div class="actual-removals">Removed: "${changes.actual_removals}"</div>` : '';
            
            const template = await this.loadTemplate('collaboration_draft_live_display', {
                summary: summary || 'No changes',
                actual_additions_html: actualAdditionsHtml,
                actual_removals_html: actualRemovalsHtml
            });
            
            display.innerHTML = template;
        }
    }

    // Create live contribution display
    createLiveContributionDisplay() {
        const editor = document.getElementById('draft-editor');
        if (editor && !this.liveContributionDisplay) {
            const display = document.createElement('div');
            display.id = 'live-contribution-display';
            display.className = 'live-contribution-display';
            display.innerHTML = '<div class="live-contribution-summary"><i class="fas fa-edit"></i> <span class="contribution-text">Ready to track changes</span></div>';
            
            // Insert after the editor header
            const header = editor.querySelector('.draft-editor-header');
            if (header) {
                header.insertAdjacentElement('afterend', display);
            }
            
            this.liveContributionDisplay = display;
        }
    }

    init() {
        this.bindEvents();
        this.loadCollaborators();
        this.loadUserRole();
        this.updateMessageCount();
        this.loadSidebarPreference();
        this.initializeTabs();
        
        // Initialize content baseline to prevent double-counting on page load
        this.initializeContentBaseline();
        
        // Initialize Edit Logs system
        this.initializeEditLogs();
        
        // Hide sidebar and overlay if user can't manage settings
        if (!this.canManageSettings()) {
            this.hideSettingsUI();
        } else {
            this.initializeSettings();
            this.loadDraftSettings();
        }
    }

    canManageSettings() {
        return this.canManageSettingsFlag;
    }

    hideSettingsUI() {
        // Hide the sidebar and overlay for users without permission
        const sidebar = document.getElementById('off-canvas-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (sidebar) {
            sidebar.style.display = 'none';
        }
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    bindEvents() {
        // Toggle sidebar button
        const toggleBtn = document.getElementById('draft-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleSidebar();
            });
        }

        // New draft button
        const newDraftBtn = document.getElementById('new-draft-btn');
        if (newDraftBtn) {
            newDraftBtn.addEventListener('click', () => {
                this.createNewDraft();
            });
        }

        // Draft editor buttons
        const saveBtn = document.getElementById('draft-save-btn');
        const publishBtn = document.getElementById('draft-publish-btn');
        const cancelBtn = document.getElementById('draft-cancel-btn');

        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                this.saveDraft();
            });
        }

        if (publishBtn) {
            publishBtn.addEventListener('click', () => {
                this.publishDraft();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                this.cancelDraft();
            });
        }

        // Draft toolbar
        this.initializeDraftToolbar();
        this.initializeViewToggle();

        // Content changes for both input types
        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');
        
        if (contentFormatted) {
            contentFormatted.addEventListener('input', () => {
                this.trackContentChanges();
            });
        }
        
        if (contentSource) {
            contentSource.addEventListener('input', () => {
                this.trackContentChanges();
            });
        }

        // Tab navigation
        const createDraftTab = document.getElementById('create-draft-tab');
        const draftsTab = document.getElementById('drafts-tab');
        const editLogsTab = document.getElementById('edit-logs-tab');
        const archiveTab = document.getElementById('archive-tab');

        if (createDraftTab) {
            createDraftTab.addEventListener('click', () => {
                this.switchTab('create-draft');
            });
        }

        if (draftsTab) {
            draftsTab.addEventListener('click', () => {
                this.switchTab('drafts');
            });
        }

        if (editLogsTab) {
            editLogsTab.addEventListener('click', () => {
                this.switchTab('edit-logs');
            });
        }

        if (archiveTab) {
            archiveTab.addEventListener('click', () => {
                this.switchTab('archive');
            });
        }

    }

    initializeDraftToolbar() {
        const toolButtons = document.querySelectorAll('.draft-tool-btn:not(.draft-toggle-btn)');
        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');

        if (!contentFormatted || !contentSource) return;

        toolButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const tag = button.getAttribute('data-tag');
                if (this.isSourceView) {
                    this.insertMyCode(tag, contentSource);
                } else {
                    this.insertFormattedMyCode(tag, contentFormatted);
                }
            });
        });
    }

    initializeViewToggle() {
        const toggleBtn = document.getElementById('draft-toggle-view-btn');
        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');
        
        if (!toggleBtn || !contentFormatted || !contentSource) return;

        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleView();
        });
    }

    insertMyCode(tag, textarea) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selectedText = textarea.value.substring(start, end);
        let mycode = '';

        switch (tag) {
            case 'b':
                mycode = `[b]${selectedText}[/b]`;
                this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : 3);
                return;
            case 'i':
                mycode = `[i]${selectedText}[/i]`;
                this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : 3);
                return;
            case 'u':
                mycode = `[u]${selectedText}[/u]`;
                this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : 3);
                return;
            case 's':
                mycode = `[s]${selectedText}[/s]`;
                this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : 3);
                return;
            case 'align':
                mycode = `[align=left]${selectedText}[/align]`;
                break;
            case 'center':
                mycode = `[align=center]${selectedText}[/align]`;
                break;
            case 'right':
                mycode = `[align=right]${selectedText}[/align]`;
                break;
            case 'list':
                mycode = `[list]\n[*]${selectedText || 'Item 1'}\n[*]Item 2\n[/list]`;
                break;
            case 'list-ol':
            case 'ordered-list':
                mycode = `[list=1]\n[*]${selectedText || 'Item 1'}\n[*]Item 2\n[/list]`;
                break;
            case 'quote':
                mycode = `[quote]${selectedText}[/quote]`;
                break;
            case 'code':
                mycode = `[code]${selectedText}[/code]`;
                break;
            case 'hr':
                mycode = `[hr]`;
                break;
            case 'url':
                this.showUrlDialog(selectedText).then(result => {
                    if (result) {
                        mycode = `[url=${result.url}]${result.text}[/url]`;
                        this.insertMyCodeResult(mycode, textarea, start, end);
                    }
                });
                return;
            case 'img':
                this.showImageDialog().then(result => {
                    if (result && result.url) {
                        mycode = `[img]${result.url}[/img]`;
                        this.insertMyCodeResult(mycode, textarea, start, end);
                    }
                });
                return;
            case 'color':
                this.showColorDialog(selectedText).then(result => {
                    if (result && result.color) {
                        mycode = `[color=${result.color}]${selectedText}[/color]`;
                        this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : `[color=${result.color}]`.length);
                    }
                });
                return;
            case 'size':
                this.showSizeDialog(selectedText).then(result => {
                    if (result && result.size) {
                        mycode = `[size=${result.size}]${selectedText}[/size]`;
                        this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : `[size=${result.size}]`.length);
                    }
                });
                return;
            case 'spoiler':
                mycode = `[spoiler]${selectedText}[/spoiler]`;
                this.insertMyCodeResultWithCursor(mycode, textarea, start, end, selectedText ? mycode.length : 9); // 9 = length of [spoiler]
                return;
            case 'table':
                mycode = `[table]\n[tr][td]${selectedText || 'Cell 1'}[/td][td]Cell 2[/td][/tr]\n[tr][td]Cell 3[/td][td]Cell 4[/td][/tr]\n[/table]`;
                break;
            case 'clear':
                // Remove all BBCode tags
                const clearText = selectedText.replace(/\[(\/?)\w+(?:=[^\]]+)?\]/g, '');
                mycode = clearText;
                break;
        }

        const newValue = textarea.value.substring(0, start) + mycode + textarea.value.substring(end);
        textarea.value = newValue;
        textarea.focus();
        textarea.setSelectionRange(start + mycode.length, start + mycode.length);
    }

    insertMyCodeResult(mycode, textarea, start, end) {
        const newValue = textarea.value.substring(0, start) + mycode + textarea.value.substring(end);
        textarea.value = newValue;
        textarea.focus();
        textarea.setSelectionRange(start + mycode.length, start + mycode.length);

        // Trigger content change tracking
        this.trackContentChanges();
    }

    insertMyCodeResultWithCursor(mycode, textarea, start, end, cursorPosition) {
        const newValue = textarea.value.substring(0, start) + mycode + textarea.value.substring(end);
        textarea.value = newValue;
        textarea.focus();
        textarea.setSelectionRange(start + cursorPosition, start + cursorPosition);

        // Trigger content change tracking
        this.trackContentChanges();
    }

    // WYSIWYG Methods for Draft Page
    toggleView() {
        const toggleBtn = document.getElementById('draft-toggle-view-btn');
        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');
        
        if (!toggleBtn || !contentFormatted || !contentSource) return;

        this.isSourceView = !this.isSourceView;
        
        if (this.isSourceView) {
            // Switch to source view
            contentFormatted.style.display = 'none';
            contentSource.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Formatted';
            toggleBtn.classList.add('active');
            
            // Convert formatted content to BBCode
            const formattedContent = contentFormatted.innerHTML;
            const bbcodeContent = this.convertFormattedToBBCode(formattedContent);
            contentSource.value = bbcodeContent;
            contentSource.focus();
        } else {
            // Switch to formatted view
            contentSource.style.display = 'none';
            contentFormatted.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-code"></i> Source';
            toggleBtn.classList.remove('active');
            
            // Convert BBCode to formatted content
            const bbcodeContent = contentSource.value;
            const formattedContent = this.convertBBCodeToFormatted(bbcodeContent);
            contentFormatted.innerHTML = formattedContent;
            contentFormatted.focus();
        }
    }

    insertFormattedMyCode(tag, input) {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        
        // Use browser's built-in formatting commands for better nesting support
        this.applyBrowserFormatting(tag, input);
        
        input.focus();
    }

    applyBrowserFormatting(tag, input) {
        // Focus the input first
        input.focus();
        
        // Use browser's execCommand for proper formatting
        switch (tag) {
            case 'b':
                document.execCommand('bold', false, null);
                break;
            case 'i':
                document.execCommand('italic', false, null);
                break;
            case 'u':
                document.execCommand('underline', false, null);
                break;
            case 's':
                document.execCommand('strikeThrough', false, null);
                break;
            case 'align':
                document.execCommand('justifyLeft', false, null);
                break;
            case 'center':
                document.execCommand('justifyCenter', false, null);
                break;
            case 'right':
                document.execCommand('justifyRight', false, null);
                break;
            case 'list':
                document.execCommand('insertUnorderedList', false, null);
                break;
            case 'list-ol':
            case 'ordered-list':
                document.execCommand('insertOrderedList', false, null);
                break;
            case 'quote':
                this.insertBlockquoteElement();
                break;
            case 'code':
                this.insertCodeElement();
                break;
            case 'hr':
                document.execCommand('insertHorizontalRule', false, null);
                break;
            case 'url':
                this.showUrlDialog('').then(result => {
                    if (result) {
                        document.execCommand('createLink', false, result.url);
                    }
                });
                break;
            case 'img':
                this.showImageDialog().then(result => {
                    if (result && result.url) {
                        document.execCommand('insertImage', false, result.url);
                    }
                });
                break;
            case 'color':
                this.showColorDialog('').then(result => {
                    if (result && result.color) {
                        document.execCommand('foreColor', false, result.color);
                    }
                });
                break;
            case 'size':
                this.showSizeDialog('').then(result => {
                    if (result && result.size) {
                        document.execCommand('fontSize', false, result.size);
                    }
                });
                break;
            case 'spoiler':
                this.insertSpoilerElement();
                break;
            case 'table':
                this.insertTableElement();
                break;
            case 'clear':
                document.execCommand('removeFormat', false, null);
                break;
        }
    }

    insertCodeElement() {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        
        const codeElement = document.createElement('code');
        if (selectedText) {
            codeElement.textContent = selectedText;
            range.deleteContents();
            range.insertNode(codeElement);
        } else {
            codeElement.innerHTML = '<br>';
            range.deleteContents();
            range.insertNode(codeElement);
            
            // Position cursor inside the element
            const newRange = document.createRange();
            newRange.setStart(codeElement, 0);
            newRange.setEnd(codeElement, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }

    insertColorElement() {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        
        const colorElement = document.createElement('span');
        colorElement.style.color = '#ff0000'; // Default red
        if (selectedText) {
            colorElement.textContent = selectedText;
            range.deleteContents();
            range.insertNode(colorElement);
        } else {
            colorElement.innerHTML = '<br>';
            range.deleteContents();
            range.insertNode(colorElement);
            
            // Position cursor inside the element
            const newRange = document.createRange();
            newRange.setStart(colorElement, 0);
            newRange.setEnd(colorElement, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }

    insertSizeElement() {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        
        const sizeElement = document.createElement('span');
        sizeElement.style.fontSize = '16px'; // Default size
        if (selectedText) {
            sizeElement.textContent = selectedText;
            range.deleteContents();
            range.insertNode(sizeElement);
        } else {
            sizeElement.innerHTML = '<br>';
            range.deleteContents();
            range.insertNode(sizeElement);
            
            // Position cursor inside the element
            const newRange = document.createRange();
            newRange.setStart(sizeElement, 0);
            newRange.setEnd(sizeElement, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }

    convertFormattedToBBCode(html) {
        let bbcode = html;
        
        // Convert HTML tags to BBCode
        bbcode = bbcode.replace(/<strong>(.*?)<\/strong>/gi, '[b]$1[/b]');
        bbcode = bbcode.replace(/<b>(.*?)<\/b>/gi, '[b]$1[/b]');
        bbcode = bbcode.replace(/<em>(.*?)<\/em>/gi, '[i]$1[/i]');
        bbcode = bbcode.replace(/<i>(.*?)<\/i>/gi, '[i]$1[/i]');
        bbcode = bbcode.replace(/<u>(.*?)<\/u>/gi, '[u]$1[/u]');
        bbcode = bbcode.replace(/<s>(.*?)<\/s>/gi, '[s]$1[/s]');
        bbcode = bbcode.replace(/<code>(.*?)<\/code>/gi, '[code]$1[/code]');
        bbcode = bbcode.replace(/<span style="color:\s*([^"]+)">(.*?)<\/span>/gi, '[color=$1]$2[/color]');
        bbcode = bbcode.replace(/<span style="font-size:\s*([^"]+)">(.*?)<\/span>/gi, '[size=$1]$2[/size]');
        
        // Remove any remaining HTML tags
        bbcode = bbcode.replace(/<[^>]*>/g, '');
        
        return bbcode;
    }

    convertBBCodeToFormatted(bbcode) {
        let html = bbcode;
        
        // Convert BBCode to HTML
        html = html.replace(/\[b\](.*?)\[\/b\]/gi, '<strong>$1</strong>');
        html = html.replace(/\[i\](.*?)\[\/i\]/gi, '<em>$1</em>');
        html = html.replace(/\[u\](.*?)\[\/u\]/gi, '<u>$1</u>');
        html = html.replace(/\[s\](.*?)\[\/s\]/gi, '<s>$1</s>');
        html = html.replace(/\[code\](.*?)\[\/code\]/gi, '<code>$1</code>');
        html = html.replace(/\[color=([^\]]+)\](.*?)\[\/color\]/gi, '<span style="color: $1">$2</span>');
        html = html.replace(/\[size=([^\]]+)\](.*?)\[\/size\]/gi, '<span style="font-size: $1px">$2</span>');
        
        return html;
    }

    loadDrafts() {
        const draftList = document.getElementById('draft-list');
        if (!draftList) return;

        draftList.innerHTML = '<div class="draft-loading">Loading drafts...</div>';

        fetch(`collaboration_draft.php?action=get_drafts&tid=${this.tid}`)
            .then(response => response.json())
            .then(async data => {
                if (data.success) {
                    await this.displayDrafts(data.drafts);
                } else {
                    draftList.innerHTML = '<div class="draft-error">Failed to load drafts</div>';
                }
            })
            .catch(error => {
                draftList.innerHTML = '<div class="draft-error">Error loading drafts</div>';
            });
    }

    async displayDrafts(drafts) {
        const draftList = document.getElementById('draft-list');
        if (!draftList) return;

        if (drafts.length === 0) {
            draftList.innerHTML = '<div class="draft-empty">No drafts yet. Create your first collaborative post!</div>';
            return;
        }

        draftList.innerHTML = '';

        for (const draft of drafts) {
            const draftItem = document.createElement('div');
            draftItem.className = 'draft-item';

            // Only show publish button for non-published drafts
            const publishButton = draft.status === 'published' ? '' : `
                <button class="draft-action-btn draft-publish-btn" onclick="collaborationDraft.publishDraftFromList(${draft.draft_id})">
                    <i class="fas fa-paper-plane"></i> Publish
                </button>
            `;

            const template = await this.loadTemplate('collaboration_draft_item', {
                draft_subject: draft.subject || 'Untitled Draft',
                draft_status: draft.status,
                draft_preview: draft.content.substring(0, 150) + (draft.content.length > 150 ? '...' : ''),
                created_by: draft.created_by,
                created_date: this.formatDate(draft.created_date),
                draft_id: draft.draft_id,
                publish_button: publishButton
            });

            draftItem.innerHTML = template;
            draftList.appendChild(draftItem);
        }
    }

    createNewDraft() {
        const draftEditor = document.getElementById('draft-editor');
        const createDraftWelcome = document.getElementById('create-draft-welcome');

        if (draftEditor && createDraftWelcome) {
            // Hide welcome screen and show editor
            createDraftWelcome.style.display = 'none';
            draftEditor.style.display = 'flex';
            document.getElementById('draft-subject').value = '';
            const contentFormatted = document.getElementById('draft-content-formatted');
            const contentSource = document.getElementById('draft-content-source');
            if (contentFormatted) contentFormatted.innerHTML = '';
            if (contentSource) contentSource.value = '';
            document.getElementById('draft-subject').focus();
        }

        this.currentDraftId = null;
        this.lastContent = ''; // For new drafts, start with empty baseline
        this.loadContributions();
    }

    editDraft(draftId) {
        fetch(`collaboration_draft.php?action=get_draft&tid=${this.tid}&draft_id=${draftId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.currentDraftId = draftId;
                    document.getElementById('draft-subject').value = data.draft.subject;
                    document.getElementById('draft-content').value = data.draft.content;

                    // Initialize content baseline to prevent double-counting contributions
                    this.initializeContentBaseline();
                    this.lastLoggedContent = data.draft.content;

                    // Switch to Create Draft tab and show editor
                    this.switchTab('create-draft');
                    const draftEditor = document.getElementById('draft-editor');
                    const createDraftWelcome = document.getElementById('create-draft-welcome');
                    if (draftEditor && createDraftWelcome) {
                        createDraftWelcome.style.display = 'none';
                        draftEditor.style.display = 'flex';
                    }

                    this.lastContent = data.draft.content;
                    this.loadContributions();
                }
            });
    }

    cancelDraft() {
        const draftEditor = document.getElementById('draft-editor');
        const createDraftWelcome = document.getElementById('create-draft-welcome');

        if (draftEditor && createDraftWelcome) {
            // Hide editor and show welcome screen
            draftEditor.style.display = 'none';
            createDraftWelcome.style.display = 'block';
        }

        this.currentDraftId = null;
        this.lastContent = '';
    }

    saveDraft() {
        const subject = document.getElementById('draft-subject').value.trim();
        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');
        
        let content;
        if (this.isSourceView) {
            content = contentSource.value.trim();
        } else {
            // Convert formatted content to BBCode for saving
            const formattedContent = contentFormatted.innerHTML;
            content = this.convertFormattedToBBCode(formattedContent).trim();
        }

        if (!subject || !content) {
            this.showNotification('Save Failed', 'Please enter both subject and content.', 'error', 8000);
            return;
        }

        const formData = new FormData();
        formData.append('tid', this.tid);
        formData.append('subject', subject);
        formData.append('content', content);
        if (this.currentDraftId) {
            formData.append('draft_id', this.currentDraftId);
        }

        fetch('collaboration_draft.php?action=save_draft', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                
                if (data.success) {
                    this.showNotification('Draft Saved', 'Your draft has been saved successfully.', 'success', 8000);
                    
                    const wasNewDraft = !this.currentDraftId;
                    this.currentDraftId = data.draft_id;
                    this.lastContent = content;
                    
                    // Only log initial creation for truly new drafts, not when updating existing drafts
                    if (wasNewDraft) {
                    this.logDraftEdit('', content, 'create');
                    this.lastLoggedContent = content;
                    }
                    
                    this.loadDrafts();
                    this.loadContributions();

                    // Switch to Drafts tab to show the saved draft
                    this.switchTab('drafts');
                } else {
                    this.showNotification('Save Failed', data.error || 'Failed to save draft.', 'error', 10000);
                }
            })
            .catch(error => {
                this.showNotification('Save Failed', 'Network error occurred.', 'error', 10000);
            });
    }

    publishDraft() {
        if (!this.currentDraftId) {
            this.showNotification('Publish Failed', 'Please save the draft first.', 'error', 8000);
            return;
        }

        this.showPublishConfirmation(() => {
            fetch(`collaboration_draft.php?action=publish_draft&tid=${this.tid}&draft_id=${this.currentDraftId}`, {
                method: 'POST'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.showNotification('Draft Published', 'Your draft has been published to the thread.', 'success', 8000);
                        this.cancelDraft();
                        this.loadDrafts();

                        // Switch to Archive tab to show the published draft
                        this.switchTab('archive');
                    } else {
                        this.showNotification('Publish Failed', data.error || 'Failed to publish draft.', 'error', 10000);
                    }
                })
                .catch(error => {
                    this.showNotification('Publish Failed', 'Network error occurred.', 'error', 10000);
                });
        });
    }

    publishDraftFromList(draftId) {
        this.showPublishConfirmation(() => {
            fetch(`collaboration_draft.php?action=publish_draft&tid=${this.tid}&draft_id=${draftId}`, {
                method: 'POST'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.showNotification('Draft Published', 'Your draft has been published to the thread.', 'success', 8000);
                        this.loadDrafts();
                    } else {
                        this.showNotification('Publish Failed', data.error || 'Failed to publish draft.', 'error', 10000);
                    }
                })
                .catch(error => {
                    this.showNotification('Publish Failed', 'Network error occurred.', 'error', 10000);
                });
        });
    }

    async deleteDraft(draftId) {
        // Check if we're in the archive tab to show appropriate confirmation message
        const currentTab = document.querySelector('.tab-button.active')?.textContent?.trim();
        const isArchiveTab = currentTab === 'Archive';
        
        const confirmTitle = isArchiveTab ? 'Permanently Delete Draft' : 'Delete Draft';
        const confirmMessage = isArchiveTab 
            ? 'Are you sure you want to permanently delete this draft? This action cannot be undone.'
            : 'Are you sure you want to delete this draft? It will be moved to the archive.';
        const confirmText = isArchiveTab ? 'Delete Forever' : 'Move to Archive';
        const modalType = isArchiveTab ? 'danger' : 'warning';
            
        const confirmed = await this.showConfirmModal(confirmTitle, confirmMessage, confirmText, 'Cancel', modalType);
        
        if (confirmed) {
            fetch(`collaboration_draft.php?action=delete_draft&tid=${this.tid}&draft_id=${draftId}`, {
                method: 'POST'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.showNotification('Draft Deleted', data.message || 'The draft has been deleted.', 'success', 12000);
                        this.loadDrafts();
                        this.loadArchive(); // Also refresh archive list
                    } else {
                        this.showNotification('Delete Failed', data.error || 'Failed to delete draft.', 'error', 10000);
                    }
                });
        }
    }

    async loadCollaborators() {
        await this.loadOnlineUsers();
        await this.loadAllCollaborators();
    }

    async loadOnlineUsers() {
        fetch(`collaboration_draft.php?action=get_online_users&tid=${this.tid}`)
            .then(response => response.json())
            .then(async data => {
                if (data.success) {
                    await this.updateOnlineUsers(data.users);
                } else {
                }
            })
            .catch(error => {
            });
    }

    async loadAllCollaborators() {
        fetch(`collaboration_draft.php?action=get_collaborators&tid=${this.tid}`)
            .then(response => response.json())
            .then(async data => {
                if (data.success) {
                    await this.updateCollaborators(data.collaborators);
                } else {
                }
            })
            .catch(error => {
            });
    }

    // Update online users display
    async updateOnlineUsers(users) {
        const onlineUsersList = document.getElementById('online-users-list');
        const onlineCount = document.getElementById('online-count');
        
        if (!onlineUsersList || !onlineCount) {
            return;
        }
        
        onlineCount.textContent = users.length;
        
        if (users.length === 0) {
            onlineUsersList.innerHTML = '<div class="no-users">No users online</div>';
            return;
        }
        
        const usersHTML = await Promise.all(users.map(async user => {
            // Create role class name from role (convert to lowercase and replace spaces with hyphens)
            const roleClass = user.role.toLowerCase().replace(/\s+/g, '-');
            
            // Handle avatar - use actual avatar URL from database
            const avatarHTML = user.avatar ? 
                `<img src="${user.avatar}" alt="${user.username}" class="avatar-img">` :
                `<div class="user-avatar-fallback">${user.username.charAt(0).toUpperCase()}</div>`;
            
            return this.replaceTemplateVariables(
                await this.loadTemplate('collaboration_user_item'),
                {
                    avatar_html: avatarHTML,
                    user_uid: user.uid,
                    username: user.username,
                    role_class: roleClass,
                    role_icon: user.role_icon,
                    role: user.role
                }
            );
        }));
        
        onlineUsersList.innerHTML = usersHTML.join('');
    }

    // Update collaborators display
    async updateCollaborators(collaborators) {
        const collaboratorsList = document.getElementById('collaborators-list');
        const collaboratorsCount = document.getElementById('collaborators-count');
        
        if (!collaboratorsList || !collaboratorsCount) {
            return;
        }
        
        collaboratorsCount.textContent = collaborators.length;
        
        if (collaborators.length === 0) {
            collaboratorsList.innerHTML = '<div class="no-collaborators">No collaborators</div>';
            return;
        }
        
        const collaboratorsHTML = await Promise.all(collaborators.map(async collaborator => {
            // Generate roles HTML for all roles (like chat page)
            const rolesHTML = await Promise.all(collaborator.roles.map(async role => {
                const roleClass = role.role.toLowerCase().replace(/\s+/g, '-');
                return this.replaceTemplateVariables(
                    await this.loadTemplate('collaboration_role_item'),
                    {
                        role_class: roleClass,
                        role_icon: role.role_icon,
                        role: role.role
                    }
                );
            }));
            
            // Handle avatar - use actual avatar URL from database
            const avatarHTML = collaborator.avatar ? 
                `<img src="${collaborator.avatar}" alt="${collaborator.username}" class="avatar-img">` :
                `<div class="collaborator-avatar-fallback">${collaborator.username.charAt(0).toUpperCase()}</div>`;
            
            return this.replaceTemplateVariables(
                await this.loadTemplate('collaboration_collaborator_item'),
                {
                    avatar_html: avatarHTML,
                    collaborator_uid: collaborator.uid,
                    username: collaborator.username,
                    roles_html: rolesHTML
                }
            );
        }));
        
        collaboratorsList.innerHTML = collaboratorsHTML.join('');
    }

    getRoleIcon(role) {
        const roleIcons = {
            'writer': 'pen',
            'artist': 'paint-brush',
            'designer': 'palette',
            'script': 'code',
            'translator': 'language',
            'announcer': 'bullhorn',
            'memer': 'laugh',
            'reader': 'book',
            'owner': 'crown'
        };

        return roleIcons[role?.toLowerCase()] || 'user';
    }

    // Load user role
    loadUserRole() {
        fetch(`collaboration_draft.php?action=get_user_role&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const userRoleElement = document.getElementById('user-role');
                    if (userRoleElement) {
                        // Create role class name from role (convert to lowercase and replace spaces with hyphens)
                        const roleClass = data.role.toLowerCase().replace(/\s+/g, '-');
                        userRoleElement.innerHTML = `<span class="user-role ${roleClass}"><i class="${data.role_icon}"></i> ${data.role}</span>`;
                    }
                }
            })
            .catch(error => {
                // Silently handle user role loading errors
            });
    }

    // Update message count
    updateMessageCount() {
        const totalMessages = document.getElementById('total-messages');
        if (totalMessages) {
            // For draft page, we can get message count from the thread
            fetch(`collaboration_draft.php?action=get_message_count&tid=${this.tid}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        totalMessages.textContent = data.count;
                    }
                })
                .catch(error => {
                    totalMessages.textContent = '0';
                });
        }
    }

    loadContributions() {
        if (!this.currentDraftId) {
            document.getElementById('contributions-list').innerHTML = '<div class="contributions-empty">No contributions yet</div>';
            return;
        }

        // Use the new edit logs system for contributions
        fetch(`collaboration_draft.php?action=get_edit_contributions&draft_id=${this.currentDraftId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.displayContributions(data.contributions);
                }
            })
            .catch(error => {
            });
    }

    displayContributions(contributions) {
        const contributionsList = document.getElementById('contributions-list');
        if (!contributionsList) return;

        if (contributions.length === 0) {
            contributionsList.innerHTML = '<div class="contributions-empty">No contributions yet</div>';
            return;
        }

        contributionsList.innerHTML = '';

        contributions.forEach(contrib => {
            const contribItem = document.createElement('div');
            contribItem.className = 'contribution-item';
            contribItem.innerHTML = `
                <div class="contributor-info">
                    <div class="collaborator-avatar">${contrib.username.charAt(0).toUpperCase()}</div>
                    <div class="contributor-details">
                        <div class="contributor-name">${contrib.username}</div>
                        <div class="contributor-role">${contrib.role}</div>
                    </div>
                </div>
                <div class="contribution-stats">
                    <div class="contribution-score">
                        <div class="score-bar">
                            <div class="score-fill" style="width: ${Math.min(100, (contrib.characters_added / 1000) * 100)}%"></div>
                        </div>
                        <div class="score-text">${contrib.characters_added} chars</div>
                    </div>
                    <div class="contribution-details">
                        <div class="contrib-stat positive">
                            <i class="fas fa-plus"></i> ${contrib.characters_added} added
                        </div>
                    </div>
                </div>
            `;
            contributionsList.appendChild(contribItem);
        });
    }


    trackContentChanges() {
        if (!this.currentDraftId) return;

        const contentFormatted = document.getElementById('draft-content-formatted');
        const contentSource = document.getElementById('draft-content-source');
        
        let content;
        if (this.isSourceView) {
            content = contentSource.value;
        } else {
            // Convert formatted content to BBCode for tracking
            const formattedContent = contentFormatted.innerHTML;
            content = this.convertFormattedToBBCode(formattedContent);
        }
        
        const contentBefore = this.lastContent;
        const contentAfter = content;

        // Only track if there are actual changes and it's different from what we last logged
        if (contentBefore !== contentAfter && contentAfter !== this.lastLoggedContent) {
            // Real-time analysis for live feedback
            if (this.realTimeTrackingEnabled) {
                // Clear existing analysis timer
                if (this.analysisTimer) {
                    clearTimeout(this.analysisTimer);
                }
                
                // Analyze changes in real-time (with shorter delay for live feedback)
                this.analysisTimer = setTimeout(() => {
                    this.analyzeChanges(this.lastLoggedContent || contentBefore, contentAfter);
                }, 500); // Shorter delay for live analysis
            }
            
            // Clear existing edit log timer
            if (this.editLogTimer) {
                clearTimeout(this.editLogTimer);
            }
            
            // Set a new timer to batch the edit log (debounce for 2 seconds)
            this.editLogTimer = setTimeout(() => {
                this.logDraftEdit(contentBefore, contentAfter, 'edit');
                this.lastLoggedContent = contentAfter;
                this.editLogTimer = null;
                
                // Clear live display after logging
                if (this.liveContributionDisplay) {
                    const display = document.getElementById('live-contribution-display');
                    if (display) {
                        display.innerHTML = '<div class="live-contribution-summary"><i class="fas fa-check"></i> <span class="contribution-text">Changes logged</span></div>';
                        setTimeout(() => {
                            if (display) {
                                display.innerHTML = '<div class="live-contribution-summary"><i class="fas fa-edit"></i> <span class="contribution-text">Ready to track changes</span></div>';
                            }
                        }, 2000);
                    }
                }
            }, 2000);
        }

        this.lastContent = content;
    }

    // Initialize lastContent with current draft content when user loads the draft
    initializeContentBaseline() {
        const content = document.getElementById('draft-content');
        if (content) {
            this.lastContent = content.value;
        }
    }

    formatDate(timestamp) {
        const date = new Date(timestamp * 1000);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }

    async showPublishConfirmation(onConfirm) {
        // Create beautiful confirmation modal
        const modal = document.createElement('div');
        modal.className = 'publish-confirmation-modal';
        
        const template = await this.loadTemplate('collaboration_draft_modal_publish');
        modal.innerHTML = template;

        document.body.appendChild(modal);

        // Add animation
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);

        // Event listeners
        const cancelBtn = modal.querySelector('.publish-confirmation-cancel');
        const confirmBtn = modal.querySelector('.publish-confirmation-confirm');
        const overlay = modal.querySelector('.publish-confirmation-overlay');

        const closeModal = () => {
            modal.classList.remove('show');
            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }, 300);
        };

        cancelBtn.addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', () => {
            closeModal();
            onConfirm();
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeModal();
            }
        });

        // ESC key support
        const handleKeyPress = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', handleKeyPress);
            }
        };
        document.addEventListener('keydown', handleKeyPress);
    }

    async showCollaboratorsPopup(draftId) {
        // Create collaborators modal
        const modal = document.createElement('div');
        modal.className = 'collaborators-modal';
        
        const template = await this.loadTemplate('collaboration_draft_modal_collaborators');
        modal.innerHTML = template;

        document.body.appendChild(modal);

        // Add animation
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);

        // Fetch collaborators data
        fetch(`collaboration_draft.php?action=get_draft_collaborators&tid=${this.tid}&draft_id=${draftId}`)
            .then(response => response.json())
            .then(async data => {
                if (data.success) {
                    await this.displayCollaboratorsInModal(modal, data.collaborators);
                } else {
                    modal.querySelector('.collaborators-modal-body').innerHTML = '<div class="collaborators-error">Failed to load collaborators</div>';
                }
            })
            .catch(error => {
                modal.querySelector('.collaborators-modal-body').innerHTML = '<div class="collaborators-error">Error loading collaborators</div>';
            });

        // Event listeners
        const closeBtn = modal.querySelector('.collaborators-modal-close');
        const overlay = modal.querySelector('.collaborators-modal-overlay');

        const closeModal = () => {
            modal.classList.remove('show');
            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }, 300);
        };

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeModal();
            }
        });

        // ESC key support
        const handleKeyPress = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', handleKeyPress);
            }
        };
        document.addEventListener('keydown', handleKeyPress);
    }

    async displayCollaboratorsInModal(modal, collaborators) {
        const modalBody = modal.querySelector('.collaborators-modal-body');

        if (collaborators.length === 0) {
            modalBody.innerHTML = '<div class="collaborators-empty">No contributors found for this draft. Only users who have actually contributed to writing and editing this draft will be shown here.</div>';
            return;
        }

        let collaboratorsHTML = '<div class="collaborators-list">';

        for (const collaborator of collaborators) {
            const avatarHTML = collaborator.avatar ?
                `<img src="${collaborator.avatar}" alt="${collaborator.username}" class="collaborator-avatar-img">` :
                `<div class="collaborator-avatar-fallback">${collaborator.username.charAt(0).toUpperCase()}</div>`;

            const rolesHTML = await Promise.all(collaborator.roles.map(async role => {
                const roleClass = role.role.toLowerCase().replace(/\s+/g, '-');
                return this.replaceTemplateVariables(
                    await this.loadTemplate('collaboration_role_span'),
                    {
                        role_class: roleClass,
                        role_icon: role.role_icon,
                        role: role.role
                    }
                );
            }));

            // Calculate contribution percentage based on total contributions from all collaborators
            const totalContributions = collaborators.reduce((sum, c) => sum + (c.total_contributions || 0), 0);
            const contributionPercentage = totalContributions > 0 ? Math.round((collaborator.total_contributions / totalContributions) * 100) : 0;

            const template = await this.loadTemplate('collaboration_draft_collaborator_item', {
                avatar_html: avatarHTML,
                username: collaborator.username,
                roles_html: rolesHTML,
                total_contributions: collaborator.total_contributions,
                contribution_percentage: contributionPercentage
            });

            collaboratorsHTML += template;
        }

        collaboratorsHTML += '</div>';
        modalBody.innerHTML = collaboratorsHTML;
    }

    // Custom Confirmation Modal
    async showConfirmModal(title, message, confirmText = 'Confirm', cancelText = 'Cancel', type = 'warning') {
        return new Promise(async (resolve) => {
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'custom-modal-overlay';

            // Create modal content
            const modal = document.createElement('div');
            modal.className = 'custom-confirm-modal';

            // Icon mapping
            const icons = {
                warning: 'fas fa-exclamation-triangle',
                danger: 'fas fa-trash-alt',
                info: 'fas fa-info-circle',
                success: 'fas fa-check-circle'
            };

            // Color mapping
            const colors = {
                warning: '#f39c12',
                danger: '#e74c3c',
                info: '#3498db',
                success: '#27ae60'
            };

            modal.innerHTML = this.replaceTemplateVariables(
                await this.loadTemplate('collaboration_custom_modal'),
                {
                    icon_bg_color: colors[type] + '15',
                    icon_border_color: colors[type] + '30',
                    icon_class: icons[type],
                    icon_color: colors[type],
                    title: title,
                    message: message,
                    cancel_text: cancelText,
                    confirm_text: confirmText,
                    confirm_bg_color: colors[type]
                }
            );


            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Animate in
            setTimeout(() => {
                overlay.style.opacity = '1';
                modal.style.transform = 'scale(1) translateY(0)';
            }, 10);

            // Handle button clicks
            const cancelBtn = modal.querySelector('.cancel-btn');
            const confirmBtn = modal.querySelector('.confirm-btn');

            const closeModal = (result) => {
                overlay.style.opacity = '0';
                modal.style.transform = 'scale(0.9) translateY(20px)';
                setTimeout(() => {
                    document.body.removeChild(overlay);
                    resolve(result);
                }, 300);
            };

            cancelBtn.addEventListener('click', () => closeModal(false));
            confirmBtn.addEventListener('click', () => closeModal(true));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal(false);
            });

            // Handle escape key
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    closeModal(false);
                    document.removeEventListener('keydown', handleEscape);
                }
            };
            document.addEventListener('keydown', handleEscape);
        });
    }

    // Amazing Notification system (from chat page)
    async showNotification(title, message, type = 'info', duration = 4000) {
        const container = document.getElementById('notification-container');
        if (!container) return;
        
        // Check for existing notifications with the same title to prevent duplicates
        const existingNotifications = container.querySelectorAll('.notification');
        for (let existing of existingNotifications) {
            const existingTitle = existing.querySelector('.notification-title');
            if (existingTitle && existingTitle.textContent === title) {
                // Remove existing notification before showing new one
                this.removeNotification(existing);
            }
        }
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };
        
        notification.innerHTML = this.replaceTemplateVariables(
            await this.loadTemplate('collaboration_notification'),
            {
                icon_class: icons[type] || icons.info,
                title: title,
                message: message
            }
        );
        
        container.appendChild(notification);
        
        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);
        
        // Auto remove after duration
        setTimeout(() => {
            this.removeNotification(notification);
        }, duration);
    }
    
    removeNotification(notification) {
        if (notification && notification.parentNode) {
            notification.classList.remove('show');
            setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
            }, 400);
        }
    }
    
    // Clear all existing notifications
    clearAllNotifications() {
        const container = document.getElementById('notification-container');
        if (container) {
            const notifications = container.querySelectorAll('.notification');
            notifications.forEach(notification => {
                this.removeNotification(notification);
            });
        }
    }

    toggleSidebar() {
        const container = document.querySelector('.collaboration-draft-container');
        const toggleBtn = document.getElementById('draft-toggle-btn');

        if (container && toggleBtn) {
            const isExpanded = container.classList.contains('expanded');

            if (isExpanded) {
                // Show sidebar
                container.classList.remove('expanded');
                toggleBtn.classList.remove('expanded');
                this.saveSidebarPreference(false);
            } else {
                // Hide sidebar
                container.classList.add('expanded');
                toggleBtn.classList.add('expanded');
                this.saveSidebarPreference(true);
            }
        }
    }

    initializeTabs() {
        // Check URL parameters for tab state
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'create-draft';

        // Set initial tab state
        this.switchTab(activeTab, false);
    }

    switchTab(tabName, updateUrl = true) {
        const createDraftTab = document.getElementById('create-draft-tab');
        const draftsTab = document.getElementById('drafts-tab');
        const editLogsTab = document.getElementById('edit-logs-tab');
        const archiveTab = document.getElementById('archive-tab');
        const createDraftContainer = document.getElementById('create-draft-container');
        const draftListContainer = document.getElementById('draft-list-container');
        const editLogsContainer = document.getElementById('edit-logs-container');
        const archiveListContainer = document.getElementById('archive-list-container');

        // Update tab buttons
        if (createDraftTab && draftsTab && editLogsTab && archiveTab) {
            createDraftTab.classList.toggle('active', tabName === 'create-draft');
            draftsTab.classList.toggle('active', tabName === 'drafts');
            editLogsTab.classList.toggle('active', tabName === 'edit-logs');
            archiveTab.classList.toggle('active', tabName === 'archive');
        }

        // Show/hide containers
        if (createDraftContainer && draftListContainer && editLogsContainer && archiveListContainer) {
            // Hide all containers first
            createDraftContainer.style.display = 'none';
            draftListContainer.style.display = 'none';
            editLogsContainer.style.display = 'none';
            archiveListContainer.style.display = 'none';

            // Show the active container
            if (tabName === 'create-draft') {
                createDraftContainer.style.display = 'block';
            } else if (tabName === 'drafts') {
                draftListContainer.style.display = 'block';
                this.loadDrafts();
            } else if (tabName === 'edit-logs') {
                editLogsContainer.style.display = 'block';
                this.showEditLogsWelcome();
            } else if (tabName === 'archive') {
                archiveListContainer.style.display = 'block';
                this.loadArchive();
            }
        }

        // Update URL without page refresh
        if (updateUrl) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
    }

    loadArchive() {
        const archiveList = document.getElementById('archive-list');
        if (!archiveList) return;

        archiveList.innerHTML = '<div class="archive-loading">Loading published drafts...</div>';

        fetch(`collaboration_draft.php?action=get_archive&tid=${this.tid}`)
            .then(response => response.json())
            .then(async data => {
                if (data.success) {
                    await this.displayArchive(data.drafts);
                } else {
                    archiveList.innerHTML = '<div class="archive-error">Failed to load archive</div>';
                }
            })
            .catch(error => {
                archiveList.innerHTML = '<div class="archive-error">Error loading archive</div>';
            });
    }

    async displayArchive(drafts) {
        const archiveList = document.getElementById('archive-list');
        if (!archiveList) return;

        if (drafts.length === 0) {
            archiveList.innerHTML = '<div class="archive-empty">No published drafts yet.</div>';
            return;
        }

        archiveList.innerHTML = '';

        for (const draft of drafts) {
            const archiveItem = document.createElement('div');
            archiveItem.className = 'archive-item';
            
            const template = await this.loadTemplate('collaboration_draft_archive_published_item', {
                draft_subject: draft.subject || 'Untitled Draft',
                draft_preview: draft.content.substring(0, 200) + (draft.content.length > 200 ? '...' : ''),
                created_by: draft.created_by,
                published_date: this.formatDate(draft.published_date || draft.created_date),
                draft_id: draft.draft_id
            });
            
            archiveItem.innerHTML = template;
            archiveList.appendChild(archiveItem);
        }
    }

    viewPublishedDraft(draftId) {
        // Open the published draft in a new tab or show details
        window.open(`collaboration_draft.php?tid=${this.tid}&draft_id=${draftId}&view=1`, '_blank');
    }

    loadSidebarPreference() {
        // Server-side preference is already applied via {$draft_view_expanded} template variable
        // No need to load from localStorage since the server handles the initial state
    }

    saveSidebarPreference(expanded) {
        fetch(`collaboration_draft.php?action=save_draft_view_preference&tid=${this.tid}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: `expanded=${expanded ? 1 : 0}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Draft view preference saved
                }
            })
            .catch(error => {
                // Error saving draft view preference
            });
    }

    // Modal Methods (copied from chat page)
    showUrlDialog(selectedText) {
        return new Promise((resolve) => {
            const modal = this.createModal('Insert Link', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="url-input">URL:</label>
                        <input type="url" id="url-input" placeholder="https://example.com" class="mycode-input">
                    </div>
                    <div class="mycode-input-group">
                        <label for="url-text">Link Text:</label>
                        <input type="text" id="url-text" placeholder="Click here" value="${selectedText || ''}" class="mycode-input">
                    </div>
                </div>
            `);

            const urlInput = modal.querySelector('#url-input');
            const textInput = modal.querySelector('#url-text');

            // Focus on URL input
            setTimeout(() => urlInput.focus(), 100);

            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    if (e.target === urlInput) {
                        textInput.focus();
                    } else {
                        this.confirmModal(modal, () => {
                            const url = urlInput.value.trim();
                            const text = textInput.value.trim();
                            if (url && text) {
                                resolve({ url, text });
                            }
                        });
                    }
                }
            };

            urlInput.addEventListener('keypress', handleKeyPress);
            textInput.addEventListener('keypress', handleKeyPress);

            this.showModal(modal, () => {
                const url = urlInput.value.trim();
                const text = textInput.value.trim();
                if (url && text) {
                    resolve({ url, text });
                }
            });
        });
    }

    showImageDialog() {
        return new Promise((resolve) => {
            const modal = this.createModal('Insert Image', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="img-url">Image URL:</label>
                        <input type="url" id="img-url" placeholder="https://example.com/image.jpg" class="mycode-input">
                    </div>
                    <div class="mycode-input-group">
                        <label for="img-alt">Alt Text (optional):</label>
                        <input type="text" id="img-alt" placeholder="Image description" class="mycode-input">
                    </div>
                    <div class="mycode-input-group">
                        <label for="img-width">Width (optional):</label>
                        <input type="number" id="img-width" placeholder="300" class="mycode-input">
                    </div>
                    <div class="mycode-input-group">
                        <label for="img-height">Height (optional):</label>
                        <input type="number" id="img-height" placeholder="200" class="mycode-input">
                    </div>
                </div>
            `);

            const urlInput = modal.querySelector('#img-url');
            const altInput = modal.querySelector('#img-alt');
            const widthInput = modal.querySelector('#img-width');
            const heightInput = modal.querySelector('#img-height');

            // Focus on URL input
            setTimeout(() => urlInput.focus(), 100);

            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    this.confirmModal(modal, () => {
                        const url = urlInput.value.trim();
                        if (url) {
                            resolve({ url });
                        }
                    });
                }
            };

            urlInput.addEventListener('keypress', handleKeyPress);
            altInput.addEventListener('keypress', handleKeyPress);
            widthInput.addEventListener('keypress', handleKeyPress);
            heightInput.addEventListener('keypress', handleKeyPress);

            this.showModal(modal, () => {
                const url = urlInput.value.trim();
                if (url) {
                    resolve({ url });
                }
            });
        });
    }

    showColorDialog(selectedText) {
        return new Promise((resolve) => {
            const modal = this.createModal('Text Color', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="color-input">Color:</label>
                        <div class="mycode-color-input-container">
                            <input type="text" id="color-input" placeholder="red, #ff0000, rgb(255,0,0)" class="mycode-input">
                            <input type="color" id="color-picker" class="mycode-color-picker">
                        </div>
                    </div>
                    <div class="mycode-preview">
                        <div class="mycode-color-preview" id="color-preview">${selectedText || 'Preview Text'}</div>
                    </div>
                </div>
            `);

            const colorInput = modal.querySelector('#color-input');
            const colorPicker = modal.querySelector('#color-picker');
            const preview = modal.querySelector('#color-preview');

            // Focus on color input
            setTimeout(() => colorInput.focus(), 100);

            // Update preview
            const updatePreview = () => {
                const color = colorInput.value.trim();
                if (color) {
                    preview.style.color = color;
                }
            };

            colorInput.addEventListener('input', updatePreview);
            colorPicker.addEventListener('input', (e) => {
                colorInput.value = e.target.value;
                updatePreview();
            });

            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    this.confirmModal(modal, () => {
                        const color = colorInput.value.trim();
                        if (color) {
                            resolve({ color });
                        }
                    });
                }
            };

            colorInput.addEventListener('keypress', handleKeyPress);

            this.showModal(modal, () => {
                const color = colorInput.value.trim();
                if (color) {
                    resolve({ color });
                }
            });
        });
    }

    showSizeDialog(selectedText) {
        return new Promise((resolve) => {
            const modal = this.createModal('Text Size', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="size-input">Size:</label>
                        <input type="number" id="size-input" placeholder="14" min="8" max="72" class="mycode-input">
                    </div>
                    <div class="mycode-preview">
                        <div class="mycode-size-preview" id="size-preview">${selectedText || 'Preview Text'}</div>
                    </div>
                </div>
            `);

            const sizeInput = modal.querySelector('#size-input');
            const preview = modal.querySelector('#size-preview');

            // Focus on size input
            setTimeout(() => sizeInput.focus(), 100);

            // Update preview
            const updatePreview = () => {
                const size = sizeInput.value.trim();
                if (size) {
                    preview.style.fontSize = size + 'px';
                }
            };

            sizeInput.addEventListener('input', updatePreview);

            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    this.confirmModal(modal, () => {
                        const size = sizeInput.value.trim();
                        if (size) {
                            resolve({ size });
                        }
                    });
                }
            };

            sizeInput.addEventListener('keypress', handleKeyPress);

            this.showModal(modal, () => {
                const size = sizeInput.value.trim();
                if (size) {
                    resolve({ size });
                }
            });
        });
    }

    createModal(title, content) {
        const modal = document.createElement('div');
        modal.className = 'mycode-modal-overlay';
        modal.innerHTML = `
            <div class="mycode-modal">
                <div class="mycode-modal-header">
                    <h3>${title}</h3>
                    <button class="mycode-modal-close">&times;</button>
                </div>
                <div class="mycode-modal-body">
                    ${content}
                </div>
                <div class="mycode-modal-footer">
                    <button class="mycode-btn mycode-btn-cancel">Cancel</button>
                    <button class="mycode-btn mycode-btn-confirm">Insert</button>
                </div>
            </div>
        `;
        return modal;
    }

    showModal(modal, onConfirm) {
        document.body.appendChild(modal);

        // Add event listeners
        const closeBtn = modal.querySelector('.mycode-modal-close');
        const cancelBtn = modal.querySelector('.mycode-btn-cancel');
        const confirmBtn = modal.querySelector('.mycode-btn-confirm');

        const closeModal = () => {
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
        };

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', () => {
            onConfirm();
            closeModal();
        });

        // Close on overlay click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Show modal with animation
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);
    }

    confirmModal(modal, onConfirm) {
        onConfirm();
        if (modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }

    // Settings functionality
    initializeSettings() {
        this.settingsOpen = false;
        this.bindSettingsEvents();
        this.loadDraftSettings();
    }

    bindSettingsEvents() {
        // Floating settings icon
        const floatingIcon = document.getElementById('floating-settings-icon');
        if (floatingIcon) {
            floatingIcon.addEventListener('click', () => {
                this.toggleSettingsSidebar();
            });
        }

        // Close sidebar button
        const closeBtn = document.getElementById('sidebar-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                this.closeSettingsSidebar();
            });
        }

        // Overlay click to close
        const overlay = document.getElementById('sidebar-overlay');
        if (overlay) {
            overlay.addEventListener('click', () => {
                this.closeSettingsSidebar();
            });
        }

        // Draft management settings
        this.bindDraftManagementEvents();
    }


    toggleSettingsSidebar() {
        const sidebar = document.getElementById('off-canvas-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const floatingIcon = document.getElementById('floating-settings-icon');

        if (this.settingsOpen) {
            this.closeSettingsSidebar();
        } else {
            this.openSettingsSidebar();
        }
    }

    openSettingsSidebar() {
        const sidebar = document.getElementById('off-canvas-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const floatingIcon = document.getElementById('floating-settings-icon');

        if (sidebar && overlay && floatingIcon) {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            floatingIcon.classList.add('sidebar-open');
            this.settingsOpen = true;
        }
    }

    closeSettingsSidebar() {
        const sidebar = document.getElementById('off-canvas-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const floatingIcon = document.getElementById('floating-settings-icon');

        if (sidebar && overlay && floatingIcon) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            floatingIcon.classList.remove('sidebar-open');
            this.settingsOpen = false;
        }
    }




    // Draft Management Settings
    bindDraftManagementEvents() {
        // Allow collaborators to publish
        const allowPublishSetting = document.getElementById('allow-collaborators-publish');
        if (allowPublishSetting) {
            allowPublishSetting.addEventListener('change', (e) => {
                this.saveDraftSetting('allowCollaboratorsPublish', e.target.checked);
                this.updatePublishingUI();
            });
        }

        // Publish as author setting
        const publishAsAuthorSetting = document.getElementById('publish-as-author');
        if (publishAsAuthorSetting) {
            publishAsAuthorSetting.addEventListener('change', (e) => {
                this.saveDraftSetting('publishAsAuthor', e.target.checked);
            });
        }

        // Publishing permissions dropdown
        const publishingPermissions = document.getElementById('publishing-permissions');
        if (publishingPermissions) {
            publishingPermissions.addEventListener('change', (e) => {
                this.saveDraftSetting('publishingPermissions', e.target.value);
                this.updateRolePermissionsUI(e.target.value);
            });
        }

        // Collaborator permission checkboxes
        this.bindCollaboratorPermissions();
    }

    updatePublishingUI() {
        const allowPublish = document.getElementById('allow-collaborators-publish');
        const publishAsAuthor = document.getElementById('publish-as-author');
        const publishingPermissions = document.getElementById('publishing-permissions');
        
        if (allowPublish && publishAsAuthor && publishingPermissions) {
            const isEnabled = allowPublish.checked;
            
            // Only disable publishAsAuthor when collaborators can't publish
            publishAsAuthor.disabled = !isEnabled;
            
            // Publishing permissions should always be enabled when the setting is available
            publishingPermissions.disabled = false;
            
            if (!isEnabled) {
                publishAsAuthor.checked = false;
                // Don't reset publishing permissions - let user keep their choice
            }
        }
    }

    updateRolePermissionsUI(permissionLevel) {
        const collaboratorPermissions = document.getElementById('collaborator-permissions');
        
        if (collaboratorPermissions) {
            if (permissionLevel === 'collaborators') {
                collaboratorPermissions.style.display = 'block';
                this.loadCollaboratorsForSettings();
            } else {
                collaboratorPermissions.style.display = 'none';
            }
        }
    }


    bindCollaboratorPermissions() {
        // This will be called when collaborators are loaded
        const collaboratorCheckboxes = document.querySelectorAll('.collaborator-checkboxes input[type="checkbox"]');
        collaboratorCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                this.updateCollaboratorPermissions();
            });
        });
    }

    updateCollaboratorPermissions() {
        const collaboratorCheckboxes = document.querySelectorAll('.collaborator-checkboxes input[type="checkbox"]:checked');
        const selectedCollaborators = Array.from(collaboratorCheckboxes).map(cb => cb.value);
        this.saveDraftSetting('allowedCollaborators', selectedCollaborators);
    }

    loadCollaboratorsForSettings() {
        fetch(`collaboration_draft.php?action=get_thread_collaborators&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.collaborators) {
                    this.renderCollaborators(data.collaborators);
                }
            })
            .catch(error => {
            });
    }

    renderCollaborators(collaborators) {
        const container = document.getElementById('collaborator-checkboxes');
        if (!container) return;

        container.innerHTML = '';

        if (collaborators.length === 0) {
            container.innerHTML = '<div class="setting-note">No collaborators found for this thread.</div>';
            return;
        }

        collaborators.forEach(collaborator => {
            const collaboratorItem = document.createElement('div');
            collaboratorItem.className = 'collaborator-item';
            
            const avatar = collaborator.avatar ? 
                `<img src="${collaborator.avatar}" class="collaborator-avatar" alt="${collaborator.username}">` :
                `<div class="collaborator-avatar collaborator-avatar-fallback">${collaborator.username.charAt(0).toUpperCase()}</div>`;
            
            collaboratorItem.innerHTML = `
                <label class="checkbox-container">
                    <input type="checkbox" value="${collaborator.uid}" class="checkbox-input">
                    ${avatar}
                    <div class="collaborator-info">
                        <div class="collaborator-name">${collaborator.username}</div>
                        <div class="collaborator-role">
                            <i class="${collaborator.role_icon || 'fas fa-user'}"></i> ${collaborator.role || 'Collaborator'}
                        </div>
                    </div>
                </label>
            `;
            
            container.appendChild(collaboratorItem);
        });

        // Re-bind event listeners
        this.bindCollaboratorPermissions();
    }

    saveDraftSetting(key, value) {
        const settings = this.getStoredDraftSettings();
        settings[key] = value;
        localStorage.setItem('collaboration_draft_management', JSON.stringify(settings));
        
        // Save to server
        this.saveSettingsToServer(settings);
        
        // Show notification for important changes
        if (key === 'allowCollaboratorsPublish' || key === 'publishAsAuthor') {
            this.showNotification('Draft management settings updated!', 'Settings have been saved successfully.', 'success', 8000);
        }
    }

    saveSettingsToServer(settings) {
        const formData = new FormData();
        formData.append('action', 'save_draft_settings');
        formData.append('allow_collaborators_publish', settings.allowCollaboratorsPublish ? 1 : 0);
        formData.append('publish_as_author', settings.publishAsAuthor ? 1 : 0);
        formData.append('publishing_permissions', settings.publishingPermissions);
        formData.append('allowed_collaborators', JSON.stringify(settings.allowedCollaborators));

        fetch('collaboration_draft.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text(); // Get as text first to check if it's JSON
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    this.showNotification('Settings saved successfully!', 'Your settings have been saved.', 'success', 8000);
                } else {
                    this.showNotification('Failed to save settings', data.error, 'error', 10000);
                }
            } catch (parseError) {
                this.showNotification('Server Error', 'Server returned invalid response. Please check the server logs.', 'error', 10000);
            }
        })
        .catch(error => {
            this.showNotification('Network Error', 'Error saving settings to server: ' + error.message, 'error', 10000);
        });
    }

    getStoredDraftSettings() {
        const defaultSettings = {
            allowCollaboratorsPublish: false,
            publishAsAuthor: false,
            publishingPermissions: 'all',
            allowedCollaborators: []
        };

        try {
            const stored = localStorage.getItem('collaboration_draft_management');
            return stored ? { ...defaultSettings, ...JSON.parse(stored) } : defaultSettings;
        } catch (e) {
            return defaultSettings;
        }
    }

    loadDraftSettings() {
        // First load from server, then fallback to localStorage
        this.loadSettingsFromServer().then(serverSettings => {
            if (serverSettings) {
                this.applyDraftSettings(serverSettings);
            } else {
                // Fallback to localStorage
                const settings = this.getStoredDraftSettings();
                this.applyDraftSettings(settings);
            }
        }).catch(error => {
            // Fallback to localStorage
            const settings = this.getStoredDraftSettings();
            this.applyDraftSettings(settings);
        });
        
        // Ensure dropdown is properly initialized
        this.initializeDropdown();
    }

    initializeDropdown() {
        const publishingPermissions = document.getElementById('publishing-permissions');
        if (publishingPermissions) {
            // Ensure dropdown is enabled and clickable
            publishingPermissions.disabled = false;
            publishingPermissions.style.pointerEvents = 'auto';
            publishingPermissions.style.opacity = '1';
        }
    }

    loadSettingsFromServer() {
        return fetch(`collaboration_draft.php?action=get_draft_settings&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.settings) {
                    return {
                        allowCollaboratorsPublish: data.settings.allow_collaborators_publish == 1,
                        publishAsAuthor: data.settings.publish_as_author == 1,
                        publishingPermissions: data.settings.publishing_permissions,
                        allowedCollaborators: data.settings.allowed_collaborators ? JSON.parse(data.settings.allowed_collaborators) : []
                    };
                }
                return null;
            });
    }

    applyDraftSettings(settings) {
        // Apply settings to UI
        const allowPublish = document.getElementById('allow-collaborators-publish');
        const publishAsAuthor = document.getElementById('publish-as-author');
        const publishingPermissions = document.getElementById('publishing-permissions');
        
        if (allowPublish) allowPublish.checked = settings.allowCollaboratorsPublish;
        if (publishAsAuthor) publishAsAuthor.checked = settings.publishAsAuthor;
        if (publishingPermissions) {
            publishingPermissions.value = settings.publishingPermissions;
            // Ensure dropdown is always enabled
            publishingPermissions.disabled = false;
        }
        
        // Update UI based on settings
        this.updatePublishingUI();
        this.updateRolePermissionsUI(settings.publishingPermissions);
        
        // Set collaborator checkboxes
        if (settings.allowedCollaborators) {
            const collaboratorCheckboxes = document.querySelectorAll('.collaborator-checkboxes input[type="checkbox"]');
            collaboratorCheckboxes.forEach(checkbox => {
                checkbox.checked = settings.allowedCollaborators.includes(checkbox.value);
            });
        }
    }

    // Check if current user can publish drafts
    // Note: This is for UI logic only. Real permission enforcement happens server-side.
    // Server-side checks include: thread owner, forum moderators, management usergroups, and collaborators with permission.
    canUserPublishDraft() {
        const settings = this.getStoredDraftSettings();
        
        // Thread owner can always publish (server-side will also check moderator/management permissions)
        if (this.isThreadOwner()) {
            return true;
        }
        
        // If collaborators can't publish, only thread owner/moderator/management can (checked server-side)
        if (!settings.allowCollaboratorsPublish) {
            return false; // Server will allow moderator/management users even if collaborators can't publish
        }
        
        // Check collaborator permission level
        if (settings.publishingPermissions === 'all') {
            return this.isCollaborator();
        } else if (settings.publishingPermissions === 'primary') {
            return this.isPrimaryContributor();
        } else if (settings.publishingPermissions === 'specific') {
            return this.hasAllowedRole(settings.allowedRoles);
        }
        
        return false;
    }

    // Check if user should publish as themselves or thread owner
    shouldPublishAsAuthor() {
        const settings = this.getStoredDraftSettings();
        return settings.publishAsAuthor && this.canUserPublishDraft();
    }

    // Helper methods for permission checking
    isThreadOwner() {
        // This would need to be set from the server-side data
        return this.currentUserId === this.threadOwnerId;
    }

    isCollaborator() {
        // Check if current user is in collaborators list
        return this.collaborators && this.collaborators.some(c => c.uid === this.currentUserId);
    }

    isPrimaryContributor() {
        // Check if user is the primary contributor for current draft
        return this.currentDraftId && this.isPrimaryContributorForDraft(this.currentDraftId);
    }

    hasAllowedRole(allowedRoles) {
        // Check if user has one of the allowed roles
        if (!this.collaborators) return false;
        
        const userRole = this.collaborators.find(c => c.uid === this.currentUserId);
        return userRole && allowedRoles.includes(userRole.role.toLowerCase());
    }

    isPrimaryContributorForDraft(draftId) {
        // This would need to be implemented based on contribution data
        // For now, return true if user is a collaborator
        return this.isCollaborator();
    }

    // Edit Logs System
    initializeEditLogs() {
        this.editLogsPagination = {
            currentPage: 1,
            totalPages: 1,
            totalLogs: 0,
            limit: 10,
            hasMore: false
        };
        
        this.bindEditLogsEvents();
        this.loadDraftsForEditLogs();
        
        // Auto-select the most recent draft and load its logs
        setTimeout(() => {
            const draftSelector = document.getElementById('edit-logs-draft-select');
            if (draftSelector && draftSelector.options.length > 1) {
                // Select the first option (most recent draft)
                draftSelector.selectedIndex = 1;
                const selectedDraftId = draftSelector.value;
                if (selectedDraftId) {
                    this.loadEditLogs(selectedDraftId, 1, true); // Reset pagination
                }
            }
        }, 500); // Small delay to ensure the selector is populated
    }

    bindEditLogsEvents() {
        // Draft selector change
        const draftSelect = document.getElementById('edit-logs-draft-select');
        if (draftSelect) {
            draftSelect.addEventListener('change', (e) => {
                const draftId = e.target.value;
                if (draftId) {
                    this.loadEditLogs(draftId, 1, true); // Reset pagination
                } else {
                    this.showEditLogsWelcome();
                }
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('refresh-edit-logs');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                const draftId = document.getElementById('edit-logs-draft-select').value;
                if (draftId) {
                    this.loadEditLogs(draftId, 1, true); // Reset pagination
                }
            });
        }
    }

    loadDraftsForEditLogs() {
        fetch(`collaboration_draft.php?action=get_all_drafts&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.populateDraftSelector(data.drafts);
                }
            })
            .catch(error => {
            });
    }

    populateDraftSelector(drafts) {
        const select = document.getElementById('edit-logs-draft-select');
        if (!select) {
            return;
        }

        // Clear existing options except the first one
        select.innerHTML = '<option value="">Select a draft to view logs...</option>';

        drafts.forEach(draft => {
            const option = document.createElement('option');
            option.value = draft.draft_id;
            option.textContent = `${draft.subject} (${draft.status})`;
            select.appendChild(option);
        });
        
    }

    loadEditLogs(draftId, page = 1, reset = false) {
        const content = document.getElementById('edit-logs-content');
        if (!content) {
            return;
        }

        // Reset pagination if requested
        if (reset) {
            this.editLogsPagination.currentPage = 1;
            this.editLogsPagination.totalPages = 1;
            this.editLogsPagination.totalLogs = 0;
            this.editLogsPagination.hasMore = false;
        }

        // Show loading indicator (only for first page or reset)
        if (page === 1 || reset) {
            content.innerHTML = `
                <div class="edit-logs-loading">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Loading edit logs...</span>
                    </div>
                </div>
            `;
        }

        const url = `collaboration_draft.php?action=get_edit_logs&draft_id=${draftId}&page=${page}&limit=${this.editLogsPagination.limit}`;
        
        fetch(url)
            .then(response => {
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    return data;
                } catch (e) {
                    throw e;
                }
            })
            .then(data => {
                if (data.success) {
                    // Update pagination info
                    this.editLogsPagination = {
                        currentPage: data.pagination.current_page,
                        totalPages: data.pagination.total_pages,
                        totalLogs: data.pagination.total_logs,
                        limit: data.pagination.limit,
                        hasMore: data.pagination.has_more
                    };
                    
                    if (page === 1 || reset) {
                        this.displayEditLogs(data.logs);
                    } else {
                        this.appendEditLogs(data.logs);
                    }
                    
                    this.updatePaginationControls();
                    
                    // Load contributions only for first page
                    if (page === 1 || reset) {
                        this.loadEditContributions(draftId);
                    }
                } else {
                    content.innerHTML = '<div class="edit-logs-error">Error loading logs: ' + (data.error || 'Unknown error') + '</div>';
                }
            })
            .catch(error => {
                content.innerHTML = '<div class="edit-logs-error">Error loading edit logs. Please try again.</div>';
            });
    }

    displayEditLogs(logs) {
        const content = document.getElementById('edit-logs-content');
        if (!content) return;

        if (logs.length === 0) {
            content.innerHTML = '<div class="edit-logs-empty">No edit logs found for this draft.</div>';
            return;
        }

        // Sort logs by date (most recent first)
        logs.sort((a, b) => new Date(b.dateline * 1000) - new Date(a.dateline * 1000));

        let html = `
            <div class="edit-logs-container-fixed">
                <div class="edit-logs-timeline">
        `;
        
        logs.forEach((log, index) => {
            const isFirst = index === 0;
            const isLast = index === logs.length - 1;
            const isRecent = index === 0; // Most recent log
            
            html += `
                <div class="edit-log-entry ${isFirst ? 'first' : ''} ${isLast ? 'last' : ''} ${isRecent ? 'recent' : ''}">
                    <div class="edit-log-header">
                        <div class="edit-log-user">
                            <img src="${log.avatar}" alt="${log.username}" class="edit-log-avatar" onerror="this.src='images/default_avatar.png'">
                            <span class="edit-log-username">${log.username}</span>
                            ${isRecent ? '<span class="recent-badge">Recent</span>' : ''}
                        </div>
                        <div class="edit-log-time">${log.formatted_time}</div>
                    </div>
                    <div class="edit-log-details">
                        <div class="edit-log-action">
                            <i class="fas fa-${this.getActionIcon(log.action)}"></i>
                            <span class="action-text">${this.getActionText(log.action)}</span>
                        </div>
                        <div class="edit-log-summary">${log.edit_summary}</div>
                        <div class="edit-log-stats">
                            ${log.characters_added > 0 ? `<span class="chars-added">+${log.characters_added} chars</span>` : ''}
                            ${log.characters_removed > 0 ? `<span class="chars-removed">-${log.characters_removed} chars</span>` : ''}
                            ${log.words_added > 0 ? `<span class="words-added">+${log.words_added} words</span>` : ''}
                            ${log.words_removed > 0 ? `<span class="words-removed">-${log.words_removed} words</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += `
                </div>
            </div>
        `;
        content.innerHTML = html;
    }

    appendEditLogs(logs) {
        const timeline = document.querySelector('.edit-logs-timeline');
        if (!timeline) return;

        logs.forEach((log, index) => {
            const isRecent = false; // Only first page logs are recent
            
            const logHtml = `
                <div class="edit-log-entry">
                    <div class="edit-log-header">
                        <div class="edit-log-user">
                            <img src="${log.avatar}" alt="${log.username}" class="edit-log-avatar" onerror="this.src='images/default_avatar.png'">
                            <span class="edit-log-username">${log.username}</span>
                        </div>
                        <div class="edit-log-time">${log.formatted_time}</div>
                    </div>
                    <div class="edit-log-details">
                        <div class="edit-log-action">
                            <i class="fas fa-${this.getActionIcon(log.action)}"></i>
                            <span class="action-text">${this.getActionText(log.action)}</span>
                        </div>
                        <div class="edit-log-summary">${log.edit_summary}</div>
                    </div>
                </div>
            `;
            
            timeline.insertAdjacentHTML('beforeend', logHtml);
        });
    }

    updatePaginationControls() {
        const content = document.getElementById('edit-logs-content');
        if (!content) return;

        // Remove existing pagination controls
        const existingControls = content.querySelector('.edit-logs-pagination');
        if (existingControls) {
            existingControls.remove();
        }

        // Only show pagination if there are multiple pages
        if (this.editLogsPagination.totalPages > 1) {
            const paginationHtml = `
                <div class="edit-logs-pagination">
                    <div class="pagination-info">
                        Showing ${this.editLogsPagination.currentPage} of ${this.editLogsPagination.totalPages} pages 
                        (${this.editLogsPagination.totalLogs} total logs)
                    </div>
                    <div class="pagination-controls">
                        ${this.editLogsPagination.hasMore ? `
                            <button class="btn-load-more" onclick="collaborationDraft.loadMoreEditLogs()">
                                <i class="fas fa-chevron-down"></i> Load More Logs
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
            
            content.insertAdjacentHTML('beforeend', paginationHtml);
        }
    }

    loadMoreEditLogs() {
        const draftSelector = document.getElementById('edit-logs-draft-select');
        if (!draftSelector || !draftSelector.value) return;

        const nextPage = this.editLogsPagination.currentPage + 1;
        this.loadEditLogs(draftSelector.value, nextPage);
    }

    loadEditContributions(draftId) {
        fetch(`collaboration_draft.php?action=get_edit_contributions&draft_id=${draftId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.displayEditContributions(data.contributions, data.total_chars);
                }
            })
            .catch(error => {
            });
    }

    displayEditContributions(contributions, totalChars) {
        const content = document.getElementById('edit-logs-content');
        if (!content) return;

        let html = '<div class="edit-contributions-summary">';
        html += '<h4><i class="fas fa-chart-pie"></i> Contribution Summary</h4>';
        html += '<div class="contributions-list">';

        contributions.forEach(contrib => {
            html += `
                <div class="contribution-item">
                    <div class="contribution-user">
                        <img src="${contrib.avatar || 'images/default_avatar.png'}" alt="${contrib.username}" class="contribution-avatar">
                        <span class="contribution-username">${contrib.username}</span>
                    </div>
                    <div class="contribution-stats">
                        <div class="contribution-percentage">${contrib.percentage}%</div>
                        <div class="contribution-details">
                            <span class="chars-contributed">${contrib.total_chars_added} characters</span>
                            <span class="words-contributed">${contrib.total_words_added} words</span>
                            <span class="edit-count">${contrib.edit_count} edits</span>
                        </div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        html += `<div class="total-contributions">Total: ${totalChars} characters</div>`;
        html += '</div>';

        content.innerHTML += html;
    }

    getActionIcon(action) {
        const icons = {
            'create': 'plus',
            'edit': 'edit',
            'delete': 'trash',
            'restore': 'undo'
        };
        return icons[action] || 'edit';
    }

    getActionText(action) {
        const texts = {
            'create': 'Created draft',
            'edit': 'Edited content',
            'delete': 'Deleted content',
            'restore': 'Restored content'
        };
        return texts[action] || 'Modified content';
    }

    showEditLogsWelcome() {
        const content = document.getElementById('edit-logs-content');
        if (!content) return;

        content.innerHTML = `
            <div class="edit-logs-welcome">
                <i class="fas fa-history"></i>
                <h4>Select a draft to view its edit history</h4>
                <p>See who made what changes and when</p>
            </div>
        `;
    }

    // Log draft edit when content changes
    logDraftEdit(contentBefore, contentAfter, action = 'edit') {
        if (!this.currentDraftId) {
            return;
        }

        const requestBody = `draft_id=${this.currentDraftId}&content_before=${encodeURIComponent(contentBefore)}&content_after=${encodeURIComponent(contentAfter)}&action_type=${action}`;

        fetch('collaboration_draft.php?action=log_draft_edit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: requestBody
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Edit logged successfully
            } else {
            }
        })
        .catch(error => {
        });
    }

    insertBlockquoteElement() {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        let bq = document.createElement('blockquote');
        if (selectedText) {
            bq.textContent = selectedText;
            range.deleteContents();
            range.insertNode(bq);
        } else {
            // Empty blockquote with caret inside
            bq.innerHTML = '<br>';
            range.insertNode(bq);
            const newRange = document.createRange();
            newRange.setStart(bq, 0);
            newRange.setEnd(bq, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }

    insertSpoilerElement() {
        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        const selectedText = selection.toString();
        let details = document.createElement('details');
        let summary = document.createElement('summary');
        summary.textContent = 'Spoiler';
        let div = document.createElement('div');
        if (selectedText) {
            div.textContent = selectedText;
            range.deleteContents();
            details.appendChild(summary);
            details.appendChild(div);
            range.insertNode(details);

            // Place cursor at **end** inside the new div after the inserted text
            const newRange = document.createRange();
            newRange.selectNodeContents(div);
            newRange.collapse(false);
            selection.removeAllRanges();
            selection.addRange(newRange);
        } else {
            div.innerHTML = '<br>';
            details.appendChild(summary);
            details.appendChild(div);
            range.insertNode(details);
            // Place caret *inside* the main div
            const newRange = document.createRange();
            newRange.setStart(div, 0);
            newRange.setEnd(div, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }
}
