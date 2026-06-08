import { ErrorSeverity } from '../types/errors.js';
interface AlertNotification {
    type: 'error_threshold' | 'system_health' | 'performance_degradation';
    severity: ErrorSeverity;
    message: string;
    details?: Record<string, any>;
}
export declare class NotificationService {
    private channels;
    constructor();
    private initializeChannels;
    sendAlert(notification: AlertNotification): Promise<void>;
    private shouldSendToChannel;
    private sendToChannel;
    private sendToConsole;
    private sendToEmail;
    private sendToSlack;
    private sendToLine;
    private getSeverityEmoji;
    private getSeverityColor;
    testNotifications(): Promise<Record<string, boolean>>;
    getChannelStatus(): Record<string, {
        enabled: boolean;
        configured: boolean;
    }>;
    private isChannelConfigured;
}
export {};
//# sourceMappingURL=NotificationService.d.ts.map