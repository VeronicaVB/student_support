// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Chat interface module for Student Support Agent.
 *
 * @module     local_student_support/chat
 * @copyright  2025 Veronica Bermegui
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import Log from 'core/log';

/**
 * Chat class to manage the chat interface.
 */
class Chat {
    /**
     * Constructor.
     *
     * @param {number} courseId - The course ID.
     * @param {string} sessionId - The session ID.
     * @param {number} userId - The user ID.
     */
    constructor(courseId, sessionId, userId) {
        this.courseId = courseId;
        this.sessionId = sessionId;
        this.userId = userId;
        this.isProcessing = false;
        this.retryMessage = null;

        // DOM elements.
        this.container = document.getElementById('student-support-chat');
        this.messagesContainer = document.getElementById('chat-messages');
        this.chatForm = document.getElementById('chat-form');
        this.chatInput = document.getElementById('chat-input');
        this.sendButton = document.getElementById('send-btn');
        this.typingIndicator = document.getElementById('typing-indicator');
        this.errorContainer = document.getElementById('chat-error');
        this.charCount = document.getElementById('char-count');
        this.newSessionBtn = document.getElementById('chat-new-session');

        // Templates.
        this.userMessageTemplate = document.getElementById('user-message-template');
        this.agentMessageTemplate = document.getElementById('agent-message-template');

        this.init();
    }

    /**
     * Initialize the chat interface.
     */
    init() {
        this.bindEvents();
        this.focusInput();
        Log.debug('[StudentSupport] Chat initialized', {
            courseId: this.courseId,
            sessionId: this.sessionId
        });
    }

    /**
     * Bind event listeners.
     */
    bindEvents() {
        // Form submission.
        this.chatForm.addEventListener('submit', (e) => this.handleSubmit(e));

        // Input handling.
        this.chatInput.addEventListener('input', () => this.handleInput());
        this.chatInput.addEventListener('keydown', (e) => this.handleKeyDown(e));

        // New session button.
        this.newSessionBtn.addEventListener('click', () => this.startNewSession());

        // Retry button in error container.
        const retryBtn = this.errorContainer.querySelector('.retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => this.retryLastMessage());
        }
    }

    /**
     * Handle form submission.
     *
     * @param {Event} e - The submit event.
     */
    async handleSubmit(e) {
        e.preventDefault();

        const message = this.chatInput.value.trim();
        if (!message || this.isProcessing) {
            return;
        }

        await this.sendMessage(message);
    }

    /**
     * Handle input changes.
     */
    handleInput() {
        const length = this.chatInput.value.length;
        this.charCount.textContent = length;

        // Enable/disable send button.
        const hasContent = this.chatInput.value.trim().length > 0;
        this.sendButton.disabled = !hasContent || this.isProcessing;

        // Auto-resize textarea.
        this.autoResizeTextarea();
    }

    /**
     * Handle keydown events.
     *
     * @param {KeyboardEvent} e - The keydown event.
     */
    handleKeyDown(e) {
        // Enter without shift sends message.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!this.sendButton.disabled) {
                this.chatForm.dispatchEvent(new Event('submit'));
            }
        }
    }

    /**
     * Auto-resize the textarea based on content.
     */
    autoResizeTextarea() {
        this.chatInput.style.height = 'auto';
        const maxHeight = 150;
        const newHeight = Math.min(this.chatInput.scrollHeight, maxHeight);
        this.chatInput.style.height = `${newHeight}px`;
    }

    /**
     * Send a message to the agent.
     *
     * @param {string} message - The message to send.
     */
    async sendMessage(message) {
        this.isProcessing = true;
        this.retryMessage = message;
        this.hideError();
        this.updateUIState();

        // Add user message to chat.
        this.addUserMessage(message);

        // Clear input.
        this.chatInput.value = '';
        this.charCount.textContent = '0';
        this.autoResizeTextarea();

        // Show typing indicator.
        this.showTypingIndicator();

        try {
            const response = await this.callAgentService(message);
            this.hideTypingIndicator();

            if (response.success) {
                this.addAgentMessage(response.message);
                this.retryMessage = null;

                // Announce to screen readers.
                this.announceMessage('messagereceived');
            } else {
                this.showError(response.message || await getString('chat:connectionerror', 'local_student_support'));
            }
        } catch (error) {
            Log.error('[StudentSupport] Error sending message:', error);
            this.hideTypingIndicator();
            this.showError(await getString('chat:connectionerror', 'local_student_support'));
        } finally {
            this.isProcessing = false;
            this.updateUIState();
            this.focusInput();
        }
    }

    /**
     * Call the agent web service.
     *
     * @param {string} message - The message to send.
     * @returns {Promise<Object>} The response from the service.
     */
    async callAgentService(message) {
        const request = {
            methodname: 'local_student_support_send_message',
            args: {
                courseid: this.courseId,
                sessionid: this.sessionId,
                message: message
            }
        };

        try {
            const responses = await Ajax.call([request]);
            return responses[0];
        } catch (error) {
            Log.error('[StudentSupport] AJAX error:', error);
            throw error;
        }
    }

    /**
     * Add a user message to the chat.
     *
     * @param {string} message - The message text.
     */
    addUserMessage(message) {
        const template = this.userMessageTemplate.content.cloneNode(true);
        const messageText = template.querySelector('.message-text');
        const messageTime = template.querySelector('.message-time');

        messageText.textContent = message;
        messageTime.textContent = this.formatTime(new Date());

        this.messagesContainer.appendChild(template);
        this.scrollToBottom();
    }

    /**
     * Add an agent message to the chat.
     *
     * @param {string} message - The message text (may contain markdown).
     */
    addAgentMessage(message) {
        const template = this.agentMessageTemplate.content.cloneNode(true);
        const messageText = template.querySelector('.message-text');
        const messageTime = template.querySelector('.message-time');

        // Render message with basic formatting.
        messageText.innerHTML = this.formatMessage(message);
        messageTime.textContent = this.formatTime(new Date());

        this.messagesContainer.appendChild(template);
        this.scrollToBottom();
    }

    /**
     * Format a message for display (basic markdown support).
     *
     * @param {string} message - The raw message.
     * @returns {string} Formatted HTML.
     */
    formatMessage(message) {
        // Escape HTML first.
        let formatted = this.escapeHtml(message);

        // Convert markdown-style formatting.
        // Bold: **text** or __text__.
        formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        formatted = formatted.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // Italic: *text* or _text_.
        formatted = formatted.replace(/\*(.+?)\*/g, '<em>$1</em>');
        formatted = formatted.replace(/_(.+?)_/g, '<em>$1</em>');

        // Code: `code`.
        formatted = formatted.replace(/`(.+?)`/g, '<code>$1</code>');

        // Line breaks.
        formatted = formatted.replace(/\n/g, '<br>');

        // Numbered lists.
        formatted = formatted.replace(/^(\d+)\.\s+(.+)$/gm, '<li>$2</li>');

        // Bullet points.
        formatted = formatted.replace(/^[-•]\s+(.+)$/gm, '<li>$1</li>');

        return formatted;
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} text - The text to escape.
     * @returns {string} Escaped text.
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Format a timestamp for display.
     *
     * @param {Date} date - The date to format.
     * @returns {string} Formatted time string.
     */
    formatTime(date) {
        return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    /**
     * Show the typing indicator.
     */
    showTypingIndicator() {
        this.typingIndicator.style.display = 'block';
        this.scrollToBottom();
    }

    /**
     * Hide the typing indicator.
     */
    hideTypingIndicator() {
        this.typingIndicator.style.display = 'none';
    }

    /**
     * Show an error message.
     *
     * @param {string} message - The error message.
     */
    showError(message) {
        const errorMessage = this.errorContainer.querySelector('.error-message');
        errorMessage.textContent = message;
        this.errorContainer.style.display = 'block';
        this.scrollToBottom();
    }

    /**
     * Hide the error message.
     */
    hideError() {
        this.errorContainer.style.display = 'none';
    }

    /**
     * Retry the last failed message.
     */
    async retryLastMessage() {
        if (this.retryMessage && !this.isProcessing) {
            await this.sendMessage(this.retryMessage);
        }
    }

    /**
     * Start a new chat session.
     */
    async startNewSession() {
        // Generate new session ID.
        this.sessionId = this.generateSessionId();

        // Clear messages except welcome.
        const messages = this.messagesContainer.querySelectorAll('.message-wrapper');
        messages.forEach((msg, index) => {
            if (index > 0) {
                msg.remove();
            }
        });

        // Hide any errors.
        this.hideError();
        this.hideTypingIndicator();

        // Reset state.
        this.isProcessing = false;
        this.retryMessage = null;
        this.updateUIState();
        this.focusInput();

        // Notify user.
        Notification.addNotification({
            message: await getString('chat:sessionexpired', 'local_student_support'),
            type: 'info'
        });

        Log.debug('[StudentSupport] New session started:', this.sessionId);
    }

    /**
     * Generate a new session ID.
     *
     * @returns {string} New session ID.
     */
    generateSessionId() {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substring(2, 10);
        return `${this.userId}_${this.courseId}_${timestamp}_${random}`;
    }

    /**
     * Update UI state based on processing status.
     */
    updateUIState() {
        this.sendButton.disabled = this.isProcessing || !this.chatInput.value.trim();
        this.chatInput.disabled = this.isProcessing;

        if (this.isProcessing) {
            this.sendButton.classList.add('processing');
        } else {
            this.sendButton.classList.remove('processing');
        }
    }

    /**
     * Scroll the messages container to the bottom.
     */
    scrollToBottom() {
        requestAnimationFrame(() => {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        });
    }

    /**
     * Focus the input field.
     */
    focusInput() {
        this.chatInput.focus();
    }

    /**
     * Announce a message for screen readers.
     *
     * @param {string} stringKey - The language string key.
     */
    async announceMessage(stringKey) {
        const message = await getString(`chat:${stringKey}`, 'local_student_support');
        const announcement = document.createElement('div');
        announcement.setAttribute('role', 'status');
        announcement.setAttribute('aria-live', 'polite');
        announcement.className = 'sr-only';
        announcement.textContent = message;
        document.body.appendChild(announcement);

        setTimeout(() => announcement.remove(), 1000);
    }
}

/**
 * Initialize the chat module.
 *
 * @param {number} courseId - The course ID.
 * @param {string} sessionId - The session ID.
 * @param {number} userId - The user ID.
 */
export const init = (courseId, sessionId, userId) => {
    // Wait for DOM to be ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            new Chat(courseId, sessionId, userId);
        });
    } else {
        new Chat(courseId, sessionId, userId);
    }
};
