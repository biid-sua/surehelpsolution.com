// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Sidebar
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });
    }

    // Toggle Password Visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.querySelector('i').classList.toggle('fa-eye');
            this.querySelector('i').classList.toggle('fa-eye-slash');
        });
    }

    // Login Form Validation
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            // Add your authentication logic here
            if (username === 'admin' && password === 'admin') {
                window.location.href = 'dashboard.html';
            } else {
                alert('Invalid credentials. Please try again.');
            }
        });
    }

    // Initialize DataTables if it exists
    if (typeof $.fn.DataTable !== 'undefined' && document.getElementById('usersTable')) {
        $('#usersTable').DataTable({
            pageLength: 10,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search users..."
            }
        });
    }

    // User Management Page Functions
    function initializeUserManagement() {
        const roleFilter = document.getElementById('roleFilter');
        const statusFilter = document.getElementById('statusFilter');
        const resetFilters = document.getElementById('resetFilters');

        if (roleFilter && statusFilter && resetFilters) {
            // Apply filters
            function applyFilters() {
                const role = roleFilter.value;
                const status = statusFilter.value;
                const table = $('#usersTable').DataTable();
                
                // Custom filtering function
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const rowRole = data[3]; // Role column
                    const rowStatus = data[4]; // Status column
                    
                    if ((role === "" || rowRole.includes(role)) &&
                        (status === "" || rowStatus.includes(status))) {
                        return true;
                    }
                    return false;
                });
                
                table.draw();
                $.fn.dataTable.ext.search.pop(); // Remove the custom filter
            }

            roleFilter.addEventListener('change', applyFilters);
            statusFilter.addEventListener('change', applyFilters);
            
            // Reset filters
            resetFilters.addEventListener('click', function() {
                roleFilter.value = "";
                statusFilter.value = "";
                $('#usersTable').DataTable().search('').draw();
            });
        }
    }

    // Content Management Page Functions
    function initializeContentManagement() {
        const publishBtn = document.getElementById('publishBtn');
        const contentSections = document.querySelectorAll('.list-group-item');

        if (publishBtn) {
            publishBtn.addEventListener('click', function() {
                // Add your publish logic here
                alert('Changes published successfully!');
            });
        }

        if (contentSections) {
            contentSections.forEach(section => {
                section.addEventListener('click', function(e) {
                    e.preventDefault();
                    contentSections.forEach(s => s.classList.remove('active'));
                    this.classList.add('active');
                    // Add your section switching logic here
                });
            });
        }
    }

    // Initialize Tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Handle Mobile Navigation
    function handleMobileNav() {
        if (window.innerWidth <= 768) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
        }
    }

    // Initial check and event listener for window resize
    handleMobileNav();
    window.addEventListener('resize', handleMobileNav);

    // Add active class to current nav item based on URL
    function setActiveNavItem() {
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (currentPath.includes(href)) {
                navLinks.forEach(l => l.classList.remove('active'));
                link.classList.add('active');
            }
        });
    }

    // Initialize page-specific functions
    function initializePageFunctions() {
        setActiveNavItem();
        
        if (window.location.pathname.includes('users.html')) {
            initializeUserManagement();
        } else if (window.location.pathname.includes('content.html')) {
            initializeContentManagement();
        }
    }

    // Initialize dashboard features
    if (window.location.pathname.includes('dashboard')) {
        initializeModernDashboard();
        initializeDashboard();
        loadDashboardData();
        handleNotifications();
        setupPeriodFilters();
        setupExportFunctionality();
        initializeCharts();
        initializeProgressRings();
        setupModernInteractions();
        createParticleEffect();
    }

    // Add fade-in animation to cards
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.classList.add('fade-in');
    });

    // Handle form submissions
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    // Initialize all page-specific functions
    initializePageFunctions();

    // Handle notifications globally
    const notificationDropdown = document.getElementById('notificationsDropdown');
    if (notificationDropdown) {
        notificationDropdown.addEventListener('click', function() {
            // Add your notification handling logic here
            console.log('Fetching notifications...');
        });
    }

    // Handle table row clicks
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('click', function() {
            // Add your row click handling logic here
            console.log('Row clicked:', this.querySelector('td').textContent);
        });
    });

    // Handle quick actions
    const quickActionButtons = document.querySelectorAll('.card-body .btn');
    quickActionButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Add your quick action handling logic here
            console.log('Quick action clicked:', this.textContent.trim());
        });
    });
});

// Dashboard-specific functions
function initializeDashboard() {
    console.log('Initializing Call Center Dashboard...');
    
    // Initialize dummy data if not exists
    if (!localStorage.getItem('callCenterData')) {
        initializeDummyData();
    }
}

function initializeDummyData() {
    const dummyData = {
        today: {
            totalCalls: { value: 320, change: 12, trend: 'up' },
            serviceRequests: { value: 200, change: 8, trend: 'up' },
            conversionRate: { value: 78, change: -2, trend: 'down' },
            totalSchedules: { value: 45, change: 15, trend: 'up' },
            requestCallback: { value: 25, change: -5, trend: 'down' },
            avgResponseTime: { value: 2.3, change: -10, trend: 'up', unit: 'm' }
        },
        weekly: {
            totalCalls: { value: 2150, change: 18, trend: 'up' },
            serviceRequests: { value: 1240, change: 12, trend: 'up' },
            conversionRate: { value: 74, change: -3, trend: 'down' },
            totalSchedules: { value: 320, change: 22, trend: 'up' },
            requestCallback: { value: 150, change: -8, trend: 'down' },
            avgResponseTime: { value: 2.1, change: -15, trend: 'up', unit: 'm' }
        },
        monthly: {
            totalCalls: { value: 8640, change: 25, trend: 'up' },
            serviceRequests: { value: 4980, change: 20, trend: 'up' },
            conversionRate: { value: 80, change: 5, trend: 'up' },
            totalSchedules: { value: 1250, change: 28, trend: 'up' },
            requestCallback: { value: 590, change: -12, trend: 'down' },
            avgResponseTime: { value: 1.9, change: -20, trend: 'up', unit: 'm' }
        },
        chartData: {
            performance: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [
                    {
                        label: 'Total Calls',
                        data: [520, 610, 480, 540],
                        borderColor: 'rgb(13, 110, 253)',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Service Requests',
                        data: [310, 380, 290, 320],
                        borderColor: 'rgb(13, 202, 240)',
                        backgroundColor: 'rgba(13, 202, 240, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Conversion Rate (%)',
                        data: [75, 78, 72, 80],
                        borderColor: 'rgb(25, 135, 84)',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            distribution: {
                labels: ['Emergency Calls', 'Support Calls', 'Sales Calls', 'Appointments', 'Callbacks'],
                datasets: [{
                    data: [25, 35, 15, 20, 5],
                    backgroundColor: [
                        'rgb(220, 53, 69)',
                        'rgb(13, 202, 240)',
                        'rgb(25, 135, 84)',
                        'rgb(255, 193, 7)',
                        'rgb(108, 117, 125)'
                    ]
                }]
            }
        }
    };
    
    localStorage.setItem('callCenterData', JSON.stringify(dummyData));
}

function loadDashboardData(period = 'today') {
    const data = JSON.parse(localStorage.getItem('callCenterData'));
    const periodData = data[period];
    
    // Update KPI cards
    updateKPICard('totalCalls', periodData.totalCalls);
    updateKPICard('serviceRequests', periodData.serviceRequests);
    updateKPICard('conversionRate', periodData.conversionRate);
    updateKPICard('totalSchedules', periodData.totalSchedules);
    updateKPICard('requestCallback', periodData.requestCallback);
    updateKPICard('avgResponseTime', periodData.avgResponseTime);
    
    // Add animation to cards
    document.querySelectorAll('.kpi-card').forEach(card => {
        card.classList.add('fade-in');
    });
}

function updateKPICard(kpiType, data) {
    const valueElement = document.getElementById(`${kpiType}Value`);
    const changeElement = document.getElementById(`${kpiType}Change`);
    
    if (valueElement && changeElement) {
        // Update value with animation
        animateValue(valueElement, data.value, data.unit || '');
        
        // Update change indicator
        const changeIcon = changeElement.parentElement.querySelector('i');
        const changeText = `${data.change > 0 ? '+' : ''}${data.change}%`;
        changeElement.textContent = changeText;
        
        // Update colors based on trend
        if (data.trend === 'up') {
            changeElement.parentElement.className = 'text-success';
            changeIcon.className = 'fas fa-arrow-up me-1';
        } else {
            changeElement.parentElement.className = 'text-danger';
            changeIcon.className = 'fas fa-arrow-down me-1';
        }
        
        // Special case for response time (lower is better)
        if (kpiType === 'avgResponseTime' && data.trend === 'up') {
            changeElement.parentElement.className = 'text-success';
            changeIcon.className = 'fas fa-arrow-down me-1';
        }
    }
}

function animateValue(element, endValue, unit = '') {
    const startValue = parseInt(element.textContent.replace(/[^\d.-]/g, '')) || 0;
    const duration = 1000;
    const startTime = performance.now();
    
    function updateValue(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function
        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
        const currentValue = startValue + (endValue - startValue) * easeOutQuart;
        
        if (unit === '%') {
            element.textContent = Math.round(currentValue) + '%';
        } else if (unit === 'm') {
            element.textContent = currentValue.toFixed(1) + 'm';
        } else if (endValue >= 1000) {
            element.textContent = Math.round(currentValue).toLocaleString();
        } else {
            element.textContent = Math.round(currentValue);
        }
        
        if (progress < 1) {
            requestAnimationFrame(updateValue);
        }
    }
    
    requestAnimationFrame(updateValue);
}

function setupPeriodFilters() {
    const periodFilters = document.querySelectorAll('input[name="periodFilter"]');
    
    periodFilters.forEach(filter => {
        filter.addEventListener('change', function() {
            if (this.checked) {
                loadDashboardData(this.value);
                updateCharts(this.value);
                
                // Add visual feedback
                document.querySelectorAll('.kpi-card').forEach(card => {
                    card.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        card.style.transform = 'scale(1)';
                    }, 150);
                });
            }
        });
    });
}

function setupExportFunctionality() {
    const exportBtn = document.getElementById('exportBtn');
    
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            const selectedPeriod = document.querySelector('input[name="periodFilter"]:checked').value;
            exportDashboardData(selectedPeriod);
        });
    }
}

function exportDashboardData(period) {
    const data = JSON.parse(localStorage.getItem('callCenterData'));
    const periodData = data[period];
    
    // Create CSV content
    const csvContent = `Call Center Dashboard Report - ${period.toUpperCase()}\n\n` +
        `Metric,Value,Change (%),Trend\n` +
        `Total Calls,${periodData.totalCalls.value},${periodData.totalCalls.change},${periodData.totalCalls.trend}\n` +
        `Service Requests,${periodData.serviceRequests.value},${periodData.serviceRequests.change},${periodData.serviceRequests.trend}\n` +
        `Conversion Rate,${periodData.conversionRate.value}%,${periodData.conversionRate.change},${periodData.conversionRate.trend}\n` +
        `Total Schedules,${periodData.totalSchedules.value},${periodData.totalSchedules.change},${periodData.totalSchedules.trend}\n` +
        `Request Callback,${periodData.requestCallback.value},${periodData.requestCallback.change},${periodData.requestCallback.trend}\n` +
        `Avg Response Time,${periodData.avgResponseTime.value}${periodData.avgResponseTime.unit},${periodData.avgResponseTime.change},${periodData.avgResponseTime.trend}\n`;
    
    // Create and download file
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `call-center-dashboard-${period}-${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    // Show success message
    showNotification('Data exported successfully!', 'success');
}

function initializeCharts() {
    const data = JSON.parse(localStorage.getItem('callCenterData'));
    
    // Performance Chart
    const performanceCtx = document.getElementById('performanceChart');
    if (performanceCtx) {
        new Chart(performanceCtx, {
            type: 'line',
            data: data.chartData.performance,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                elements: {
                    line: {
                        tension: 0.4
                    }
                }
            }
        });
    }
    
    // Distribution Chart
    const distributionCtx = document.getElementById('distributionChart');
    if (distributionCtx) {
        new Chart(distributionCtx, {
            type: 'doughnut',
            data: data.chartData.distribution,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + '%';
                            }
                        }
                    }
                }
            }
        });
    }
}

function updateCharts(period) {
    // This would update charts based on period
    // For now, we'll keep the same chart data
    console.log(`Updating charts for period: ${period}`);
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}

function handleNotifications() {
    // Simulate real-time notifications
    setInterval(() => {
        const notifications = [
            'New emergency call received',
            'Service request completed',
            'Appointment scheduled',
            'Callback request pending'
        ];
        
        if (Math.random() > 0.95) { // 5% chance every interval
            const message = notifications[Math.floor(Math.random() * notifications.length)];
            showNotification(message, 'info');
            
            // Update notification badge
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                const currentCount = parseInt(badge.textContent) || 0;
                badge.textContent = currentCount + 1;
            }
        }
    }, 5000); // Check every 5 seconds
}

// Modern Dashboard Functions
function initializeModernDashboard() {
    // Initialize AOS animations
    AOS.init({
        duration: 800,
        easing: 'ease-out-cubic',
        once: true,
        offset: 100
    });
    
    console.log('Modern Dashboard Initialized with enhanced features');
}

function initializeProgressRings() {
    const progressRings = document.querySelectorAll('.progress-ring');
    
    progressRings.forEach(ring => {
        const progress = parseInt(ring.getAttribute('data-progress'));
        const circle = ring.querySelector('.progress-ring-circle');
        const radius = circle.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        
        circle.style.strokeDasharray = `${circumference} ${circumference}`;
        circle.style.strokeDashoffset = circumference;
        
        // Animate progress ring
        setTimeout(() => {
            const offset = circumference - progress / 100 * circumference;
            circle.style.strokeDashoffset = offset;
        }, 500);
    });
}

function setupModernInteractions() {
    // Enhanced hover effects for KPI cards
    const kpiCards = document.querySelectorAll('.modern-kpi-card');
    
    kpiCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-10px) scale(1.02)';
            this.style.boxShadow = '0 20px 40px rgba(0,0,0,0.1)';
            
            // Animate progress ring on hover
            const progressRing = this.querySelector('.progress-ring-circle');
            if (progressRing) {
                progressRing.style.filter = 'brightness(1.2)';
            }
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
            this.style.boxShadow = '0 10px 30px rgba(0,0,0,0.08)';
            
            const progressRing = this.querySelector('.progress-ring-circle');
            if (progressRing) {
                progressRing.style.filter = 'brightness(1)';
            }
        });
        
        // Add click ripple effect
        card.addEventListener('click', function(e) {
            const ripple = document.createElement('div');
            ripple.classList.add('ripple-effect');
            
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
    
    // Chart control buttons
    const chartControls = document.querySelectorAll('.chart-control-btn');
    chartControls.forEach(btn => {
        btn.addEventListener('click', function() {
            chartControls.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Add pulse animation
            this.style.animation = 'pulse 0.3s ease';
            setTimeout(() => {
                this.style.animation = '';
            }, 300);
        });
    });
}

function createParticleEffect() {
    const container = document.querySelector('.dashboard-bg-animation');
    if (!container) return;
    
    // Create floating particles
    for (let i = 0; i < 15; i++) {
        const particle = document.createElement('div');
        particle.classList.add('floating-particle');
        
        // Random positioning and animation delay
        particle.style.left = Math.random() * 100 + '%';
        particle.style.animationDelay = Math.random() * 10 + 's';
        particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
        
        container.appendChild(particle);
    }
}

function animateCounters() {
    const counters = document.querySelectorAll('.kpi-value[data-target]');
    
    counters.forEach(counter => {
        const target = parseFloat(counter.getAttribute('data-target'));
        const duration = 2000;
        const step = target / (duration / 16);
        let current = 0;
        
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            
            // Format based on the type of value
            if (counter.id.includes('Rate')) {
                counter.textContent = Math.round(current) + '%';
            } else if (counter.id.includes('Time')) {
                counter.textContent = current.toFixed(1) + 'm';
            } else if (target >= 1000) {
                counter.textContent = Math.round(current).toLocaleString();
            } else {
                counter.textContent = Math.round(current);
            }
        }, 16);
    });
}

function createSparklines() {
    const sparklineContainers = document.querySelectorAll('.kpi-sparkline');
    
    sparklineContainers.forEach(container => {
        const data = generateSparklineData();
        const svg = createSparklineSVG(data);
        container.appendChild(svg);
    });
}

function generateSparklineData() {
    const points = [];
    for (let i = 0; i < 10; i++) {
        points.push(Math.random() * 100);
    }
    return points;
}

function createSparklineSVG(data) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '100');
    svg.setAttribute('height', '30');
    svg.setAttribute('class', 'sparkline-svg');
    
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min;
    
    let pathData = '';
    data.forEach((value, index) => {
        const x = (index / (data.length - 1)) * 100;
        const y = 30 - ((value - min) / range) * 30;
        
        if (index === 0) {
            pathData += `M ${x} ${y}`;
        } else {
            pathData += ` L ${x} ${y}`;
        }
    });
    
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', pathData);
    path.setAttribute('stroke', '#007bff');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('fill', 'none');
    path.setAttribute('opacity', '0.7');
    
    svg.appendChild(path);
    return svg;
}

function enhancedLoadDashboardData(period = 'today') {
    const data = JSON.parse(localStorage.getItem('callCenterData'));
    const periodData = data[period];
    
    // Update KPI cards with modern animations
    updateModernKPICard('totalCalls', periodData.totalCalls);
    updateModernKPICard('serviceRequests', periodData.serviceRequests);
    updateModernKPICard('conversionRate', periodData.conversionRate);
    updateModernKPICard('totalSchedules', periodData.totalSchedules);
    updateModernKPICard('requestCallback', periodData.requestCallback);
    updateModernKPICard('avgResponseTime', periodData.avgResponseTime);
    
    // Animate counters
    setTimeout(() => {
        animateCounters();
    }, 500);
    
    // Create sparklines
    setTimeout(() => {
        createSparklines();
    }, 1000);
}

function updateModernKPICard(kpiType, data) {
    const card = document.querySelector(`[data-kpi="${kpiType}"]`);
    if (!card) return;
    
    const valueElement = card.querySelector('.kpi-value');
    const changeElement = card.querySelector('.kpi-change span');
    const progressRing = card.querySelector('.progress-ring-circle');
    
    if (valueElement) {
        valueElement.setAttribute('data-target', data.value);
    }
    
    if (changeElement) {
        const changeText = `${data.change > 0 ? '+' : ''}${data.change}%`;
        changeElement.textContent = changeText;
        
        // Update trend class
        const changeContainer = changeElement.parentElement;
        changeContainer.className = `kpi-change trend-${data.trend === 'up' ? 'up' : 'down'}`;
    }
    
    // Update progress ring
    if (progressRing) {
        const progress = Math.min(Math.abs(data.value) / 10, 100); // Normalize progress
        const radius = progressRing.r.baseVal.value;
        const circumference = radius * 2 * Math.PI;
        const offset = circumference - progress / 100 * circumference;
        
        setTimeout(() => {
            progressRing.style.strokeDashoffset = offset;
        }, 100);
    }
}

function setupAdvancedFilters() {
    const periodFilters = document.querySelectorAll('input[name="periodFilter"]');
    
    periodFilters.forEach(filter => {
        filter.addEventListener('change', function() {
            if (this.checked) {
                // Add loading state
                const cards = document.querySelectorAll('.modern-kpi-card');
                cards.forEach(card => card.classList.add('loading'));
                
                setTimeout(() => {
                    enhancedLoadDashboardData(this.value);
                    cards.forEach(card => card.classList.remove('loading'));
                }, 300);
                
                // Update charts with animation
                updateChartsWithAnimation(this.value);
            }
        });
    });
}

function updateChartsWithAnimation(period) {
    const charts = document.querySelectorAll('canvas');
    charts.forEach(chart => {
        chart.style.opacity = '0.5';
        chart.style.transform = 'scale(0.95)';
        
        setTimeout(() => {
            chart.style.opacity = '1';
            chart.style.transform = 'scale(1)';
        }, 300);
    });
}

// Override the original loadDashboardData function
window.loadDashboardData = enhancedLoadDashboardData; 