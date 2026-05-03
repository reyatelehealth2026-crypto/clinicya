<?php
/**
 * Executive Overview Section
 * CRM Dashboard Advanced - Main Dashboard Landing
 */
?>

<!-- Metric Cards Row 1 -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
    <!-- Total Customers -->
    <div class="metric-card">
        <div class="metric-label">Total Customers</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-total-customers">--</div>
            <div class="metric-change positive" id="change-total-customers">+0%</div>
        </div>
        <canvas id="spark-customers" class="sparkline mt-2" width="60" height="20"></canvas>
    </div>
    
    <!-- Active Deals -->
    <div class="metric-card">
        <div class="metric-label">Pipeline Value</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-pipeline-value">฿--</div>
            <div class="metric-change positive" id="change-pipeline">+0%</div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
            <span id="metric-active-deals">--</span> active deals
        </div>
    </div>
    
    <!-- Monthly Revenue -->
    <div class="metric-card">
        <div class="metric-label">Monthly Revenue</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-revenue">฿--</div>
            <div class="metric-change positive" id="change-revenue">+0%</div>
        </div>
        <canvas id="spark-revenue" class="sparkline mt-2" width="60" height="20"></canvas>
    </div>
    
    <!-- Open Tickets -->
    <div class="metric-card">
        <div class="metric-label">Open Tickets</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-tickets">--</div>
            <div class="badge badge-red" id="metric-urgent-tickets">-- urgent</div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
            <span id="metric-sla-breach" class="text-red-600 font-medium">--</span> SLA breach
        </div>
    </div>
</div>

<!-- Metric Cards Row 2 -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <!-- Conversion Rate -->
    <div class="metric-card">
        <div class="metric-label">Conversion Rate</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-conversion">--%</div>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
            <div class="bg-blue-600 h-1.5 rounded-full" style="width: 0%" id="bar-conversion"></div>
        </div>
    </div>
    
    <!-- Avg Deal Size -->
    <div class="metric-card">
        <div class="metric-label">Avg Deal Size</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-avg-deal">฿--</div>
        </div>
        <div class="text-xs text-gray-500 mt-1">Last 30 days</div>
    </div>
    
    <!-- Active Campaigns -->
    <div class="metric-card">
        <div class="metric-label">Active Campaigns</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-campaigns">--</div>
        </div>
        <div class="flex items-center gap-1 mt-1">
            <span class="badge badge-green text-xs" id="metric-campaign-active">--</span>
            <span class="badge badge-gray text-xs">running</span>
        </div>
    </div>
    
    <!-- CSAT Score -->
    <div class="metric-card">
        <div class="metric-label">CSAT Score</div>
        <div class="flex items-end justify-between">
            <div class="metric-value" id="metric-csat">--</div>
            <div class="text-sm text-gray-500">/5.0</div>
        </div>
        <div class="flex items-center gap-0.5 mt-2" id="stars-csat">
            <i class="bi bi-star-fill text-yellow-400 text-xs"></i>
            <i class="bi bi-star-fill text-yellow-400 text-xs"></i>
            <i class="bi bi-star-fill text-yellow-400 text-xs"></i>
            <i class="bi bi-star-fill text-yellow-400 text-xs"></i>
            <i class="bi bi-star text-gray-300 text-xs"></i>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    
    <!-- Left Column (2/3) -->
    <div class="lg:col-span-2 space-y-4">
        
        <!-- Alerts Section -->
        <div id="alerts-container" class="hidden">
            <!-- Alerts loaded dynamically -->
        </div>
        
        <!-- Revenue Chart -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-graph-up text-blue-600"></i>
                    <span>Revenue Trend</span>
                </div>
                <div class="flex gap-2">
                    <button class="btn btn-sm btn-secondary" onclick="setRevenuePeriod('7d')">7D</button>
                    <button class="btn btn-sm btn-secondary" onclick="setRevenuePeriod('30d')">30D</button>
                    <button class="btn btn-sm btn-secondary" onclick="setRevenuePeriod('90d')">90D</button>
                </div>
            </div>
            <div class="section-body">
                <canvas id="revenueChart" height="250"></canvas>
            </div>
        </div>
        
        <!-- Pipeline Distribution -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="section-card">
                <div class="section-header">
                    <div class="flex items-center gap-2">
                        <i class="bi bi-kanban text-purple-600"></i>
                        <span>Pipeline Distribution</span>
                    </div>
                </div>
                <div class="section-body">
                    <canvas id="pipelineChart" height="200"></canvas>
                </div>
            </div>
            
            <div class="section-card">
                <div class="section-header">
                    <div class="flex items-center gap-2">
                        <i class="bi bi-pie-chart text-green-600"></i>
                        <span>Revenue by Source</span>
                    </div>
                </div>
                <div class="section-body">
                    <canvas id="sourceChart" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Recent Deals Table -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-briefcase text-blue-600"></i>
                    <span>Recent Deals</span>
                </div>
                <a href="#" class="text-xs text-blue-600 hover:underline" onclick="showSection('deals'); return false;">View All</a>
            </div>
            <div class="section-body p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Deal</th>
                            <th>Customer</th>
                            <th>Value</th>
                            <th>Stage</th>
                            <th>Probability</th>
                            <th>Expected Close</th>
                        </tr>
                    </thead>
                    <tbody id="recent-deals-table">
                        <tr>
                            <td colspan="6" class="text-center py-4 text-gray-400">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Right Column (1/3) -->
    <div class="space-y-4">
        
        <!-- Quick Actions -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-lightning-charge text-yellow-600"></i>
                    <span>Quick Actions</span>
                </div>
            </div>
            <div class="section-body">
                <div class="grid grid-cols-2 gap-2">
                    <button class="btn btn-primary btn-sm" onclick="openAddDealModal()">
                        <i class="bi bi-plus-lg"></i> Add Deal
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="openCreateTicketModal()">
                        <i class="bi bi-ticket"></i> New Ticket
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="showSection('customers')">
                        <i class="bi bi-person-plus"></i> Add Customer
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="showSection('marketing')">
                        <i class="bi bi-send"></i> Campaign
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Activity Feed -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-activity text-blue-600"></i>
                    <span>Activity Feed</span>
                </div>
            </div>
            <div class="section-body">
                <div id="activity-feed" class="max-h-80 overflow-y-auto">
                    <div class="text-center py-4 text-gray-400">Loading activities...</div>
                </div>
            </div>
        </div>
        
        <!-- Top Performers -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-trophy text-yellow-600"></i>
                    <span>Top Performers</span>
                </div>
            </div>
            <div class="section-body">
                <div id="top-performers" class="space-y-3">
                    <div class="text-center py-4 text-gray-400">Loading...</div>
                </div>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="section-card">
            <div class="section-header">
                <div class="flex items-center gap-2">
                    <i class="bi bi-check-circle-fill text-green-600"></i>
                    <span>System Status</span>
                </div>
            </div>
            <div class="section-body">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">API Status</span>
                        <span class="badge badge-green">Operational</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Database</span>
                        <span class="badge badge-green">Connected</span>
                    </div>
                    <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Odoo Sync</span>
                        <span class="badge badge-green">Active</span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between">
                        <span class="text-gray-500">LINE OA</span>
                        <span class="badge badge-green">Online</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let revenueChart, pipelineChart, sourceChart;

function loadExecutiveOverview() {
    crmApi('overview').then(result => {
        if (result.success) {
            renderOverview(result.data);
        }
    });
    
    // Load recent deals
    crmApi('deals', { limit: 5 }).then(result => {
        if (result.success) {
            renderRecentDeals(result.data.deals);
        }
    });
    
    // Load activities
    crmApi('activities', { limit: 10 }).then(result => {
        if (result.success) {
            renderActivityFeed(result.data);
        }
    });
    
    // Load revenue analytics
    crmApi('analytics_revenue', { period: '30d' }).then(result => {
        if (result.success) {
            renderRevenueChart(result.data);
        }
    });
    
    // Load pipeline data
    crmApi('pipeline').then(result => {
        if (result.success) {
            renderPipelineChart(result.data);
            renderTopPerformers(result.data);
        }
    });
}

function renderOverview(data) {
    const metrics = data.metrics;
    
    // Row 1 Metrics
    document.getElementById('metric-total-customers').textContent = metrics.total_customers?.value?.toLocaleString() || '0';
    document.getElementById('change-total-customers').textContent = 
        (metrics.total_customers?.change >= 0 ? '+' : '') + (metrics.total_customers?.change || 0) + '%';
    document.getElementById('change-total-customers').className = 'metric-change ' + 
        (metrics.total_customers?.change >= 0 ? 'positive' : 'negative');
    
    document.getElementById('metric-pipeline-value').textContent = '฿' + (metrics.active_deals?.pipeline_value?.toLocaleString() || '0');
    document.getElementById('metric-active-deals').textContent = metrics.active_deals?.value || '0';
    document.getElementById('change-pipeline').textContent = 
        (metrics.active_deals?.change >= 0 ? '+' : '') + (metrics.active_deals?.change || 0) + '%';
    
    document.getElementById('metric-revenue').textContent = '฿' + (metrics.monthly_revenue?.value?.toLocaleString() || '0');
    document.getElementById('change-revenue').textContent = 
        (metrics.monthly_revenue?.change >= 0 ? '+' : '') + (metrics.monthly_revenue?.change || 0) + '%';
    
    document.getElementById('metric-tickets').textContent = metrics.open_tickets?.value || '0';
    document.getElementById('metric-urgent-tickets').textContent = (metrics.open_tickets?.urgent || '0') + ' urgent';
    document.getElementById('metric-sla-breach').textContent = '0'; // Would come from ticket stats
    
    // Row 2 Metrics
    document.getElementById('metric-conversion').textContent = (metrics.conversion_rate?.value || '0') + '%';
    document.getElementById('bar-conversion').style.width = (metrics.conversion_rate?.value || 0) + '%';
    
    document.getElementById('metric-avg-deal').textContent = '฿' + (metrics.avg_deal_size?.value?.toLocaleString() || '0');
    
    document.getElementById('metric-campaigns').textContent = metrics.active_campaigns?.value || '0';
    document.getElementById('metric-campaign-active').textContent = metrics.active_campaigns?.value || '0';
    
    document.getElementById('metric-csat').textContent = metrics.satisfaction?.value || '0';
    
    // Sparklines
    if (data.charts?.revenue_trend) {
        createSparkline('spark-revenue', data.charts.revenue_trend, '#10b981');
    }
    if (data.charts?.pipeline_distribution) {
        createSparkline('spark-customers', data.charts.pipeline_distribution, '#3b82f6');
    }
    
    // Alerts
    if (data.alerts && data.alerts.length > 0) {
        renderAlerts(data.alerts);
    }
}

function renderAlerts(alerts) {
    const container = document.getElementById('alerts-container');
    container.classList.remove('hidden');
    
    const alertColors = {
        'danger': 'bg-red-50 border-red-200 text-red-800',
        'warning': 'bg-yellow-50 border-yellow-200 text-yellow-800',
        'info': 'bg-blue-50 border-blue-200 text-blue-800'
    };
    
    container.innerHTML = alerts.map(alert => `
        <div class="${alertColors[alert.type] || alertColors.info} border rounded-lg p-3 mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="bi bi-exclamation-circle"></i>
                <span class="font-medium">${alert.message}</span>
            </div>
            <a href="${alert.link}" class="text-sm underline">View</a>
        </div>
    `).join('');
}

function renderRecentDeals(deals) {
    const tbody = document.getElementById('recent-deals-table');
    
    if (!deals || deals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-gray-400">No deals found</td></tr>';
        return;
    }
    
    const stageBadges = {
        'lead': '<span class="badge badge-gray">Lead</span>',
        'qualified': '<span class="badge badge-blue">Qualified</span>',
        'proposal': '<span class="badge badge-purple">Proposal</span>',
        'negotiation': '<span class="badge badge-yellow">Negotiation</span>',
        'closed_won': '<span class="badge badge-green">Won</span>',
        'closed_lost': '<span class="badge badge-red">Lost</span>'
    };
    
    tbody.innerHTML = deals.map(deal => `
        <tr class="cursor-pointer" onclick="openDealDetail(${deal.id})">
            <td class="font-medium">${deal.title}</td>
            <td>
                <div class="flex items-center gap-2">
                    <img src="${deal.customer_avatar || 'https://via.placeholder.com/24'}" class="w-6 h-6 rounded-full">
                    <span>${deal.customer_name || 'Unknown'}</span>
                </div>
            </td>
            <td class="font-mono">฿${parseFloat(deal.value || 0).toLocaleString()}</td>
            <td>${stageBadges[deal.stage] || deal.stage}</td>
            <td>${deal.probability || 0}%</td>
            <td class="text-gray-500">${deal.expected_close || '-'}</td>
        </tr>
    `).join('');
}

function renderActivityFeed(activities) {
    const container = document.getElementById('activity-feed');
    
    if (!activities || activities.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-gray-400">No recent activity</div>';
        return;
    }
    
    const icons = {
        'deal': 'bi-briefcase',
        'ticket': 'bi-ticket',
        'campaign': 'bi-send',
        'customer': 'bi-person'
    };
    
    const colors = {
        'deal': 'bg-blue-100 text-blue-600',
        'ticket': 'bg-yellow-100 text-yellow-600',
        'campaign': 'bg-purple-100 text-purple-600',
        'customer': 'bg-green-100 text-green-600'
    };
    
    container.innerHTML = activities.map(activity => `
        <div class="activity-item">
            <div class="activity-icon ${colors[activity.type] || colors.customer}">
                <i class="bi ${icons[activity.type] || icons.customer}"></i>
            </div>
            <div class="activity-content">
                <p class="text-sm">
                    <span class="font-medium">${activity.customer_name || 'Unknown'}</span>
                    ${getActivityText(activity)}
                </p>
                <p class="text-xs text-gray-400">${formatTimeAgo(activity.created_at)}</p>
            </div>
        </div>
    `).join('');
}

function getActivityText(activity) {
    switch(activity.type) {
        case 'deal':
            return `created deal "${activity.title}" worth ฿${parseFloat(activity.value || 0).toLocaleString()}`;
        case 'ticket':
            return `opened ticket "${activity.title}" (${activity.stage})`;
        default:
            return 'had activity';
    }
}

function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function renderRevenueChart(data) {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    const labels = data.daily?.map(d => d.date) || [];
    const values = data.daily?.map(d => d.revenue) || [];
    
    revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: values,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 10 },
                        callback: value => '฿' + (value / 1000).toFixed(0) + 'k'
                    }
                }
            }
        }
    });
}

function renderPipelineChart(data) {
    const ctx = document.getElementById('pipelineChart').getContext('2d');
    
    if (pipelineChart) {
        pipelineChart.destroy();
    }
    
    const stages = data.stages || [];
    const labels = stages.map(s => s.name);
    const values = stages.map(s => s.value);
    const colors = ['#94a3b8', '#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'];
    
    pipelineChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { font: { size: 10 }, boxWidth: 12 }
                }
            },
            cutout: '65%'
        }
    });
}

function renderTopPerformers(pipelineData) {
    const container = document.getElementById('top-performers');
    
    // Mock data for now - would be replaced with actual analytics
    const performers = [
        { name: 'Sales Team', deals: 12, revenue: 450000, winRate: 35 },
        { name: 'Marketing', campaigns: 5, leads: 150, conversion: 12 },
    ];
    
    container.innerHTML = performers.map(p => `
        <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="bi bi-person text-blue-600 text-sm"></i>
                </div>
                <div>
                    <p class="font-medium text-sm">${p.name}</p>
                    <p class="text-xs text-gray-500">
                        ${p.deals ? p.deals + ' deals' : p.campaigns + ' campaigns'}
                    </p>
                </div>
            </div>
            <div class="text-right">
                <p class="font-mono font-medium text-sm">฿${(p.revenue || 0).toLocaleString()}</p>
                <p class="text-xs text-gray-500">${p.winRate || p.conversion}% rate</p>
            </div>
        </div>
    `).join('');
}

function setRevenuePeriod(period) {
    crmApi('analytics_revenue', { period }).then(result => {
        if (result.success) {
            renderRevenueChart(result.data);
        }
    });
}

function openDealDetail(dealId) {
    // Would open deal detail modal
    console.log('Open deal:', dealId);
}

// Load on page ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('section-overview').classList.contains('active')) {
        loadExecutiveOverview();
    }
});
</script>
