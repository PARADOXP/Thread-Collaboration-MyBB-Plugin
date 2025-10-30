/**
 * Collaboration Chat JavaScript
 * Handles real-time chat functionality with AJAX
 */

class CollaborationChat {
    constructor(config) {
        this.tid = config.tid;
        this.lastMessageId = config.lastMessageId;
        this.refreshInterval = config.refreshInterval || 3000;
        this.typingTimeout = config.typingTimeout || 2000;
        this.maxMessageLength = config.maxMessageLength || 1000;
        this.currentPage = config.currentPage || 1;
        this.totalPages = config.totalPages || 1;
        this.totalMessages = config.totalMessages || 0;
        
        this.isTyping = false;
        this.typingTimer = null;
        this.refreshTimer = null;
        this.isOnline = true;
        this.isSourceView = false; // Track current view mode
        
        this.init();
    }
    
    init() {
        // Clear any existing notifications first
        this.clearAllNotifications();
        
        this.bindEvents();
        this.initializePagination();
        this.generatePaginationNumbers();
        this.initializeMyCodeToolbar();
        this.initializeViewToggle();
        this.initializeImageHandlers();
        this.resetTypingState(); // Ensure typing state is clean on init
        this.setInitialMode();
        this.startAutoRefresh();
        this.showOnlineIndicator();
        this.scrollToBottom();
        this.loadOnlineUsers();
        this.loadCollaborators();
        this.loadUserRole();
        this.updateMessageCount();
        this.loadChatViewPreference();
        this.showNotification('Chat Connected', 'You are now connected to the collaboration chat.', 'info');
        
    }
    
    bindEvents() {
        const chatForm = document.getElementById('chat-form');
        const chatMessages = document.getElementById('chat-messages');
        const scrollToBottomBtn = document.getElementById('scroll-to-bottom');
        
        
        // Form submission
        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.sendMessage();
        });
        
        // Input events for both input types
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        
        if (chatInputFormatted) {
            chatInputFormatted.addEventListener('input', () => {
                this.handleTyping();
                this.autoResizeTextarea();
            });
            
            chatInputFormatted.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }
        
        if (chatInputSource) {
            chatInputSource.addEventListener('input', () => {
                this.handleTyping();
                this.autoResizeTextarea();
            });
            
            chatInputSource.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }
        
        // Scroll events
        chatMessages.addEventListener('scroll', () => {
            this.handleScroll();
        });
        
        
        // Chat toggle button
        const chatToggleBtn = document.getElementById('chat-toggle-btn');
        if (chatToggleBtn) {
            chatToggleBtn.addEventListener('click', () => {
                this.toggleChatView();
            });
        }
        
        // Go to page modal
        this.initializeGoToPageModal();
        
        // Scroll to bottom button
        scrollToBottomBtn.addEventListener('click', () => {
            this.scrollToBottom();
        });
        
        // Window focus/blur events
        window.addEventListener('focus', () => {
            this.isOnline = true;
            this.showOnlineIndicator();
        });
        
        window.addEventListener('blur', () => {
            this.isOnline = false;
            this.hideOnlineIndicator();
        });
    }
    
    sendMessage() {
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        const chatSend = document.getElementById('chat-send');
        
        let message;
        if (this.isSourceView) {
            message = chatInputSource.value.trim();
        } else {
            // Convert formatted content to BBCode for sending
            const formattedContent = chatInputFormatted.innerHTML;
            message = this.convertFormattedToBBCode(formattedContent).trim();
        }
        
        // Debug: Log the message being sent
        console.log('Sending message:', message);
        
        if (!message) {
            return;
        }
        
        if (message.length > this.maxMessageLength) {
            this.showNotification('Message Too Long', 'Maximum ' + this.maxMessageLength + ' characters allowed.', 'warning');
            return;
        }
        
        // Disable input while sending and pause auto-refresh
        chatSend.disabled = true;
        if (this.isSourceView) {
            chatInputSource.disabled = true;
        } else {
            chatInputFormatted.contentEditable = false;
        }
        this.pauseAutoRefresh();
        
        // Prepare message data
        let messageData = `tid=${this.tid}&message=${encodeURIComponent(message)}`;
        
        // Add reply data if replying to a message
        if (this.currentReply) {
            messageData += `&reply_to=${this.currentReply.messageId}`;
        }
        
        // Send message via AJAX
        fetch('collaboration_chats.php?action=send_message', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: messageData
        })
        .then(response => {
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Add message to chat with original content
                this.addMessageToChat(data.html, data.original_content);
                // Update last message ID to prevent duplicate fetching
                if (data.message_id) {
                    this.lastMessageId = data.message_id;
                }
                // Clear the appropriate input
                if (this.isSourceView) {
                    chatInputSource.value = '';
                } else {
                    chatInputFormatted.innerHTML = '';
                }
                this.autoResizeTextarea();
                this.scrollToBottom();
                
                // Clear reply preview if replying
                if (this.currentReply) {
                    const replyPreview = document.querySelector('.reply-preview');
                    if (replyPreview) {
                        replyPreview.remove();
                    }
                    this.currentReply = null;
                }
                
                // Hide typing indicator immediately after sending
                this.hideTypingIndicator();
            } else {
                this.showNotification('Send Failed', data.error || 'Failed to send message', 'error');
            }
        })
        .catch(error => {
            this.showNotification('Send Error', 'Failed to send message. Please try again.', 'error');
        })
        .finally(() => {
            // Re-enable input and resume auto-refresh
            chatSend.disabled = false;
            if (this.isSourceView) {
                chatInputSource.disabled = false;
                chatInputSource.focus();
            } else {
                chatInputFormatted.contentEditable = true;
                chatInputFormatted.focus();
            }
            this.resumeAutoRefresh();
            
            // Reset typing state after sending message
            this.resetTypingState();
        });
    }
    
    addMessageToChat(html, originalContent = null) {
        const chatMessages = document.getElementById('chat-messages');
        const messageElement = document.createElement('div');
        messageElement.innerHTML = html;
        
        // Store original MyCode content for editing if provided
        if (originalContent) {
            const contentElement = messageElement.querySelector('.chat-message-content');
            if (contentElement) {
                contentElement.setAttribute('data-original-content', originalContent);
            }
        }
        
        // Remove typing indicator if present
        const typingIndicator = document.getElementById('typing-indicator');
        if (typingIndicator) {
            typingIndicator.style.display = 'none';
        }
        
        // Add message
        chatMessages.appendChild(messageElement);
        this.scrollToBottom();
    }
    
    startAutoRefresh() {
        this.refreshTimer = setInterval(() => {
            this.refreshMessages();
        }, this.refreshInterval);
    }
    
    pauseAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    }
    
    resumeAutoRefresh() {
        if (!this.refreshTimer) {
            this.startAutoRefresh();
        }
    }
    
    refreshMessages() {
        fetch(`collaboration_chats.php?action=get_messages&tid=${this.tid}&last_message_id=${this.lastMessageId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.messages.length > 0) {
                const chatMessages = document.getElementById('chat-messages');
                
                // Remove typing indicator if present
                const typingIndicator = document.getElementById('typing-indicator');
                if (typingIndicator) {
                    typingIndicator.style.display = 'none';
                }
                
                // Add new messages (check for duplicates)
                data.messages.forEach(message => {
                    // Check if message already exists
                    const existingMessage = chatMessages.querySelector(`[data-message-id="${message.message_id}"]`);
                    if (!existingMessage) {
                        const messageElement = document.createElement('div');
                        messageElement.innerHTML = message.html;
                        
                        // Store original MyCode content for editing if available
                        if (message.original_content) {
                            const contentElement = messageElement.querySelector('.chat-message-content');
                            if (contentElement) {
                                contentElement.setAttribute('data-original-content', message.original_content);
                            }
                        }
                        
                        chatMessages.appendChild(messageElement);
                    }
                });
                
                // Update last message ID
                this.lastMessageId = data.last_message_id;
                
    // Show new message notification
    // This notification appears when other users post new messages in the chat
    // It alerts the current user that there are new messages to read
    // The 'info' type displays a blue info icon and informational styling
    // Title: "New Message" - Brief, clear indication that a new message arrived
    // Message: "A new message has been posted in the chat." - Detailed information about the event
    // Type: 'info' - Uses blue styling and info icon for neutral, informational feedback
    // Only show notification if there are actually new messages (not on initial load)
    // if (data.messages.length > 0) {
    //     this.showNotification('New Message', 'A new message has been posted in the chat.', 'info', 2000);
    // }
                
                // Scroll to bottom if user is near bottom and auto-scroll is enabled
                const autoScrollEnabled = localStorage.getItem('auto-scroll') !== 'false';
                if (this.isNearBottom() && autoScrollEnabled) {
                    this.scrollToBottom();
                }
            }
        })
        .catch(error => {
            // Silently handle refresh errors
        });
    }
    
    handleTyping() {
        // Always show typing indicator when user is typing
        this.isTyping = true;
        this.showTypingIndicator();
        
        // Clear existing timer
        if (this.typingTimer) {
            clearTimeout(this.typingTimer);
        }
        
        // Set new timer
        this.typingTimer = setTimeout(() => {
            this.isTyping = false;
            this.hideTypingIndicator();
        }, this.typingTimeout);
    }
    
    showTypingIndicator() {
        const typingIndicator = document.getElementById('typing-indicator');
        if (typingIndicator) {
            typingIndicator.style.display = 'block';
            // Auto-scroll to bottom smoothly to show typing indicator
            this.scrollToBottomSmooth();
        }
    }
    
    hideTypingIndicator() {
        const typingIndicator = document.getElementById('typing-indicator');
        if (typingIndicator) {
            typingIndicator.style.display = 'none';
        }
    }
    
    resetTypingState() {
        this.isTyping = false;
        this.hideTypingIndicator();
        if (this.typingTimer) {
            clearTimeout(this.typingTimer);
            this.typingTimer = null;
        }
    }
    
    showOnlineIndicator() {
        const onlineIndicator = document.getElementById('online-indicator');
        if (onlineIndicator) {
            onlineIndicator.style.display = 'block';
            setTimeout(() => {
                onlineIndicator.style.display = 'none';
            }, 3000);
        }
    }
    
    hideOnlineIndicator() {
        const onlineIndicator = document.getElementById('online-indicator');
        if (onlineIndicator) {
            onlineIndicator.style.display = 'none';
        }
    }
    
    handleScroll() {
        const chatMessages = document.getElementById('chat-messages');
        const scrollToBottomBtn = document.getElementById('scroll-to-bottom');
        
        if (this.isNearBottom()) {
            scrollToBottomBtn.style.display = 'none';
        } else {
            scrollToBottomBtn.style.display = 'flex';
        }
    }
    
    isNearBottom() {
        const chatMessages = document.getElementById('chat-messages');
        const threshold = 150; // Increased threshold for larger chat window
        return (chatMessages.scrollTop + chatMessages.clientHeight) >= (chatMessages.scrollHeight - threshold);
    }
    
    scrollToBottom() {
        const chatMessages = document.getElementById('chat-messages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    scrollToBottomSmooth() {
        const chatMessages = document.getElementById('chat-messages');
        chatMessages.scrollTo({
            top: chatMessages.scrollHeight,
            behavior: 'smooth'
        });
    }
    
    autoResizeTextarea() {
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        
        if (this.isSourceView && chatInputSource) {
            chatInputSource.style.height = 'auto';
            chatInputSource.style.height = Math.min(chatInputSource.scrollHeight, 120) + 'px';
        } else if (!this.isSourceView && chatInputFormatted) {
            chatInputFormatted.style.height = 'auto';
            chatInputFormatted.style.height = Math.min(chatInputFormatted.scrollHeight, 120) + 'px';
        }
    }
    
    // Initialize pagination
    initializePagination() {
        const liveModeBtn = document.getElementById('live-mode-btn');
        const paginationModeBtn = document.getElementById('pagination-mode-btn');
        const paginationContainer = document.getElementById('chat-pagination');
        
        if (liveModeBtn && paginationModeBtn) {
            // Set initial mode based on URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const liveMode = urlParams.get('live') !== '0';
            
            if (liveMode) {
                this.setLiveMode();
            } else {
                this.setPaginationMode();
            }
            
            // Bind mode switch events
            liveModeBtn.addEventListener('click', () => {
                this.setLiveMode();
                window.location.href = `collaboration_chats.php?tid=${this.tid}&live=1`;
            });
            
            paginationModeBtn.addEventListener('click', () => {
                this.setPaginationMode();
                window.location.href = `collaboration_chats.php?tid=${this.tid}&live=0&page=1`;
            });
        }
        
        // Generate pagination numbers
        this.generatePaginationNumbers();
    }
    
    initializeMyCodeToolbar() {
        const mycodeButtons = document.querySelectorAll('.mycode-btn:not(.mycode-toggle-btn)');
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        
        if (!chatInputFormatted || !chatInputSource) return;
        
        mycodeButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const tag = button.getAttribute('data-tag');
                if (this.isSourceView) {
                    this.insertMyCode(tag, chatInputSource);
                } else {
                    this.insertFormattedMyCode(tag, chatInputFormatted);
                }
            });
        });
    }

    initializeViewToggle() {
        const toggleBtn = document.getElementById('toggle-view-btn');
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        
        if (!toggleBtn || !chatInputFormatted || !chatInputSource) return;

        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleView();
        });
    }
    
    insertMyCode(tag, input) {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selectedText = input.value.substring(start, end);
        let mycode = '';
        
        switch (tag) {
            case 'b':
                mycode = `[b]${selectedText}[/b]`;
                this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : 3);
                return;
            case 'i':
                mycode = `[i]${selectedText}[/i]`;
                this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : 3);
                return;
            case 'u':
                mycode = `[u]${selectedText}[/u]`;
                this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : 3);
                return;
            case 's':
                mycode = `[s]${selectedText}[/s]`;
                this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : 3);
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
                        this.insertMyCodeResult(mycode, input, start, end);
                    }
                });
                return;
            case 'img':
                this.showImageDialog().then(result => {
                    if (result && result.url) {
                        mycode = `[img]${result.url}[/img]`;
                        this.insertMyCodeResult(mycode, input, start, end);
                    }
                });
                return;
            case 'color':
                this.showColorDialog(selectedText).then(result => {
                    if (result && result.color) {
                        mycode = `[color=${result.color}]${selectedText}[/color]`;
                        this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : `[color=${result.color}]`.length);
                    }
                });
                return;
            case 'size':
                this.showSizeDialog(selectedText).then(result => {
                    if (result && result.size) {
                        mycode = `[size=${result.size}]${selectedText}[/size]`;
                        this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : `[size=${result.size}]`.length);
                    }
                });
                return;
            case 'spoiler':
                mycode = `[spoiler]${selectedText}[/spoiler]`;
                this.insertMyCodeResultWithCursor(mycode, input, start, end, selectedText ? mycode.length : 9);
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

        const newValue = input.value.substring(0, start) + mycode + input.value.substring(end);
        input.value = newValue;
        input.focus();
        input.setSelectionRange(start + mycode.length, start + mycode.length);
    }
    
    insertMyCodeResult(mycode, input, start, end) {
        const newValue = input.value.substring(0, start) + mycode + input.value.substring(end);
        input.value = newValue;
        input.focus();
        input.setSelectionRange(start + mycode.length, start + mycode.length);
    }

    insertMyCodeResultWithCursor(mycode, input, start, end, cursorPosition) {
        const newValue = input.value.substring(0, start) + mycode + input.value.substring(end);
        input.value = newValue;
        input.focus();
        
        // Force cursor positioning with a small delay to ensure DOM is updated
        setTimeout(() => {
            input.setSelectionRange(start + cursorPosition, start + cursorPosition);
        }, 10);
    }

    toggleView() {
        const toggleBtn = document.getElementById('toggle-view-btn');
        const chatInputFormatted = document.getElementById('chat-input-formatted');
        const chatInputSource = document.getElementById('chat-input-source');
        
        if (!toggleBtn || !chatInputFormatted || !chatInputSource) return;

        this.isSourceView = !this.isSourceView;
        
        if (this.isSourceView) {
            // Switch to source view
            chatInputFormatted.style.display = 'none';
            chatInputSource.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Formatted';
            toggleBtn.classList.add('active');
            
            // Convert formatted content to BBCode
            const formattedContent = chatInputFormatted.innerHTML;
            const bbcodeContent = this.convertFormattedToBBCode(formattedContent);
            chatInputSource.value = bbcodeContent;
            chatInputSource.focus();
        } else {
            // Switch to formatted view
            chatInputSource.style.display = 'none';
            chatInputFormatted.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-code"></i> Source';
            toggleBtn.classList.remove('active');
            
            // Convert BBCode to formatted content
            const bbcodeContent = chatInputSource.value;
            const formattedContent = this.convertBBCodeToFormatted(bbcodeContent);
            chatInputFormatted.innerHTML = formattedContent;
            chatInputFormatted.focus();
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
    
    // Beautiful URL Dialog
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
    
    // Beautiful Image Dialog
    showImageDialog() {
        return new Promise((resolve) => {
            const modal = this.createModal('Insert Image', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="img-input">Image URL:</label>
                        <input type="url" id="img-input" placeholder="https://example.com/image.jpg" class="mycode-input">
                    </div>
                    <div class="mycode-preview" id="img-preview" style="display: none;">
                        <img id="preview-img" style="max-width: 100%; height: auto; border-radius: 8px;">
                    </div>
                </div>
            `);
            
            const imgInput = modal.querySelector('#img-input');
            const preview = modal.querySelector('#img-preview');
            const previewImg = modal.querySelector('#preview-img');
            
            // Focus on input
            setTimeout(() => imgInput.focus(), 100);
            
            // Preview image on input
            imgInput.addEventListener('input', () => {
                const url = imgInput.value.trim();
                if (url && this.isValidImageUrl(url)) {
                    previewImg.src = url;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                }
            });
            
            // Handle Enter key
            imgInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.confirmModal(modal, () => {
                        const url = imgInput.value.trim();
                        if (url) {
                            resolve(url);
                        }
                    });
                }
            });
            
            this.showModal(modal, () => {
                const url = imgInput.value.trim();
                if (url) {
                    resolve({ url: url });
                }
            });
        });
    }
    
    // Beautiful Color Dialog
    showColorDialog(selectedText) {
        return new Promise((resolve) => {
            const modal = this.createModal('Text Color', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="color-input">Color:</label>
                        <div class="mycode-color-input-container">
                            <input type="color" id="color-picker" class="mycode-color-picker">
                            <input type="text" id="color-input" placeholder="red, #ff0000, rgb(255,0,0)" class="mycode-input">
                        </div>
                    </div>
                    <div class="mycode-input-group">
                        <label for="color-text">Text:</label>
                        <input type="text" id="color-text" placeholder="Colored text" value="${selectedText || ''}" class="mycode-input">
                    </div>
                    <div class="mycode-color-preview" id="color-preview">
                        <span id="preview-text">Preview</span>
                    </div>
                </div>
            `);
            
            const colorPicker = modal.querySelector('#color-picker');
            const colorInput = modal.querySelector('#color-input');
            const textInput = modal.querySelector('#color-text');
            const preview = modal.querySelector('#preview-text');
            
            // Focus on color input
            setTimeout(() => colorInput.focus(), 100);
            
            // Update preview
            const updatePreview = () => {
                const color = colorInput.value.trim() || colorPicker.value;
                const text = textInput.value.trim() || 'Preview';
                preview.textContent = text;
                preview.style.color = color;
            };
            
            colorPicker.addEventListener('input', () => {
                colorInput.value = colorPicker.value;
                updatePreview();
            });
            
            colorInput.addEventListener('input', updatePreview);
            textInput.addEventListener('input', updatePreview);
            
            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    if (e.target === colorInput) {
                        textInput.focus();
                    } else {
                        this.confirmModal(modal, () => {
                            const color = colorInput.value.trim();
                            const text = textInput.value.trim();
                            if (color && text) {
                                resolve({ color, text });
                            }
                        });
                    }
                }
            };
            
            colorInput.addEventListener('keypress', handleKeyPress);
            textInput.addEventListener('keypress', handleKeyPress);
            
            this.showModal(modal, () => {
                const color = colorInput.value.trim();
                const text = textInput.value.trim();
                if (color && text) {
                    resolve({ color, text });
                }
            });
        });
    }
    
    // Beautiful Size Dialog
    showSizeDialog(selectedText) {
        return new Promise((resolve) => {
            const modal = this.createModal('Text Size', `
                <div class="mycode-modal-content">
                    <div class="mycode-input-group">
                        <label for="size-input">Size (px):</label>
                        <input type="number" id="size-input" placeholder="16" min="8" max="72" class="mycode-input">
                    </div>
                    <div class="mycode-input-group">
                        <label for="size-text">Text:</label>
                        <input type="text" id="size-text" placeholder="Sized text" value="${selectedText || ''}" class="mycode-input">
                    </div>
                    <div class="mycode-size-preview" id="size-preview">
                        <span id="preview-text">Preview</span>
                    </div>
                </div>
            `);
            
            const sizeInput = modal.querySelector('#size-input');
            const textInput = modal.querySelector('#size-text');
            const preview = modal.querySelector('#preview-text');
            
            // Focus on size input
            setTimeout(() => sizeInput.focus(), 100);
            
            // Update preview
            const updatePreview = () => {
                const size = sizeInput.value || '16';
                const text = textInput.value.trim() || 'Preview';
                preview.textContent = text;
                preview.style.fontSize = size + 'px';
            };
            
            sizeInput.addEventListener('input', updatePreview);
            textInput.addEventListener('input', updatePreview);
            
            // Handle Enter key
            const handleKeyPress = (e) => {
                if (e.key === 'Enter') {
                    if (e.target === sizeInput) {
                        textInput.focus();
                    } else {
                        this.confirmModal(modal, () => {
                            const size = sizeInput.value.trim();
                            const text = textInput.value.trim();
                            if (size && text) {
                                resolve({ size, text });
                            }
                        });
                    }
                }
            };
            
            sizeInput.addEventListener('keypress', handleKeyPress);
            textInput.addEventListener('keypress', handleKeyPress);
            
            this.showModal(modal, () => {
                const size = sizeInput.value.trim();
                const text = textInput.value.trim();
                if (size && text) {
                    resolve({ size, text });
                }
            });
        });
    }
    
    // Modal creation and management
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
            document.body.removeChild(modal);
        };
        
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        
        confirmBtn.addEventListener('click', () => {
            onConfirm();
            closeModal();
        });
        
        // Animate in
        setTimeout(() => modal.classList.add('show'), 10);
    }
    
    confirmModal(modal, onConfirm) {
        const confirmBtn = modal.querySelector('.mycode-btn-confirm');
        confirmBtn.click();
    }
    
    isValidImageUrl(url) {
        return /\.(jpg|jpeg|png|gif|webp|svg)$/i.test(url) || url.includes('data:image');
    }
    
    // Initialize settings sidebar
    initializeSettingsSidebar() {
        const settingsBtn = document.getElementById('chat-settings-btn');
        const settingsSidebar = document.getElementById('chat-settings-sidebar');
        const settingsOverlay = document.getElementById('settings-overlay');
        const closeBtn = document.getElementById('settings-close-btn');
        const saveBtn = document.getElementById('save-settings');
        const resetBtn = document.getElementById('reset-settings');
        
        // Check if settings elements exist
        if (!settingsBtn || !settingsSidebar || !settingsOverlay || !closeBtn || !saveBtn || !resetBtn) {
            return; // Exit if settings elements don't exist
        }
        
        // Load saved settings
        this.loadSettings();
        
        // Open settings
        settingsBtn.addEventListener('click', () => {
            this.openSettings();
        });
        
        // Close settings
        closeBtn.addEventListener('click', () => {
            this.closeSettings();
        });
        
        // Close on overlay click
        settingsOverlay.addEventListener('click', () => {
            this.closeSettings();
        });
        
        // Save settings
        saveBtn.addEventListener('click', () => {
            this.saveSettings();
        });
        
        // Reset settings
        resetBtn.addEventListener('click', () => {
            this.resetSettings();
        });
        
        // Handle setting changes
        this.bindSettingEvents();
    }
    
    // Open settings sidebar
    openSettings() {
        const settingsSidebar = document.getElementById('chat-settings-sidebar');
        const settingsOverlay = document.getElementById('settings-overlay');
        const settingsBtn = document.getElementById('chat-settings-btn');
        
        if (settingsSidebar) settingsSidebar.classList.add('open');
        if (settingsOverlay) settingsOverlay.classList.add('show');
        if (settingsBtn) settingsBtn.classList.add('active');
        
        // Prevent body scroll
        document.body.style.overflow = 'hidden';
    }
    
    // Close settings sidebar
    closeSettings() {
        const settingsSidebar = document.getElementById('chat-settings-sidebar');
        const settingsOverlay = document.getElementById('settings-overlay');
        const settingsBtn = document.getElementById('chat-settings-btn');
        
        settingsSidebar.classList.remove('open');
        settingsOverlay.classList.remove('show');
        settingsBtn.classList.remove('active');
        
        // Restore body scroll
        document.body.style.overflow = '';
    }
    
    // Bind setting events
    bindSettingEvents() {
        // Notification settings
        const messageNotifications = document.getElementById('message-notifications');
        if (messageNotifications) {
            messageNotifications.addEventListener('change', (e) => {
                this.settings.messageNotifications = e.target.checked;
            });
        }
        
        const typingIndicators = document.getElementById('typing-indicators');
        if (typingIndicators) {
            typingIndicators.addEventListener('change', (e) => {
                this.settings.typingIndicators = e.target.checked;
            });
        }
        
        const soundNotifications = document.getElementById('sound-notifications');
        if (soundNotifications) {
            soundNotifications.addEventListener('change', (e) => {
                this.settings.soundNotifications = e.target.checked;
            });
        }
        
        const desktopNotifications = document.getElementById('desktop-notifications');
        if (desktopNotifications) {
            desktopNotifications.addEventListener('change', (e) => {
                this.settings.desktopNotifications = e.target.checked;
            });
        }
        
        // Pagination settings
        const messagesPerPage = document.getElementById('messages-per-page');
        if (messagesPerPage) {
            messagesPerPage.addEventListener('change', (e) => {
                this.settings.messagesPerPage = parseInt(e.target.value);
            });
        }
        
        const autoScroll = document.getElementById('auto-scroll');
        if (autoScroll) {
            autoScroll.addEventListener('change', (e) => {
                this.settings.autoScroll = e.target.checked;
            });
        }
        
        // Display settings
        const showTimestamps = document.getElementById('show-timestamps');
        if (showTimestamps) {
            showTimestamps.addEventListener('change', (e) => {
                this.settings.showTimestamps = e.target.checked;
                this.toggleTimestamps();
            });
        }
        
        const showAvatars = document.getElementById('show-avatars');
        if (showAvatars) {
            showAvatars.addEventListener('change', (e) => {
                this.settings.showAvatars = e.target.checked;
                this.toggleAvatars();
            });
        }
        
        const compactMode = document.getElementById('compact-mode');
        if (compactMode) {
            compactMode.addEventListener('change', (e) => {
                this.settings.compactMode = e.target.checked;
                this.toggleCompactMode();
            });
        }
        
        // Chat behavior
        const refreshInterval = document.getElementById('refresh-interval');
        if (refreshInterval) {
            refreshInterval.addEventListener('change', (e) => {
                this.settings.refreshInterval = parseInt(e.target.value);
                this.updateRefreshInterval();
            });
        }
        
        const showOnlineStatus = document.getElementById('show-online-status');
        if (showOnlineStatus) {
            showOnlineStatus.addEventListener('change', (e) => {
                this.settings.showOnlineStatus = e.target.checked;
                this.toggleOnlineStatus();
            });
        }
    }
    
    // Load settings from localStorage
    loadSettings() {
        const defaultSettings = {
            messageNotifications: true,
            typingIndicators: true,
            soundNotifications: true,
            desktopNotifications: false,
            messagesPerPage: 20,
            autoScroll: true,
            showTimestamps: true,
            showAvatars: true,
            compactMode: false,
            refreshInterval: 2000,
            showOnlineStatus: true
        };
        
        this.settings = JSON.parse(localStorage.getItem('chatSettings')) || defaultSettings;
        this.applySettings();
    }
    
    // Apply settings to UI
    applySettings() {
        // Check if settings elements exist before applying
        const messageNotifications = document.getElementById('message-notifications');
        const typingIndicators = document.getElementById('typing-indicators');
        const soundNotifications = document.getElementById('sound-notifications');
        const desktopNotifications = document.getElementById('desktop-notifications');
        const messagesPerPage = document.getElementById('messages-per-page');
        const autoScroll = document.getElementById('auto-scroll');
        const showTimestamps = document.getElementById('show-timestamps');
        const showAvatars = document.getElementById('show-avatars');
        const compactMode = document.getElementById('compact-mode');
        const refreshInterval = document.getElementById('refresh-interval');
        const showOnlineStatus = document.getElementById('show-online-status');
        
        if (messageNotifications) messageNotifications.checked = this.settings.messageNotifications;
        if (typingIndicators) typingIndicators.checked = this.settings.typingIndicators;
        if (soundNotifications) soundNotifications.checked = this.settings.soundNotifications;
        if (desktopNotifications) desktopNotifications.checked = this.settings.desktopNotifications;
        if (messagesPerPage) messagesPerPage.value = this.settings.messagesPerPage;
        if (autoScroll) autoScroll.checked = this.settings.autoScroll;
        if (showTimestamps) showTimestamps.checked = this.settings.showTimestamps;
        if (showAvatars) showAvatars.checked = this.settings.showAvatars;
        if (compactMode) compactMode.checked = this.settings.compactMode;
        if (refreshInterval) refreshInterval.value = this.settings.refreshInterval;
        if (showOnlineStatus) showOnlineStatus.checked = this.settings.showOnlineStatus;
    }
    
    // Save settings to localStorage
    saveSettings() {
        localStorage.setItem('chatSettings', JSON.stringify(this.settings));
        this.showNotification('Settings Saved', 'Your chat settings have been saved successfully.', 'success');
        this.closeSettings();
    }
    
    // Reset settings to default
    resetSettings() {
        localStorage.removeItem('chatSettings');
        this.loadSettings();
        this.showNotification('Settings Reset', 'Your chat settings have been reset to default.', 'info');
    }
    
    // Toggle timestamps
    toggleTimestamps() {
        const timestamps = document.querySelectorAll('.chat-time');
        timestamps.forEach(timestamp => {
            timestamp.style.display = this.settings.showTimestamps ? 'block' : 'none';
        });
    }
    
    // Toggle avatars
    toggleAvatars() {
        const avatars = document.querySelectorAll('.chat-avatar');
        avatars.forEach(avatar => {
            avatar.style.display = this.settings.showAvatars ? 'block' : 'none';
        });
    }
    
    // Toggle compact mode
    toggleCompactMode() {
        const chatContainer = document.querySelector('.collaboration-chat-container');
        if (this.settings.compactMode) {
            chatContainer.classList.add('compact-mode');
        } else {
            chatContainer.classList.remove('compact-mode');
        }
    }
    
    // Update refresh interval
    updateRefreshInterval() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = setInterval(() => {
                this.refreshMessages();
            }, this.settings.refreshInterval);
        }
    }
    
    // Toggle online status
    toggleOnlineStatus() {
        const onlineStatus = document.querySelectorAll('.user-status, .collaborator-status');
        onlineStatus.forEach(status => {
            status.style.display = this.settings.showOnlineStatus ? 'block' : 'none';
        });
    }
    
    // Extract MyCode from formatted HTML content
    extractMyCodeFromFormattedContent(htmlContent) {
        let mycodeContent = htmlContent;
        
        // Convert formatted HTML back to MyCode
        // Bold
        mycodeContent = mycodeContent.replace(/<strong>(.*?)<\/strong>/gi, '[b]$1[/b]');
        mycodeContent = mycodeContent.replace(/<b>(.*?)<\/b>/gi, '[b]$1[/b]');
        
        // Italic
        mycodeContent = mycodeContent.replace(/<em>(.*?)<\/em>/gi, '[i]$1[/i]');
        mycodeContent = mycodeContent.replace(/<i>(.*?)<\/i>/gi, '[i]$1[/i]');
        
        // Underline
        mycodeContent = mycodeContent.replace(/<u>(.*?)<\/u>/gi, '[u]$1[/u]');
        
        // Strikethrough
        mycodeContent = mycodeContent.replace(/<s>(.*?)<\/s>/gi, '[s]$1[/s]');
        mycodeContent = mycodeContent.replace(/<strike>(.*?)<\/strike>/gi, '[s]$1[/s]');
        
        // Color
        mycodeContent = mycodeContent.replace(/<span style="color:\s*([^"]+)">(.*?)<\/span>/gi, '[color=$1]$2[/color]');
        
        // Size
        mycodeContent = mycodeContent.replace(/<span style="font-size:\s*([^"]+)px">(.*?)<\/span>/gi, '[size=$1]$2[/size]');
        
        // URL
        mycodeContent = mycodeContent.replace(/<a href="([^"]+)"[^>]*>([^<]+)<\/a>/gi, '[url=$1]$2[/url]');
        
        // Image - this is the most important one for your case
        mycodeContent = mycodeContent.replace(/<img[^>]+src="([^"]+)"[^>]*>/gi, '[img]$1[/img]');
        
        // Code
        mycodeContent = mycodeContent.replace(/<code[^>]*>(.*?)<\/code>/gi, '[code]$1[/code]');
        
        // Quote
        mycodeContent = mycodeContent.replace(/<blockquote[^>]*>(.*?)<\/blockquote>/gi, '[quote]$1[/quote]');
        
        // Spoiler
        mycodeContent = mycodeContent.replace(/<details><summary>Spoiler<\/summary>(.*?)<\/details>/gi, '[spoiler]$1[/spoiler]');
        
        // List items
        mycodeContent = mycodeContent.replace(/<li>(.*?)<\/li>/gi, '[*]$1');
        mycodeContent = mycodeContent.replace(/<ul[^>]*>(.*?)<\/ul>/gi, '[list]$1[/list]');
        mycodeContent = mycodeContent.replace(/<ol[^>]*>(.*?)<\/ol>/gi, '[list]$1[/list]');
        
        // Remove any remaining HTML tags and clean up
        mycodeContent = mycodeContent.replace(/<[^>]*>/g, '');
        mycodeContent = mycodeContent.replace(/&nbsp;/g, ' ');
        mycodeContent = mycodeContent.replace(/&lt;/g, '<');
        mycodeContent = mycodeContent.replace(/&gt;/g, '>');
        mycodeContent = mycodeContent.replace(/&amp;/g, '&');
        mycodeContent = mycodeContent.replace(/&quot;/g, '"');
        
        return mycodeContent.trim();
    }
    
    // Initialize image click handlers
    initializeImageHandlers() {
        // Handle image clicks for size toggling
        document.addEventListener('click', (e) => {
            if (e.target.tagName === 'IMG' && e.target.classList.contains('chat-image')) {
                e.preventDefault();
                this.toggleImageSize(e.target);
            }
        });
    }
    
    // Toggle image size between thumbnail and full size
    toggleImageSize(img) {
        if (img.classList.contains('expanded')) {
            // Shrink to thumbnail
            img.classList.remove('expanded');
            img.style.maxWidth = '300px';
            img.style.maxHeight = '200px';
            img.title = 'Click to expand';
        } else {
            // Expand to full size
            img.classList.add('expanded');
            img.style.maxWidth = '100%';
            img.style.maxHeight = '500px';
            img.title = 'Click to shrink';
        }
    }
    
    setLiveMode() {
        const liveModeBtn = document.getElementById('live-mode-btn');
        const paginationModeBtn = document.getElementById('pagination-mode-btn');
        const paginationContainer = document.getElementById('chat-pagination');
        
        if (liveModeBtn) liveModeBtn.classList.add('active');
        if (paginationModeBtn) paginationModeBtn.classList.remove('active');
        if (paginationContainer) paginationContainer.style.display = 'none';
        
        // Reload all messages for live mode
        this.loadAllMessages();
        
        // Resume auto-refresh for live mode
        this.startAutoRefresh();
    }
    
    setInitialMode() {
        // Set initial mode based on URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const liveMode = urlParams.get('live');
        
        if (liveMode === '1') {
            this.setLiveMode();
        } else {
            this.setPaginationMode();
        }
    }
    
    setPaginationMode() {
        const liveModeBtn = document.getElementById('live-mode-btn');
        const paginationModeBtn = document.getElementById('pagination-mode-btn');
        const paginationContainer = document.getElementById('chat-pagination');
        
        if (liveModeBtn) liveModeBtn.classList.remove('active');
        if (paginationModeBtn) paginationModeBtn.classList.add('active');
        if (paginationContainer) paginationContainer.style.display = 'block';
        
        // Stop auto-refresh for pagination mode
        this.stopAutoRefresh();
        
        // Hide typing indicator in pagination mode (since it's for browsing history)
        const typingIndicator = document.getElementById('typing-indicator');
        if (typingIndicator) {
            typingIndicator.style.display = 'none';
        }
        
        // Reload messages with pagination
        this.loadPaginatedMessages();
        
        // Update pagination with settings
        this.updatePaginationWithSettings();
    }
    
    // Load all messages for live mode
    loadAllMessages() {
        fetch(`collaboration_chats.php?action=get_messages&tid=${this.tid}&live=1`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const chatMessages = document.getElementById('chat-messages');
                if (chatMessages) {
                    // Preserve typing indicator before clearing
                    const typingIndicator = document.getElementById('typing-indicator');
                    const typingIndicatorHTML = typingIndicator ? typingIndicator.outerHTML : '';
                    
                    // Clear existing messages
                    chatMessages.innerHTML = '';
                    
                    // Add all messages
                    data.messages.forEach(message => {
                        const messageElement = document.createElement('div');
                        messageElement.innerHTML = message.html;
                        
                        // Store original MyCode content for editing if available
                        if (message.original_content) {
                            const contentElement = messageElement.querySelector('.chat-message-content');
                            if (contentElement) {
                                contentElement.setAttribute('data-original-content', message.original_content);
                            }
                        }
                        
                        chatMessages.appendChild(messageElement);
                    });
                    
                    // Restore typing indicator after adding messages
                    if (typingIndicatorHTML) {
                        chatMessages.insertAdjacentHTML('beforeend', typingIndicatorHTML);
                    }
                    
                    // Update last message ID
                    this.lastMessageId = data.last_message_id;
                }
            }
        })
        .catch(error => {
            // Error loading all messages
        });
    }
    
    // Load paginated messages
    loadPaginatedMessages() {
        const currentPage = parseInt(new URLSearchParams(window.location.search).get('page')) || 1;
        const perPage = parseInt(new URLSearchParams(window.location.search).get('per_page')) || 15;
        
        
        fetch(`collaboration_chats.php?action=get_messages&tid=${this.tid}&live=0&page=${currentPage}&per_page=${perPage}`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const chatMessages = document.getElementById('chat-messages');
                if (chatMessages) {
                    // Preserve typing indicator before clearing
                    const typingIndicator = document.getElementById('typing-indicator');
                    const typingIndicatorHTML = typingIndicator ? typingIndicator.outerHTML : '';
                    
                    // Clear existing messages
                    chatMessages.innerHTML = '';
                    
                    // Add paginated messages
                    data.messages.forEach(message => {
                        const messageElement = document.createElement('div');
                        messageElement.innerHTML = message.html;
                        
                        // Store original MyCode content for editing if available
                        if (message.original_content) {
                            const contentElement = messageElement.querySelector('.chat-message-content');
                            if (contentElement) {
                                contentElement.setAttribute('data-original-content', message.original_content);
                            }
                        }
                        
                        chatMessages.appendChild(messageElement);
                    });
                    
                    // Restore typing indicator after adding messages
                    if (typingIndicatorHTML) {
                        chatMessages.insertAdjacentHTML('beforeend', typingIndicatorHTML);
                    }
                }
            }
        })
        .catch(error => {
            // Error loading paginated messages
        });
    }
    
    // Update pagination with settings
    updatePaginationWithSettings() {
        if (this.settings && this.settings.messagesPerPage && this.settings.messagesPerPage > 0) {
            // Update pagination to use settings
            const currentPage = parseInt(new URLSearchParams(window.location.search).get('page')) || 1;
            const newUrl = `collaboration_chats.php?tid=${this.tid}&live=0&page=${currentPage}&per_page=${this.settings.messagesPerPage}`;
            window.history.pushState({}, '', newUrl);
        }
    }
    
    generatePaginationNumbers() {
        const paginationNumbers = document.getElementById('pagination-numbers');
        const goToPageBtn = document.getElementById('go-to-page-btn');
        if (!paginationNumbers) return;
        
        const urlParams = new URLSearchParams(window.location.search);
        const currentPage = parseInt(urlParams.get('page')) || 1;
        const totalPages = this.totalPages || 1;
        
        // Clear existing numbers
        paginationNumbers.innerHTML = '';
        
        // Show "Go to page" button if there are many pages
        if (totalPages > 7) {
            if (goToPageBtn) {
                goToPageBtn.style.display = 'inline-flex';
            }
        } else {
            if (goToPageBtn) {
                goToPageBtn.style.display = 'none';
            }
        }
        
        // Generate page numbers (show max 7 pages)
        const startPage = Math.max(1, currentPage - 3);
        const endPage = Math.min(totalPages, currentPage + 3);
        
        for (let i = startPage; i <= endPage; i++) {
            const pageLink = document.createElement('a');
            pageLink.href = `collaboration_chats.php?tid=${this.tid}&live=0&page=${i}`;
            pageLink.className = `pagination-number ${i === currentPage ? 'current' : ''}`;
            pageLink.textContent = i;
            paginationNumbers.appendChild(pageLink);
        }
    }
    
    initializeGoToPageModal() {
        const goToPageBtn = document.getElementById('go-to-page-btn');
        const goToPageModal = document.getElementById('go-to-page-modal');
        const goToPageClose = document.getElementById('go-to-page-close');
        const goToPageInput = document.getElementById('go-to-page-input');
        const goToPageDecrease = document.getElementById('go-to-page-decrease');
        const goToPageIncrease = document.getElementById('go-to-page-increase');
        const goToPageGo = document.getElementById('go-to-page-go');
        
        if (!goToPageBtn || !goToPageModal) return;
        
        // Open modal
        goToPageBtn.addEventListener('click', (e) => {
            e.preventDefault();
            goToPageModal.classList.add('show');
            goToPageInput.focus();
            goToPageInput.value = this.currentPage || 1;
        });
        
        // Close modal
        const closeModal = () => {
            goToPageModal.classList.remove('show');
        };
        
        goToPageClose.addEventListener('click', closeModal);
        goToPageModal.addEventListener('click', (e) => {
            if (e.target === goToPageModal) {
                closeModal();
            }
        });
        
        // Decrease page number
        goToPageDecrease.addEventListener('click', () => {
            const currentValue = parseInt(goToPageInput.value) || 1;
            const newValue = Math.max(1, currentValue - 1);
            goToPageInput.value = newValue;
        });
        
        // Increase page number
        goToPageIncrease.addEventListener('click', () => {
            const currentValue = parseInt(goToPageInput.value) || 1;
            const newValue = Math.min(this.totalPages || 1, currentValue + 1);
            goToPageInput.value = newValue;
        });
        
        // Go to page
        goToPageGo.addEventListener('click', () => {
            const pageNumber = parseInt(goToPageInput.value);
            if (pageNumber >= 1 && pageNumber <= (this.totalPages || 1)) {
                window.location.href = `collaboration_chats.php?tid=${this.tid}&live=0&page=${pageNumber}`;
            }
        });
        
        // Handle Enter key
        goToPageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                goToPageGo.click();
            }
        });
    }
    
    stopAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    // Cleanup when page unloads
    destroy() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
        }
        if (this.typingTimer) {
            clearTimeout(this.typingTimer);
        }
    }
    
    // Load online users
    loadOnlineUsers() {
        fetch(`collaboration_chats.php?action=get_online_users&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Online users loaded successfully
                    this.updateOnlineUsers(data.users);
                }
            })
            .catch(error => {
                // Error loading online users
            });
    }
    
    // Update online users display
    updateOnlineUsers(users) {
        const onlineUsersList = document.getElementById('online-users-list');
        const onlineCount = document.getElementById('online-count');
        
        if (!onlineUsersList || !onlineCount) return;
        
        onlineCount.textContent = users.length;
        
        if (users.length === 0) {
            onlineUsersList.innerHTML = '<div class="no-users">No users online</div>';
            return;
        }
        
        const usersHTML = users.map(user => {
            // Create role class name from role (convert to lowercase and replace spaces with hyphens)
            const roleClass = user.role.toLowerCase().replace(/\s+/g, '-');
            
            // Handle avatar - use actual avatar URL from database
            const avatarHTML = user.avatar ? 
                `<img src="${user.avatar}" alt="${user.username}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` :
                `<div class="user-avatar-fallback">${user.username.charAt(0).toUpperCase()}</div>`;
            
            return `
                <div class="user-item">
                    <div class="user-avatar">
                        ${avatarHTML}
                    </div>
                    <div class="user-info">
                        <div class="user-name">
                            <a href="member.php?action=profile&uid=${user.uid}" style="color: inherit; text-decoration: none;">${user.username}</a>
                        </div>
                        <div class="user-role ${roleClass}">
                            <i class="${user.role_icon}"></i> ${user.role}
                        </div>
                    </div>
                    <div class="user-status online"></div>
                </div>
            `;
        }).join('');
        
        onlineUsersList.innerHTML = usersHTML;
    }
    
    // Load collaborators
    loadCollaborators() {
        fetch(`collaboration_chats.php?action=get_collaborators&tid=${this.tid}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Collaborators loaded successfully
                    this.updateCollaborators(data.collaborators);
                }
            })
            .catch(error => {
                // Error loading collaborators
            });
    }
    
    // Update collaborators display
    updateCollaborators(collaborators) {
        const collaboratorsList = document.getElementById('collaborators-list');
        const collaboratorsCount = document.getElementById('collaborators-count');
        
        if (!collaboratorsList || !collaboratorsCount) return;
        
        collaboratorsCount.textContent = collaborators.length;
        
        if (collaborators.length === 0) {
            collaboratorsList.innerHTML = '<div class="no-collaborators">No collaborators</div>';
            return;
        }
        
        const collaboratorsHTML = collaborators.map(collaborator => {
            // Generate roles HTML for all roles
            const rolesHTML = collaborator.roles.map(role => {
                const roleClass = role.role.toLowerCase().replace(/\s+/g, '-');
                return `
                    <div class="collaborator-role ${roleClass}">
                        <i class="${role.role_icon}"></i> ${role.role}
                    </div>
                `;
            }).join('');
            
            // Handle avatar - use actual avatar URL from database
            const avatarHTML = collaborator.avatar ? 
                `<img src="${collaborator.avatar}" alt="${collaborator.username}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">` :
                `<div class="collaborator-avatar-fallback">${collaborator.username.charAt(0).toUpperCase()}</div>`;
            
            return `
                <div class="collaborator-item">
                    <div class="collaborator-avatar">
                        ${avatarHTML}
                    </div>
                    <div class="collaborator-info">
                        <div class="collaborator-name">
                            <a href="member.php?action=profile&uid=${collaborator.uid}" style="color: inherit; text-decoration: none;">${collaborator.username}</a>
                        </div>
                        <div class="collaborator-roles">
                            ${rolesHTML}
                        </div>
                    </div>
                    <div class="collaborator-status"></div>
                </div>
            `;
        }).join('');
        
        collaboratorsList.innerHTML = collaboratorsHTML;
    }
    
    // Load user role
    loadUserRole() {
        fetch(`collaboration_chats.php?action=get_user_role&tid=${this.tid}`)
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
            const messages = document.querySelectorAll('.chat-message');
            totalMessages.textContent = messages.length;
        }
    }
    
    // Edit message functionality
    editMessage(messageId) {
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) {
            return;
        }
        
        const contentElement = messageElement.querySelector('.chat-message-content');
        // Store the current formatted content for cancel functionality
        const currentFormattedContent = contentElement.innerHTML;
        contentElement.setAttribute('data-original-formatted-content', currentFormattedContent);
        
        // Get the original MyCode content from the data attribute
        let originalContent = contentElement.getAttribute('data-original-content');
        
        // If no original content is stored, try to extract it from the formatted content
        if (!originalContent) {
            originalContent = this.extractMyCodeFromFormattedContent(contentElement.innerHTML);
        }
        
        // Final fallback to text content
        if (!originalContent) {
            originalContent = contentElement.textContent.trim();
        }
        
        // Create edit form
        const editForm = document.createElement('form');
        editForm.className = 'edit-message-form';
        editForm.innerHTML = `
            <div class="edit-message-container">
                <textarea class="edit-message-input" rows="3" maxlength="1000">${originalContent}</textarea>
                <div class="edit-message-actions">
                    <button type="submit" class="btn btn-sm btn-success">Save</button>
                    <button type="button" class="btn btn-sm btn-secondary cancel-edit-btn">Cancel</button>
                </div>
            </div>
        `;
        
        // Hide action buttons during editing
        const actionButtons = messageElement.querySelector('.chat-message-actions');
        if (actionButtons) {
            actionButtons.style.opacity = '0';
            actionButtons.style.visibility = 'hidden';
            actionButtons.style.pointerEvents = 'none';
        }
        
        // Replace content with edit form
        contentElement.innerHTML = '';
        contentElement.appendChild(editForm);
        
        // Focus and select text
        const textarea = editForm.querySelector('.edit-message-input');
        textarea.focus();
        textarea.select();
        
        // Add cancel button event listener
        const cancelBtn = editForm.querySelector('.cancel-edit-btn');
        cancelBtn.addEventListener('click', () => {
            editForm.remove();
            // Restore the original formatted content, not the raw MyCode
            // The original formatted content should be stored in the data attribute
            const originalFormattedContent = contentElement.getAttribute('data-original-formatted-content');
            if (originalFormattedContent) {
                contentElement.innerHTML = originalFormattedContent;
            } else {
                // Fallback: just remove the edit form and keep current content
                // This prevents the message from disappearing
            }
            // Show action buttons again
            const actionButtons = messageElement.querySelector('.chat-message-actions');
            if (actionButtons) {
                actionButtons.style.opacity = '';
                actionButtons.style.visibility = 'visible';
                actionButtons.style.pointerEvents = 'auto';
            }
        });
        
        // Handle form submission
        editForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const newContent = textarea.value.trim();
            
            if (newContent === originalContent) {
                editForm.remove();
                // Restore the original formatted content, not the raw MyCode
                const originalFormattedContent = contentElement.getAttribute('data-original-formatted-content');
                if (originalFormattedContent) {
                    contentElement.innerHTML = originalFormattedContent;
                }
                // Show action buttons again
                const actionButtons = messageElement.querySelector('.chat-message-actions');
                if (actionButtons) {
                    actionButtons.style.opacity = '';
                    actionButtons.style.visibility = 'visible';
                    actionButtons.style.pointerEvents = 'auto';
                }
                return;
            }
            
            if (newContent.length === 0) {
                this.showNotification('Empty Message', 'Message cannot be empty.', 'warning');
                return;
            }
            
            this.saveEditedMessage(messageId, newContent, contentElement);
        });
    }
    
    // Save edited message
    async saveEditedMessage(messageId, newContent, contentElement) {
        try {
            const response = await fetch('collaboration_chats.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=edit_message&message_id=${messageId}&content=${encodeURIComponent(newContent)}&tid=${this.tid}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Update the content with the formatted message from server
                if (result.formatted_content) {
                    contentElement.innerHTML = result.formatted_content;
                } else {
                    // Fallback: re-format the content locally
                    contentElement.innerHTML = newContent;
                }
                
                // Update the original content data attribute
                contentElement.setAttribute('data-original-content', newContent);
                
                // Add edited indicator
                const messageElement = contentElement.closest('.chat-message');
                const timeElement = messageElement.querySelector('.chat-time');
                if (timeElement && !timeElement.textContent.includes('(edited)')) {
                    timeElement.textContent += ' (edited)';
                }
                // Show action buttons again
                const actionButtons = messageElement.querySelector('.chat-message-actions');
                if (actionButtons) {
                    actionButtons.style.opacity = '';
                    actionButtons.style.visibility = 'visible';
                    actionButtons.style.pointerEvents = 'auto';
                }
                this.showNotification('Message Edited', 'Your message has been successfully updated.', 'success');
            } else {
                this.showNotification('Edit Failed', result.error || 'Failed to edit message.', 'error');
                // Show action buttons again even on error
                const messageElement = contentElement.closest('.chat-message');
                const actionButtons = messageElement.querySelector('.chat-message-actions');
                if (actionButtons) {
                    actionButtons.style.opacity = '';
                    actionButtons.style.visibility = 'visible';
                    actionButtons.style.pointerEvents = 'auto';
                }
            }
        } catch (error) {
            this.showNotification('Edit Error', 'An error occurred while editing the message.', 'error');
            // Show action buttons again on error
            const messageElement = contentElement.closest('.chat-message');
            const actionButtons = messageElement.querySelector('.chat-message-actions');
            if (actionButtons) {
                actionButtons.style.opacity = '';
                actionButtons.style.visibility = 'visible';
                actionButtons.style.pointerEvents = 'auto';
            }
        }
    }
    
    // Reply to message functionality
    replyToMessage(messageId) {
        const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageElement) {
            return;
        }
        
        // Get message content and author
        const contentElement = messageElement.querySelector('.chat-message-content');
        const usernameElement = messageElement.querySelector('.chat-username');
        const messageIdElement = messageElement.querySelector('.chat-message-id');
        
        if (!contentElement || !usernameElement || !messageIdElement) {
            return;
        }
        
        const messageContent = contentElement.textContent.trim();
        const username = usernameElement.textContent.trim();
        const messageIdText = messageIdElement.textContent.trim();
        
        // Create reply preview
        const replyPreview = document.createElement('div');
        replyPreview.className = 'reply-preview';
        replyPreview.innerHTML = `
            <div class="reply-preview-content">
                <div class="reply-preview-header">
                    <i class="fas fa-reply"></i>
                    <span class="reply-preview-label">Replying to ${username}</span>
                    <button class="reply-cancel-btn" onclick="this.parentElement.parentElement.parentElement.remove()" title="Cancel reply">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="reply-preview-message">
                    <div class="reply-preview-message-id">${messageIdText}</div>
                    <div class="reply-preview-message-content">${messageContent.length > 100 ? messageContent.substring(0, 100) + '...' : messageContent}</div>
                </div>
            </div>
        `;
        
        // Add reply preview to chat input area
        const chatInputContainer = document.querySelector('.chat-input-container');
        if (chatInputContainer) {
            // Remove any existing reply preview
            const existingReply = chatInputContainer.querySelector('.reply-preview');
            if (existingReply) {
                existingReply.remove();
            }
            
            // Add new reply preview
            chatInputContainer.insertBefore(replyPreview, chatInputContainer.firstChild);
            
            // Store reply data for sending
            this.currentReply = {
                messageId: messageId,
                username: username,
                content: messageContent
            };
            
            // Focus on input
            const chatInputFormatted = document.getElementById('chat-input-formatted');
            const chatInputSource = document.getElementById('chat-input-source');
            if (this.isSourceView && chatInputSource) {
                chatInputSource.focus();
            } else if (!this.isSourceView && chatInputFormatted) {
                chatInputFormatted.focus();
            }
        }
    }
    
    // Delete message functionality
    async deleteMessage(messageId) {
        // Show custom confirmation dialog
        const confirmed = await this.showConfirmDialog(
            'Delete Message',
            'Are you sure you want to delete this message? This action cannot be undone.',
            'warning',
            'Delete',
            'Cancel'
        );
        
        if (!confirmed) {
            return;
        }
        
        try {
            const response = await fetch('collaboration_chats.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_message&message_id=${messageId}&tid=${this.tid}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Remove the message from the DOM
                const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageElement) {
                    messageElement.remove();
                    this.updateMessageCount();
                }
                this.showNotification('Message Deleted', 'The message has been successfully deleted.', 'success');
            } else {
                this.showNotification('Delete Failed', result.error || 'Failed to delete message.', 'error');
            }
        } catch (error) {
            this.showNotification('Delete Error', 'An error occurred while deleting the message.', 'error');
        }
    }
    
    // Notification system
    showNotification(title, message, type = 'info', duration = 4000) {
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
        
        notification.innerHTML = `
            <div class="notification-header">
                <div class="notification-title">
                    <i class="notification-icon ${icons[type] || icons.info}"></i>
                    ${title}
                </div>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="notification-message">${message}</div>
            <div class="notification-progress"></div>
        `;
        
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
    
    // Chat view toggle functionality
    toggleChatView() {
        const container = document.querySelector('.collaboration-chat-container');
        const toggleBtn = document.getElementById('chat-toggle-btn');
        const icon = toggleBtn.querySelector('i');
        
        if (container.classList.contains('expanded')) {
            // Collapse - show sidebar
            container.classList.remove('expanded');
            toggleBtn.classList.remove('expanded');
            icon.style.transform = 'rotateY(0deg)';
            this.saveChatViewPreference(false);
        } else {
            // Expand - hide sidebar
            container.classList.add('expanded');
            toggleBtn.classList.add('expanded');
            icon.style.transform = 'rotateY(180deg)';
            this.saveChatViewPreference(true);
        }
    }
    
    loadChatViewPreference() {
        // Check if the container already has the expanded class (server-side rendered)
        const container = document.querySelector('.collaboration-chat-container');
        const toggleBtn = document.getElementById('chat-toggle-btn');
        const icon = toggleBtn.querySelector('i');
        
        if (container.classList.contains('expanded')) {
            // Server-side already applied the expanded state
            toggleBtn.classList.add('expanded');
            icon.style.transform = 'rotateY(180deg)';
        }
        
        // Still fetch from server to ensure consistency, but don't override if already set
        fetch(`collaboration_chats.php?action=get_chat_view_preference&tid=${this.tid}`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.expanded && !container.classList.contains('expanded')) {
                // Only apply if server-side didn't already set it
                container.classList.add('expanded');
                toggleBtn.classList.add('expanded');
                icon.style.transform = 'rotateY(180deg)';
            }
        })
        .catch(error => {
            // Error loading chat view preference
        });
    }
    
    saveChatViewPreference(expanded) {
        fetch(`collaboration_chats.php?action=save_chat_view_preference&tid=${this.tid}`, {
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
                // Chat view preference saved
            }
        })
        .catch(error => {
            // Error saving chat view preference
        });
    }
    
    // Custom confirmation dialog
    showConfirmDialog(title, message, type = 'warning', confirmText = 'Confirm', cancelText = 'Cancel') {
        return new Promise((resolve) => {
            const container = document.getElementById('notification-container');
            if (!container) {
                resolve(false);
                return;
            }
            
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 10001;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(5px);
            `;
            
            const dialog = document.createElement('div');
            dialog.style.cssText = `
                background: white;
                border-radius: 16px;
                padding: 24px;
                max-width: 400px;
                width: 90%;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                transform: scale(0.8);
                transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            `;
            
            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };
            
            const colors = {
                success: '#4ecdc4',
                error: '#ff6b6b',
                warning: '#feca57',
                info: '#45b7d1'
            };
            
            dialog.innerHTML = `
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <i class="${icons[type] || icons.info}" style="font-size: 24px; color: ${colors[type] || colors.info}; margin-right: 12px;"></i>
                    <h3 style="margin: 0; color: #333; font-size: 18px;">${title}</h3>
                </div>
                <p style="margin: 0 0 24px 0; color: #666; line-height: 1.5;">${message}</p>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button id="cancel-btn" style="
                        background: #f8f9fa;
                        border: 1px solid #dee2e6;
                        color: #6c757d;
                        padding: 10px 20px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: 500;
                        transition: all 0.2s ease;
                    ">${cancelText}</button>
                    <button id="confirm-btn" style="
                        background: ${colors[type] || colors.info};
                        border: none;
                        color: white;
                        padding: 10px 20px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: 500;
                        transition: all 0.2s ease;
                    ">${confirmText}</button>
                </div>
            `;
            
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            
            // Animate in
            setTimeout(() => {
                dialog.style.transform = 'scale(1)';
            }, 10);
            
            // Button handlers
            const cancelBtn = dialog.querySelector('#cancel-btn');
            const confirmBtn = dialog.querySelector('#confirm-btn');
            
            const cleanup = () => {
                dialog.style.transform = 'scale(0.8)';
                setTimeout(() => {
                    if (overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                }, 300);
            };
            
            cancelBtn.addEventListener('click', () => {
                cleanup();
                resolve(false);
            });
            
            confirmBtn.addEventListener('click', () => {
                cleanup();
                resolve(true);
            });
            
            // Close on overlay click
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    cleanup();
                    resolve(false);
                }
            });
            
            // Close on escape key
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    cleanup();
                    resolve(false);
                    document.removeEventListener('keydown', handleEscape);
                }
            };
            document.addEventListener('keydown', handleEscape);
        });
    }
    
    // Initialize toggle switches for chat controls
    
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
            // Place cursor at end inside the new div after the inserted text
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
            // Place caret inside the main div
            const newRange = document.createRange();
            newRange.setStart(div, 0);
            newRange.setEnd(div, 0);
            selection.removeAllRanges();
            selection.addRange(newRange);
        }
    }
    
}
// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.collaborationChat) {
        window.collaborationChat.destroy();
    }
});

// Global functions for onclick handlers
function editMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.editMessage(messageId);
    }
}

function deleteMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.deleteMessage(messageId);
    }
}

// Export for global access
function replyToMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.replyToMessage(messageId);
    }
}

window.CollaborationChat = CollaborationChat;

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.collaborationChat) {
        window.collaborationChat.destroy();
    }
});

// Global functions for onclick handlers
function editMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.editMessage(messageId);
    }
}

function deleteMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.deleteMessage(messageId);
    }
}

// Export for global access
function replyToMessage(messageId) {
    if (window.collaborationChat) {
        window.collaborationChat.replyToMessage(messageId);
    }
}

window.CollaborationChat = CollaborationChat;
