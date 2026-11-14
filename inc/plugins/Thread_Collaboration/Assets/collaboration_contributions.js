/**
 * Collaboration Contributions Toggle JavaScript
 * Handles expand/collapse functionality for contribution stats
 */

var collaborationContributions = {
    init: function() {
        // Initialize all contribution toggle buttons
        var toggles = document.querySelectorAll('.collaboration-contributions-toggle');
        toggles.forEach(function(toggle) {
            var id = toggle.id.replace('_img', '');
            var content = document.getElementById(id + '_e');
            var icon = document.getElementById(id + '_icon');
            
            if (content && icon) {
                // Set initial state based on visibility
                if (content.style.display === 'none' || content.offsetHeight === 0) {
                    icon.className = 'fas fa-chevron-right';
                    toggle.title = 'Expand';
                } else {
                    icon.className = 'fas fa-chevron-down';
                    toggle.title = 'Collapse';
                }
            }
        });
    },

    toggle: function(id) {
        var content = document.getElementById(id + '_e');
        var icon = document.getElementById(id + '_icon');
        var toggle = document.getElementById(id + '_img');
        
        if (!content || !icon || !toggle) {
            return;
        }
        
        var isCollapsed = content.style.display === 'none' || content.offsetHeight === 0;
        
        if (isCollapsed) {
            // Expand
            content.style.display = 'block';
            icon.className = 'fas fa-chevron-down';
            toggle.title = 'Collapse';
            this.saveCollapsed(id, false);
        } else {
            // Collapse
            content.style.display = 'none';
            icon.className = 'fas fa-chevron-right';
            toggle.title = 'Expand';
            this.saveCollapsed(id, true);
        }
    },

    saveCollapsed: function(id, collapsed) {
        // Get current cookie value
        var cookieName = 'collaboration_contributions_collapsed';
        var currentValue = this.getCookie(cookieName) || '';
        var items = currentValue ? currentValue.split('|') : [];
        
        if (collapsed) {
            // Add to collapsed list if not already there
            if (items.indexOf(id) === -1) {
                items.push(id);
            }
        } else {
            // Remove from collapsed list
            var index = items.indexOf(id);
            if (index > -1) {
                items.splice(index, 1);
            }
        }
        
        // Save updated cookie
        var newValue = items.join('|');
        this.setCookie(cookieName, newValue, 365); // 1 year expiry
    },

    getCookie: function(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    },

    setCookie: function(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', collaborationContributions.init);
} else {
    collaborationContributions.init();
}
