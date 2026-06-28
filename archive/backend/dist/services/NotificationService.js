"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.NotificationService = void 0;
const errors_js_1 = require("../types/errors.js");
class NotificationService {
    channels = new Map();
    constructor() {
        this.initializeChannels();
    }
    initializeChannels() {
        this.channels.set('console', {
            name: 'Console',
            enabled: true,
            config: {}
        });
        this.channels.set('email', {
            name: 'Email',
            enabled: process.env.SMTP_HOST ? true : false,
            config: {
                host: process.env.SMTP_HOST,
                port: process.env.SMTP_PORT || 587,
                secure: process.env.SMTP_SECURE === 'true',
                auth: {
                    user: process.env.SMTP_USER,
                    pass: process.env.SMTP_PASS
                },
                recipients: (process.env.ALERT_EMAIL_RECIPIENTS || '').split(',').filter(Boolean)
            }
        });
        this.channels.set('slack', {
            name: 'Slack',
            enabled: process.env.SLACK_WEBHOOK_URL ? true : false,
            config: {
                webhookUrl: process.env.SLACK_WEBHOOK_URL,
                channel: process.env.SLACK_CHANNEL || '#alerts'
            }
        });
        this.channels.set('line', {
            name: 'LINE',
            enabled: process.env.LINE_NOTIFY_TOKEN ? true : false,
            config: {
                token: process.env.LINE_NOTIFY_TOKEN
            }
        });
    }
    async sendAlert(notification) {
        const promises = [];
        for (const [channelName, channel] of this.channels.entries()) {
            if (channel.enabled && this.shouldSendToChannel(notification.severity, channelName)) {
                promises.push(this.sendToChannel(channelName, notification));
            }
        }
        try {
            await Promise.allSettled(promises);
        }
        catch (error) {
            console.error('Failed to send notifications:', error);
        }
    }
    shouldSendToChannel(severity, channelName) {
        switch (channelName) {
            case 'console':
                return true;
            case 'email':
                return severity === errors_js_1.ErrorSeverity.HIGH || severity === errors_js_1.ErrorSeverity.CRITICAL;
            case 'slack':
                return severity === errors_js_1.ErrorSeverity.MEDIUM || severity === errors_js_1.ErrorSeverity.HIGH || severity === errors_js_1.ErrorSeverity.CRITICAL;
            case 'line':
                return severity === errors_js_1.ErrorSeverity.CRITICAL;
            default:
                return false;
        }
    }
    async sendToChannel(channelName, notification) {
        const channel = this.channels.get(channelName);
        if (!channel)
            return;
        try {
            switch (channelName) {
                case 'console':
                    await this.sendToConsole(notification);
                    break;
                case 'email':
                    await this.sendToEmail(notification, channel.config);
                    break;
                case 'slack':
                    await this.sendToSlack(notification, channel.config);
                    break;
                case 'line':
                    await this.sendToLine(notification, channel.config);
                    break;
            }
        }
        catch (error) {
            console.error(`Failed to send notification to ${channelName}:`, error);
        }
    }
    async sendToConsole(notification) {
        const emoji = this.getSeverityEmoji(notification.severity);
        const timestamp = new Date().toISOString();
        console.log(`\n${emoji} [${notification.severity.toUpperCase()}] ${notification.type.toUpperCase()}`);
        console.log(`Time: ${timestamp}`);
        console.log(`Message: ${notification.message}`);
        if (notification.details) {
            console.log('Details:', JSON.stringify(notification.details, null, 2));
        }
        console.log('---');
    }
    async sendToEmail(notification, config) {
        if (!config.recipients || config.recipients.length === 0) {
            return;
        }
        const emailContent = {
            to: config.recipients,
            subject: `🚨 ${notification.severity.toUpperCase()} Alert: ${notification.type}`,
            html: `
        <h2>System Alert</h2>
        <p><strong>Severity:</strong> ${notification.severity.toUpperCase()}</p>
        <p><strong>Type:</strong> ${notification.type}</p>
        <p><strong>Message:</strong> ${notification.message}</p>
        <p><strong>Time:</strong> ${new Date().toISOString()}</p>
        ${notification.details ? `<p><strong>Details:</strong><br><pre>${JSON.stringify(notification.details, null, 2)}</pre></p>` : ''}
      `
        };
        console.log('Email notification (would be sent):', emailContent);
    }
    async sendToSlack(notification, config) {
        if (!config.webhookUrl)
            return;
        const emoji = this.getSeverityEmoji(notification.severity);
        const color = this.getSeverityColor(notification.severity);
        const payload = {
            channel: config.channel,
            username: 'Dashboard Monitor',
            icon_emoji: ':warning:',
            attachments: [{
                    color,
                    title: `${emoji} ${notification.severity.toUpperCase()} Alert`,
                    fields: [
                        {
                            title: 'Type',
                            value: notification.type,
                            short: true
                        },
                        {
                            title: 'Time',
                            value: new Date().toISOString(),
                            short: true
                        },
                        {
                            title: 'Message',
                            value: notification.message,
                            short: false
                        }
                    ],
                    footer: 'Dashboard Monitoring System'
                }]
        };
        if (notification.details) {
            payload.attachments[0].fields.push({
                title: 'Details',
                value: `\`\`\`${JSON.stringify(notification.details, null, 2)}\`\`\``,
                short: false
            });
        }
        console.log('Slack notification (would be sent):', payload);
    }
    async sendToLine(notification, config) {
        if (!config.token)
            return;
        const emoji = this.getSeverityEmoji(notification.severity);
        const message = `${emoji} ${notification.severity.toUpperCase()} Alert\n\n` +
            `Type: ${notification.type}\n` +
            `Message: ${notification.message}\n` +
            `Time: ${new Date().toLocaleString('th-TH', { timeZone: 'Asia/Bangkok' })}`;
        console.log('LINE notification (would be sent):', { message, token: config.token });
    }
    getSeverityEmoji(severity) {
        switch (severity) {
            case errors_js_1.ErrorSeverity.CRITICAL:
                return '🔥';
            case errors_js_1.ErrorSeverity.HIGH:
                return '🚨';
            case errors_js_1.ErrorSeverity.MEDIUM:
                return '⚠️';
            case errors_js_1.ErrorSeverity.LOW:
                return 'ℹ️';
            default:
                return '📢';
        }
    }
    getSeverityColor(severity) {
        switch (severity) {
            case errors_js_1.ErrorSeverity.CRITICAL:
                return '#FF0000';
            case errors_js_1.ErrorSeverity.HIGH:
                return '#FF8C00';
            case errors_js_1.ErrorSeverity.MEDIUM:
                return '#FFD700';
            case errors_js_1.ErrorSeverity.LOW:
                return '#00CED1';
            default:
                return '#808080';
        }
    }
    async testNotifications() {
        const results = {};
        for (const [channelName, channel] of this.channels.entries()) {
            if (channel.enabled) {
                try {
                    await this.sendToChannel(channelName, {
                        type: 'system_health',
                        severity: errors_js_1.ErrorSeverity.LOW,
                        message: 'Test notification - system is healthy',
                        details: {
                            test: true,
                            timestamp: new Date().toISOString()
                        }
                    });
                    results[channelName] = true;
                }
                catch (error) {
                    results[channelName] = false;
                    console.error(`Test failed for ${channelName}:`, error);
                }
            }
            else {
                results[channelName] = false;
            }
        }
        return results;
    }
    getChannelStatus() {
        const status = {};
        for (const [channelName, channel] of this.channels.entries()) {
            status[channelName] = {
                enabled: channel.enabled,
                configured: this.isChannelConfigured(channelName, channel.config)
            };
        }
        return status;
    }
    isChannelConfigured(channelName, config) {
        switch (channelName) {
            case 'console':
                return true;
            case 'email':
                return !!(config.host && config.auth?.user && config.recipients?.length > 0);
            case 'slack':
                return !!config.webhookUrl;
            case 'line':
                return !!config.token;
            default:
                return false;
        }
    }
}
exports.NotificationService = NotificationService;
//# sourceMappingURL=NotificationService.js.map