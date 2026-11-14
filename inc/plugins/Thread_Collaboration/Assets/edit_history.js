/**
 * Thread Collaboration Edit History JavaScript
 * Handles diff viewer and restore functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize event listeners
    initializeEditHistory();
});

function initializeEditHistory() {
    // View diff buttons
    const viewDiffButtons = document.querySelectorAll('.view-diff-btn');
    viewDiffButtons.forEach(button => {
        button.addEventListener('click', function() {
            const historyId = this.getAttribute('data-history-id');
            showDiffModal(historyId);
        });
    });
    
    // Restore buttons
    const restoreButtons = document.querySelectorAll('.restore-btn');
    restoreButtons.forEach(button => {
        button.addEventListener('click', function() {
            const historyId = this.getAttribute('data-history-id');
            showRestoreModal(historyId);
        });
    });
    
    // Content preview expand/collapse
    const contentPreviews = document.querySelectorAll('.edit-content-preview');
    contentPreviews.forEach(preview => {
        preview.addEventListener('click', function() {
            const previewText = this.querySelector('.preview-text');
            if (previewText) {
                previewText.classList.toggle('expanded');
            }
        });
    });
    
    // Restore modal content expansion (using event delegation for dynamically created content)
    document.addEventListener('click', function(e) {
        if (e.target.closest('.restore-preview-text')) {
            const preview = e.target.closest('.restore-preview-text');
            console.log('Restore preview clicked, toggling expanded state');
            
            if (preview.classList.contains('expanded')) {
                // Collapse
                preview.classList.remove('expanded');
                preview.style.maxHeight = '120px';
                preview.style.overflow = 'hidden';
            } else {
                // Expand
                preview.classList.add('expanded');
                preview.style.maxHeight = 'none';
                preview.style.overflow = 'visible';
            }
        }
    });
    
    // Modal close buttons
    const closeButtons = document.querySelectorAll('.collaboration-modal-close');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.collaboration-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });
    
    // Close modals when clicking outside
    const modals = document.querySelectorAll('.collaboration-modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
}

function showDiffModal(historyId) {
    const modal = document.getElementById('diff-modal');
    const diffContent = document.getElementById('diff-content');
    const oldMeta = document.getElementById('diff-old-meta');
    const newMeta = document.getElementById('diff-new-meta');
    
    // Check if elements exist
    if (!modal || !diffContent) {
        console.error('Modal elements not found');
        return;
    }
    
    // Show loading
    if (diffContent) {
        diffContent.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading diff...</div>';
    }
    modal.style.display = 'block';
    
    // Fetch diff data
    fetch(`collaboration_edit_history.php?action=get_diff&history_id=${historyId}&pid=${getUrlParameter('pid')}&tid=${getUrlParameter('tid')}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (oldMeta) oldMeta.innerHTML = data.old_meta;
                if (newMeta) newMeta.innerHTML = data.new_meta;
                
                // Create client-side diff for better side-by-side display
                const oldText = data.old_content || '';
                const newText = data.new_content || '';
                const diff = createDiff(oldText, newText);
                
                if (diffContent) diffContent.innerHTML = generateDiffHTML(diff);
            } else {
                if (diffContent) diffContent.innerHTML = '<div class="error">Error loading diff: ' + data.error + '</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (diffContent) diffContent.innerHTML = '<div class="error">Error loading diff. Please try again.</div>';
        });
}

function showRestoreModal(historyId) {
    const modal = document.getElementById('restore-modal');
    const restorePreview = document.getElementById('restore-preview');
    const historyIdInput = document.getElementById('restore-history-id');
    
    // Check if elements exist
    if (!modal || !restorePreview) {
        console.error('Restore modal elements not found');
        return;
    }
    
    // Set history ID
    if (historyIdInput) {
        historyIdInput.value = historyId;
    }
    
    // Show loading
    restorePreview.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading preview...</div>';
    modal.style.display = 'block';
    
    // Fetch restore preview
    fetch(`collaboration_edit_history.php?action=get_restore_preview&history_id=${historyId}&pid=${getUrlParameter('pid')}&tid=${getUrlParameter('tid')}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                restorePreview.innerHTML = `
                    <div class="restore-preview-content">
                        <h4>Content to be restored:</h4>
                        <div class="restore-preview-text" style="max-height: 120px; overflow: hidden; transition: max-height 0.3s ease;">
                            ${escapeHtml(data.content)}
                            <div class="expand-overlay">Click to expand...</div>
                        </div>
                        ${data.subject ? `
                            <h4>Subject to be restored:</h4>
                            <div class="restore-preview-text" style="max-height: 120px; overflow: hidden; transition: max-height 0.3s ease;">
                                ${escapeHtml(data.subject)}
                                <div class="expand-overlay">Click to expand...</div>
                            </div>
                        ` : ''}
                    </div>
                `;
            } else {
                restorePreview.innerHTML = '<div class="error">Error loading preview: ' + data.error + '</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            restorePreview.innerHTML = '<div class="error">Error loading preview. Please try again.</div>';
        });
}

function closeDiffModal() {
    document.getElementById('diff-modal').style.display = 'none';
}

function closeRestoreModal() {
    document.getElementById('restore-modal').style.display = 'none';
}

function generateDiffHTML(diff) {
    if (!diff || !diff.length) {
        return '<div class="no-changes">No changes detected.</div>';
    }
    
    let html = '<div class="diff-lines">';
    
    diff.forEach(line => {
        const lineClass = line.type === 'added' ? 'diff-added' : 
                         line.type === 'removed' ? 'diff-removed' : 
                         line.type === 'changed' ? 'diff-changed' : 'diff-context';
        
        html += `<div class="diff-line ${lineClass}">`;
        
        // Left side (old version)
        html += `<div class="diff-line-left">`;
        html += `<span class="diff-line-number">${line.oldLine || ''}</span>`;
        html += `<span class="diff-line-content">${escapeHtml(line.oldContent || '')}</span>`;
        html += `</div>`;
        
        // Right side (new version)
        html += `<div class="diff-line-right">`;
        html += `<span class="diff-line-number">${line.newLine || ''}</span>`;
        html += `<span class="diff-line-content">${escapeHtml(line.newContent || '')}</span>`;
        html += `</div>`;
        
        html += '</div>';
    });
    
    html += '</div>';
    return html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getUrlParameter(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

// Improved diff algorithm for proper side-by-side display
function createDiff(oldText, newText) {
    const oldLines = oldText.split('\n');
    const newLines = newText.split('\n');
    
    const diff = [];
    let oldIndex = 0;
    let newIndex = 0;
    
    while (oldIndex < oldLines.length || newIndex < newLines.length) {
        if (oldIndex >= oldLines.length) {
            // Only new lines left - add them as additions
            diff.push({
                type: 'added',
                oldContent: '',
                newContent: newLines[newIndex],
                oldLine: null,
                newLine: newIndex + 1
            });
            newIndex++;
        } else if (newIndex >= newLines.length) {
            // Only old lines left - add them as removals
            diff.push({
                type: 'removed',
                oldContent: oldLines[oldIndex],
                newContent: '',
                oldLine: oldIndex + 1,
                newLine: null
            });
            oldIndex++;
        } else if (oldLines[oldIndex] === newLines[newIndex]) {
            // Lines are the same - show as context
            diff.push({
                type: 'context',
                oldContent: oldLines[oldIndex],
                newContent: newLines[newIndex],
                oldLine: oldIndex + 1,
                newLine: newIndex + 1
            });
            oldIndex++;
            newIndex++;
        } else {
            // Lines are different - try to find a match
            let found = false;
            let bestMatch = -1;
            let bestDistance = Infinity;
            
            // Look ahead in new lines for a match
            for (let i = newIndex + 1; i < Math.min(newIndex + 10, newLines.length); i++) {
                if (oldLines[oldIndex] === newLines[i]) {
                    bestMatch = i;
                    bestDistance = i - newIndex;
                    found = true;
                    break;
                }
            }
            
            if (found && bestDistance <= 3) {
                // Found a close match - add intermediate lines as additions
                for (let j = newIndex; j < bestMatch; j++) {
                    diff.push({
                        type: 'added',
                        oldContent: '',
                        newContent: newLines[j],
                        oldLine: null,
                        newLine: j + 1
                    });
                }
                newIndex = bestMatch;
            } else {
                // No good match found - add as changed
                diff.push({
                    type: 'changed',
                    oldContent: oldLines[oldIndex],
                    newContent: newLines[newIndex],
                    oldLine: oldIndex + 1,
                    newLine: newIndex + 1
                });
                oldIndex++;
                newIndex++;
            }
        }
    }
    
    return diff;
}
