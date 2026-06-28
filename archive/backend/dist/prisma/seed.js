#!/usr/bin/env tsx
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const client_1 = require("@prisma/client");
const bcryptjs_1 = __importDefault(require("bcryptjs"));
const prisma = new client_1.PrismaClient();
async function main() {
    console.log('🌱 Starting database seeding...');
    const lineAccount1 = await prisma.lineAccount.upsert({
        where: { channelSecret: 'sample_channel_secret_1' },
        update: {},
        create: {
            name: 'Main Pharmacy Bot',
            channelId: 'sample_channel_id_1',
            channelSecret: 'sample_channel_secret_1',
            channelAccessToken: 'sample_access_token_1',
            basicId: '@main-pharmacy',
            isDefault: true,
            botMode: 'SHOP',
            settings: {
                welcomeEnabled: true,
                autoReplyEnabled: true,
                businessHours: {
                    start: '09:00',
                    end: '18:00',
                    timezone: 'Asia/Bangkok'
                }
            },
            welcomeMessage: 'ยินดีต้อนรับสู่ร้านยาออนไลน์ของเรา! 🏥',
        }
    });
    const lineAccount2 = await prisma.lineAccount.upsert({
        where: { channelSecret: 'sample_channel_secret_2' },
        update: {},
        create: {
            name: 'Support Bot',
            channelId: 'sample_channel_id_2',
            channelSecret: 'sample_channel_secret_2',
            channelAccessToken: 'sample_access_token_2',
            basicId: '@support-pharmacy',
            isDefault: false,
            botMode: 'GENERAL',
            settings: {
                welcomeEnabled: true,
                autoReplyEnabled: true,
            },
            welcomeMessage: 'สวัสดีครับ! มีอะไรให้ช่วยเหลือไหมครับ? 😊',
        }
    });
    console.log(`✅ Created LINE accounts: ${lineAccount1.name}, ${lineAccount2.name}`);
    const hashedPassword = await bcryptjs_1.default.hash('password123', 10);
    const superAdmin = await prisma.user.upsert({
        where: { email: 'admin@pharmacy.com' },
        update: {},
        create: {
            username: 'superadmin',
            email: 'admin@pharmacy.com',
            passwordHash: hashedPassword,
            role: client_1.UserRole.SUPER_ADMIN,
            lineAccountId: lineAccount1.id.toString(),
            permissions: {
                canManageUsers: true,
                canManageSettings: true,
                canViewReports: true,
                canManageOrders: true,
                canProcessPayments: true,
            },
        }
    });
    const pharmacist = await prisma.user.upsert({
        where: { email: 'pharmacist@pharmacy.com' },
        update: {},
        create: {
            username: 'pharmacist1',
            email: 'pharmacist@pharmacy.com',
            passwordHash: hashedPassword,
            role: client_1.UserRole.PHARMACIST,
            lineAccountId: lineAccount1.id.toString(),
            permissions: {
                canViewReports: true,
                canManageOrders: true,
                canProcessPayments: true,
            },
        }
    });
    const staff = await prisma.user.upsert({
        where: { email: 'staff@pharmacy.com' },
        update: {},
        create: {
            username: 'staff1',
            email: 'staff@pharmacy.com',
            passwordHash: hashedPassword,
            role: client_1.UserRole.STAFF,
            lineAccountId: lineAccount1.id.toString(),
            permissions: {
                canViewReports: false,
                canManageOrders: true,
                canProcessPayments: false,
            },
        }
    });
    console.log(`✅ Created users: ${superAdmin.username}, ${pharmacist.username}, ${staff.username}`);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const sampleMetrics = [
        {
            lineAccountId: lineAccount1.id.toString(),
            metricType: client_1.MetricType.ORDERS,
            dateKey: today,
            data: {
                totalOrders: 45,
                totalAmount: 125000,
                averageOrderValue: 2777.78,
                completedOrders: 38,
                pendingOrders: 7,
                topProducts: [
                    { name: 'Paracetamol 500mg', quantity: 120 },
                    { name: 'Vitamin C 1000mg', quantity: 85 },
                    { name: 'Omeprazole 20mg', quantity: 65 }
                ]
            },
            expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
        },
        {
            lineAccountId: lineAccount1.id.toString(),
            metricType: client_1.MetricType.PAYMENTS,
            dateKey: today,
            data: {
                pendingSlips: 12,
                processedToday: 38,
                matchingRate: 0.92,
                totalAmount: 118500,
                averageProcessingTime: 145,
            },
            expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
        },
        {
            lineAccountId: lineAccount1.id.toString(),
            metricType: client_1.MetricType.WEBHOOKS,
            dateKey: today,
            data: {
                totalWebhooks: 156,
                successfulWebhooks: 148,
                failedWebhooks: 8,
                successRate: 0.949,
                averageProcessingTime: 89,
                errorTypes: {
                    'timeout': 3,
                    'invalid_payload': 2,
                    'service_unavailable': 3
                }
            },
            expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
        },
        {
            lineAccountId: lineAccount1.id.toString(),
            metricType: client_1.MetricType.CUSTOMERS,
            dateKey: today,
            data: {
                totalCustomers: 1247,
                newCustomers: 23,
                activeCustomers: 189,
                returningCustomers: 166,
                customerRetentionRate: 0.878,
                averageOrdersPerCustomer: 3.2
            },
            expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
        }
    ];
    for (const metric of sampleMetrics) {
        await prisma.dashboardMetricsCache.upsert({
            where: {
                lineAccountId_metricType_dateKey: {
                    lineAccountId: metric.lineAccountId,
                    metricType: metric.metricType,
                    dateKey: metric.dateKey,
                }
            },
            update: {
                data: metric.data,
                expiresAt: metric.expiresAt,
            },
            create: metric,
        });
    }
    console.log('✅ Created sample dashboard metrics cache');
    const apiCacheEntries = [
        {
            cacheKey: 'odoo_orders_summary_today',
            data: {
                totalOrders: 45,
                totalAmount: 125000,
                lastUpdated: new Date().toISOString(),
            },
            expiresAt: new Date(Date.now() + 30 * 60 * 1000),
        },
        {
            cacheKey: 'customer_stats_weekly',
            data: {
                newCustomers: 89,
                totalCustomers: 1247,
                weeklyGrowth: 0.076,
                lastUpdated: new Date().toISOString(),
            },
            expiresAt: new Date(Date.now() + 60 * 60 * 1000),
        }
    ];
    for (const cacheEntry of apiCacheEntries) {
        await prisma.apiCache.upsert({
            where: { cacheKey: cacheEntry.cacheKey },
            update: {
                data: cacheEntry.data,
                expiresAt: cacheEntry.expiresAt,
            },
            create: cacheEntry,
        });
    }
    console.log('✅ Created sample API cache entries');
    const sampleFollowers = [
        {
            lineAccountId: lineAccount1.id,
            lineUserId: 'U1234567890abcdef',
            displayName: 'สมชาย ใจดี',
            isFollowing: true,
            totalMessages: 15,
            lastInteractionAt: new Date(Date.now() - 2 * 60 * 60 * 1000),
        },
        {
            lineAccountId: lineAccount1.id,
            lineUserId: 'U2345678901bcdefg',
            displayName: 'สมหญิง รักสุขภาพ',
            isFollowing: true,
            totalMessages: 8,
            lastInteractionAt: new Date(Date.now() - 5 * 60 * 60 * 1000),
        },
        {
            lineAccountId: lineAccount1.id,
            lineUserId: 'U3456789012cdefgh',
            displayName: 'นายแพทย์ สมศักดิ์',
            isFollowing: false,
            unfollowedAt: new Date(Date.now() - 24 * 60 * 60 * 1000),
            totalMessages: 3,
        }
    ];
    for (const follower of sampleFollowers) {
        await prisma.accountFollower.upsert({
            where: {
                lineAccountId_lineUserId: {
                    lineAccountId: follower.lineAccountId,
                    lineUserId: follower.lineUserId,
                }
            },
            update: follower,
            create: follower,
        });
    }
    console.log('✅ Created sample account followers');
    console.log('🎉 Database seeding completed successfully!');
    console.log('\n📋 Summary:');
    console.log(`   • LINE Accounts: 2`);
    console.log(`   • Users: 3 (1 Super Admin, 1 Pharmacist, 1 Staff)`);
    console.log(`   • Dashboard Metrics: 4 cache entries`);
    console.log(`   • API Cache: 2 entries`);
    console.log(`   • Account Followers: 3`);
    console.log('\n🔐 Default login credentials:');
    console.log(`   Super Admin: admin@pharmacy.com / password123`);
    console.log(`   Pharmacist: pharmacist@pharmacy.com / password123`);
    console.log(`   Staff: staff@pharmacy.com / password123`);
}
main()
    .catch((e) => {
    console.error('❌ Seeding failed:', e);
    process.exit(1);
})
    .finally(async () => {
    await prisma.$disconnect();
});
//# sourceMappingURL=seed.js.map