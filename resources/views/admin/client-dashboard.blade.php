<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SureHelp Dashboard - Call Management Platform</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-blue: #4f46e5;
            --secondary-purple: #7c3aed;
            --accent-pink: #ec4899;
            --dark-bg: #0f0f23;
            --darker-bg: #1a1a3e;
            --card-bg: rgba(255, 255, 255, 0.05);
            --border-color: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --success-green: #10b981;
            --warning-orange: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 50%, #2d1b69 100%);
            color: var(--text-primary);
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 25%, rgba(79, 70, 229, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(236, 72, 153, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        /* Header Styles */
        .dashboard-header {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            max-width: 165px;
            /* height: 40px; */
            background: transparent;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            padding-left: 20px;
            border-left: 1px solid #fff;
        }

        .brand-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .page-title {
            font-size: 1.1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-btn, .logout-btn {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .notification-btn:hover, .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            color: var(--text-primary);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: white;
        }

        /* Client Navigation */
        .dashboard-nav {
            max-width: 1400px;
            margin: 0.75rem auto 0;
            padding: 0 2rem;
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
        }

        .dashboard-nav a {
            color: var(--text-secondary);
            text-decoration: none;
            padding: 0.4rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            white-space: nowrap;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .dashboard-nav a:hover,
        .dashboard-nav a.active {
            color: var(--text-primary);
            background: rgba(79, 70, 229, 0.25);
        }

        .dashboard-container > section {
            scroll-margin-top: 140px;
        }

        /* Main Content */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Business Summary Section */
        .business-summary {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .business-summary:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .summary-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .summary-card {
            text-align: center;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 15px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-3px);
        }

        .summary-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            display: block;
        }

        .summary-label {
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .filter-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .filter-btn {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%);
            color: white;
            border-color: transparent;
        }

        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }

        /* Call History Section */
        .call-history-section {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.03);
        }

        .call-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .call-table thead {
            background: #1a103c;
        }

        .call-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: white;
            border: none;
            background: #1a103c;
        }

        .call-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .call-table tbody tr {
            transition: all 0.3s ease;
        }

        .call-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: scale(1.01);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success-green);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-progress {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning-orange);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-scheduled {
            background: rgba(79, 70, 229, 0.2);
            color: var(--primary-blue);
            border: 1px solid rgba(79, 70, 229, 0.3);
        }

        .status-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-neutral {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-secondary);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .dashboard-container {
                padding: 1.5rem;
            }
            
            .summary-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .dashboard-container {
                padding: 1rem;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-buttons {
                justify-content: center;
            }

            .call-table {
                font-size: 0.8rem;
            }

            .call-table th,
            .call-table td {
                padding: 0.75rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .business-summary,
            .call-history-section {
                padding: 1.5rem;
            }

            .summary-number {
                font-size: 2rem;
            }

            .filter-buttons {
                flex-direction: column;
            }

            .filter-btn {
                text-align: center;
            }
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .slide-in {
            animation: slideIn 0.8s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Dropdown Styles */
        .dropdown-menu {
            background: rgba(15, 15, 35, 0.95) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 12px !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
            padding: 0.5rem 0 !important;
        }

        .dropdown-item {
            color: var(--text-primary) !important;
            padding: 0.5rem 1rem !important;
            transition: all 0.3s ease !important;
            border: none !important;
            background: none !important;
        }

        .dropdown-item:hover,
        .dropdown-item:focus {
            background: rgba(79, 70, 229, 0.2) !important;
            color: var(--text-primary) !important;
            transform: translateX(5px) !important;
        }

        .dropdown-item:active {
            background: rgba(79, 70, 229, 0.3) !important;
            color: var(--text-primary) !important;
        }

        .dropdown-divider {
            border-color: var(--border-color) !important;
            margin: 0.5rem 0 !important;
        }

        .dropdown-header {
            color: var(--text-secondary) !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            padding: 0.5rem 1rem !important;
            margin-bottom: 0.25rem !important;
        }

        /* Bootstrap dropdown toggle button styling */
        .dropdown-toggle::after {
            border-top-color: var(--text-primary) !important;
        }

        /* Calendar Section Styles */
        .calendar-section {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .calendar-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .calendar-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .calendar-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .calendar-view-buttons {
            display: flex;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 0.25rem;
        }

        .view-btn {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .view-btn.active {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%);
            color: white;
        }

        .view-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-btn {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .today-btn {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .today-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .current-date {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            min-width: 150px;
            text-align: center;
        }

        /* FullCalendar Custom Styles */
        .fc {
            font-family: 'Roboto', sans-serif !important;
            background: transparent !important;
        }

        .fc-toolbar {
            display: none !important;
        }

        .fc-daygrid-day {
            border: 1px solid var(--border-color) !important;
            background: rgba(255, 255, 255, 0.02) !important;
            transition: all 0.3s ease !important;
        }

        .fc-daygrid-day:hover {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        .fc-daygrid-day-frame {
            min-height: 120px !important;
        }

        .fc-daygrid-day-number {
            color: var(--text-primary) !important;
            font-weight: 500 !important;
            padding: 8px !important;
            font-size: 0.9rem !important;
        }

        .fc-daygrid-day.fc-day-today {
            background: rgba(79, 70, 229, 0.15) !important;
            border: 2px solid var(--primary-blue) !important;
        }

        .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%) !important;
            color: var(--text-primary) !important;
            border-radius: 50% !important;
            width: 32px !important;
            height: 32px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 4px !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3) !important;
        }

        .fc-col-header-cell {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%) !important;
            border: 1px solid var(--border-color) !important;
            padding: 15px 12px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .fc-col-header-cell-cushion {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
            text-decoration: none !important;
        }

        .fc-event {
            border: none !important;
            border-radius: 8px !important;
            padding: 4px 8px !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            margin: 1px 2px !important;
            position: relative !important;
            overflow: hidden !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2) !important;
            border-left: 3px solid !important;
            max-height: 22px !important;
        }

        .fc-event:hover {
            transform: translateY(-3px) scale(1.02) !important;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
        }

        .fc-event-service-request {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: var(--text-primary) !important;
            border-left: 4px solid #047857 !important;
        }

        .fc-event-scheduled {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            color: var(--text-primary) !important;
            border-left: 4px solid #1e40af !important;
        }

        .fc-event-completed {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
            color: var(--text-primary) !important;
            border-left: 4px solid #374151 !important;
        }

        /* Event Time Badge */
        .event-time-badge {
            position: absolute !important;
            top: 2px !important;
            right: 4px !important;
            background: rgba(255, 255, 255, 0.2) !important;
            color: var(--text-primary) !important;
            padding: 1px 4px !important;
            border-radius: 4px !important;
            font-size: 0.6rem !important;
            font-weight: 700 !important;
            backdrop-filter: blur(5px) !important;
        }

        /* Time Grid Enhanced Styles - Matching Agent Dashboard */
        .fc-timegrid-slot {
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            min-height: 40px !important;
            transition: all 0.2s ease !important;
            position: relative !important;
        }

        .fc-timegrid-slot:hover {
            background: rgba(255, 255, 255, 0.02) !important;
        }

        .fc-timegrid-slot-label {
            color: var(--text-secondary) !important;
            font-size: 0.75rem !important;
            font-weight: 500 !important;
            padding: 4px 8px !important;
            background: rgba(255, 255, 255, 0.02) !important;
            border-right: 1px solid rgba(255, 255, 255, 0.1) !important;
            min-width: 65px !important;
            text-align: right !important;
        }

        .fc-timegrid-axis {
            border-right: 1px solid var(--border-color) !important;
            background: rgba(255, 255, 255, 0.02) !important;
            width: 70px !important;
        }

        /* Alternate time slot styling for better readability */
        .fc-timegrid-slot:nth-child(even) {
            background: rgba(255, 255, 255, 0.01) !important;
        }

        .fc-timegrid-slot:nth-child(odd) {
            background: rgba(255, 255, 255, 0.005) !important;
        }

        /* Current time indicator styling */
        .fc-timegrid-now-indicator-line {
            border-color: var(--primary-blue) !important;
            border-width: 3px !important;
            box-shadow: 0 0 15px rgba(79, 70, 229, 0.6) !important;
        }

        .fc-timegrid-now-indicator-arrow {
            border-color: var(--primary-blue) !important;
            background: var(--primary-blue) !important;
            box-shadow: 0 0 10px rgba(79, 70, 229, 0.5) !important;
        }

        /* Enhanced Event Styling */
        .fc-event-title {
            font-weight: 600 !important;
            margin-bottom: 2px !important;
        }

        .fc-event-time {
            font-size: 0.7rem !important;
            opacity: 0.9 !important;
            font-weight: 500 !important;
        }

        /* More Link Styling */
        .fc-more-link {
            background: rgba(255, 255, 255, 0.1) !important;
            color: var(--text-primary) !important;
            padding: 4px 8px !important;
            border-radius: 6px !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            border: 1px solid var(--border-color) !important;
            transition: all 0.3s ease !important;
        }

        .fc-more-link:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            transform: translateY(-1px) !important;
        }

        /* Multiple events in same time slot styling */
        .fc-timegrid-event-harness {
            margin: 0.5px 0 !important;
        }

        .fc-timegrid-event {
            border-radius: 6px !important;
            font-size: 0.65rem !important;
            line-height: 1.2 !important;
            padding: 2px 6px !important;
        }

        /* Stack multiple events vertically in same slot */
        .fc-timegrid-event:not(:last-child) {
            margin-bottom: 1px !important;
        }

        /* Enhanced event colors for better distinction */
        .fc-event-service-request {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            border-left-color: #047857 !important;
        }

        .fc-event-scheduled {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            border-left-color: #1e40af !important;
        }

        .fc-event-completed {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
            border-left-color: #374151 !important;
        }

        .fc-event-emergency {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
            border-left-color: #b91c1c !important;
            animation: pulse 2s infinite !important;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        /* Better spacing for time grid */
        .fc-timegrid-body {
            height: auto !important;
            min-height: auto !important;
        }

        /* Time Grid Layout Styling */
        .fc-timegrid {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-view {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-view .fc-view-harness {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-view .fc-scroller {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-view .fc-scroller-liquid {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        /* Remove extra spacing at bottom */
        .fc-timegrid-slot.fc-timegrid-slot-last {
            border-bottom: none !important;
        }

        .fc-timegrid-body .fc-timegrid-slot:last-child {
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }

        /* Ensure proper calendar height */
        #serviceCalendar {
            height: auto !important;
            max-height: none !important;
        }

        .fc-timegrid-view .fc-view-harness .fc-scroller {
            height: auto !important;
            max-height: none !important;
        }

        /* Remove all extra spacing and padding */
        .fc-timegrid-view .fc-view-harness {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-view .fc-view-harness .fc-scroller-liquid {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
            padding-bottom: 0 !important;
            margin-bottom: 0 !important;
        }

        /* Remove bottom spacing from calendar container */
        .calendar-section {
            padding-bottom: 1rem !important;
        }

        /* Ensure time grid ends cleanly */
        .fc-timegrid-body .fc-timegrid-slot:last-child {
            border-bottom: none !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }

        /* Remove any extra height from timegrid */
        .fc-timegrid {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        /* Override FullCalendar sticky header background */
        .fc .fc-scrollgrid-section-sticky > * {
            background: #1a103c !important;
            position: sticky !important;
            z-index: 3 !important;
        }

        /* Additional Calendar Styles */
        .fc-daygrid-day {
            border: 1px solid var(--border-color) !important;
            background: rgba(255, 255, 255, 0.02) !important;
            transition: all 0.3s ease !important;
        }

        .fc-daygrid-day:hover {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        .fc-daygrid-day-frame {
            min-height: 100px !important;
        }

        .fc-daygrid-day-number {
            color: var(--text-primary) !important;
            font-weight: 500 !important;
            padding: 8px !important;
            font-size: 0.9rem !important;
        }

        .fc-daygrid-day.fc-day-today {
            background: rgba(79, 70, 229, 0.15) !important;
            border: 2px solid var(--primary-blue) !important;
        }

        .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-purple) 100%) !important;
            color: var(--text-primary) !important;
            border-radius: 50% !important;
            width: 32px !important;
            height: 32px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 4px !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3) !important;
        }

        .fc-col-header-cell {
            background: #1a103c !important;
            border: 1px solid var(--border-color) !important;
            padding: 15px 12px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            position: static !important;
            top: auto !important;
            z-index: auto !important;
            backdrop-filter: none !important;
        }

        .fc-col-header-cell-cushion {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            font-size: 0.85rem !important;
            text-decoration: none !important;
        }

        .fc-event {
            border: none !important;
            border-radius: 8px !important;
            padding: 4px 8px !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            margin: 1px 2px !important;
            position: relative !important;
            overflow: hidden !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2) !important;
            border-left: 3px solid !important;
            max-height: 22px !important;
        }

        .fc-event:hover {
            transform: translateY(-3px) scale(1.02) !important;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3) !important;
        }

        /* Event Time Badge */
        .event-time-badge {
            position: absolute !important;
            top: 2px !important;
            right: 4px !important;
            background: rgba(255, 255, 255, 0.2) !important;
            color: var(--text-primary) !important;
            padding: 1px 4px !important;
            border-radius: 4px !important;
            font-size: 0.6rem !important;
            font-weight: 700 !important;
            backdrop-filter: blur(5px) !important;
        }
        .fc-daygrid-day.fc-day-sat,
        .fc-daygrid-day.fc-day-sun {
            background: rgba(255, 255, 255, 0.02) !important;
        }

        .fc-daygrid-day.fc-day-sat .fc-daygrid-day-number,
        .fc-daygrid-day.fc-day-sun .fc-daygrid-day-number {
            color: var(--text-secondary) !important;
        }

        /* Responsive Calendar */
        @media (max-width: 768px) {
            .calendar-header {
                flex-direction: column;
                align-items: stretch;
            }

            .calendar-controls {
                justify-content: center;
            }

            .calendar-view-buttons {
                width: 100%;
                justify-content: center;
            }

            .fc-daygrid-day-frame {
                min-height: 80px !important;
            }

            .fc-event {
                font-size: 0.7rem !important;
                padding: 2px 4px !important;
            }
        }

        @media (max-width: 480px) {
            .calendar-section {
                padding: 1.5rem;
            }

            .fc-daygrid-day-frame {
                min-height: 60px !important;
            }

            .view-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .current-date {
                font-size: 1rem;
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="dashboard-header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo-icon">
                    <img src="{{ asset('assets/img/logo.png') }}" alt="SHS Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div class="logo-text">
                    <!-- <div class="brand-name">SureHelp</div>
                    <div class="page-title">Professional Call Management</div> -->
                    <div class="d-flex align-items-center" style="margin-top: 2px;">
                        <i class="fa-solid fa-users me-2"></i>
                        <span class="fw-semibold">Client Dashboard</span>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <a href="#" class="notification-btn">
                    <i class="fas fa-bell"></i>
                    Notifications
                </a>
                <div class="dropdown">
                    <a href="#" class="logout-btn" data-bs-toggle="dropdown">
                        {{ explode(' ', Auth::user()->name)[0] }}
                        <i class="fas fa-chevron-down"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="{{ route('auth.logout') }}">Sign Out</a></li>
                    </ul>
                </div>
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
            </div>
        </div>
        <nav class="dashboard-nav" aria-label="Client dashboard">
            <a href="#overview" class="active"><i class="fas fa-chart-pie me-1"></i>Overview</a>
            <a href="#calendar"><i class="fas fa-calendar-alt me-1"></i>Service Calendar</a>
            <a href="#call-history"><i class="fas fa-phone me-1"></i>Call History</a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="dashboard-container">

        <!-- Business Summary -->
        <section class="business-summary fade-in" id="overview">
            <div class="filter-section">
            <h2 class="summary-title">Business Summary</h2>
            <div class="filter-buttons">
                <button class="filter-btn active" data-period="daily">Daily</button>
                <button class="filter-btn" data-period="weekly">Weekly</button>
                <button class="filter-btn" data-period="monthly">Monthly</button>
            </div></div>
            <div class="summary-grid">
                <div class="summary-card">
                    <span class="summary-number" id="totalCalls">{{ number_format($initialSummary['totalCalls'] ?? 0) }}</span>
                    <div class="summary-label">Total Calls</div>
                </div>
                <div class="summary-card">
                    <span class="summary-number" id="serviceRequests">{{ number_format($initialSummary['serviceRequests'] ?? 0) }}</span>
                    <div class="summary-label">Service Requests</div>
                </div>
                <div class="summary-card">
                    <span class="summary-number" id="totalScheduled">{{ number_format($initialSummary['totalScheduled'] ?? 0) }}</span>
                    <div class="summary-label">Total Scheduled</div>
                </div>
                <div class="summary-card">
                    <span class="summary-number" id="inProgress">{{ number_format($initialSummary['inProgress'] ?? 0) }}</span>
                    <div class="summary-label">Service in Progress</div>
                </div>
            </div>
        </section>

        <!-- Service Calendar -->
        <section class="calendar-section fade-in" id="calendar">
            <div class="calendar-header">
                <h2 class="calendar-title">Service Schedule Calendar</h2>
                <div class="calendar-controls">
                <div class="calendar-view-buttons">
                    <button class="view-btn active" data-view="timeGridDay">Day</button>
                    <button class="view-btn" data-view="timeGridWeek">Week</button>
                    <button class="view-btn" data-view="dayGridMonth">Month</button>
                </div>
                    <div class="calendar-nav">
                        <button class="nav-btn" id="prevBtn">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="today-btn" id="todayBtn">Today</button>
                        <button class="nav-btn" id="nextBtn">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="current-date" id="currentDate"></div>
                </div>
            </div>
            <div id="serviceCalendar"></div>
        </section>

        <!-- Call History -->
        <section class="call-history-section slide-in" id="call-history">
            <h2 class="section-title">Call History</h2>
            <div class="table-container">
                <table class="call-table">
                    <thead>
                        <tr>
                            <th>Call ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Caller Name</th>
                            <th>Caller Number</th>
                            <th>Call Outcome</th>
                            <th>Agent Name</th>
                            <th>Status</th>
                            <th>Service Location</th>
                        </tr>
                    </thead>
                    <tbody id="callHistoryTable">
                        @foreach(($initialCalls ?? []) as $call)
                        <tr>
                            <td>{{ $call['callId'] }}</td>
                            <td>{{ $call['date'] }}</td>
                            <td>{{ $call['time'] }}</td>
                            <td>{{ $call['callerName'] }}</td>
                            <td>{{ $call['callerNumber'] }}</td>
                            <td>{{ $call['callOutcome'] }}</td>
                            <td>{{ $call['agentName'] }}</td>
                            <td><span class="status-badge status-{{ $call['statusTone'] }}">{{ $call['status'] }}</span></td>
                            <td>{{ $call['serviceLocation'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- FullCalendar JS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

    <script>
        // Server-provided datasets for the authenticated client
        const callData = @json($callData ?? []);
        const summaryData = @json($summaryData ?? []);
        const defaultSummaryPeriod = @json($defaultSummaryPeriod ?? 'daily');
        const defaultCallHistoryPeriod = @json($defaultCallHistoryPeriod ?? 'all');

        // Service Calendar Implementation
        let serviceCalendar;
        let currentView = 'timeGridDay';

        // Initialize the service calendar
        function initializeServiceCalendar() {
            const calendarEl = document.getElementById('serviceCalendar');
            
            serviceCalendar = new FullCalendar.Calendar(calendarEl, {
                initialView: currentView,
                headerToolbar: false,
                height: 'auto',
                dayMaxEvents: 4,
                moreLinkClick: 'popover',
                eventDisplay: 'block',
                slotMinTime: '00:00:00',
                slotMaxTime: '23:59:59',
                slotDuration: '01:00:00',
                slotLabelInterval: '01:00:00',
                allDaySlot: false,
                nowIndicator: true,
                dayHeaderFormat: { weekday: 'short', day: 'numeric' },
                slotLabelFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                },
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                },
                events: function(info, successCallback, failureCallback) {
                    // Fetch service requests from call data
                    const events = generateServiceEvents(info.start, info.end);
                    successCallback(events);
                },
                eventDidMount: function(info) {
                    const event = info.event;
                    const eventType = event.extendedProps.status || 'service-request';
                    info.el.classList.add(`fc-event-${eventType}`);
                    
                    // Add enhanced tooltip
                    const tooltip = `${event.title}\n📅 Date: ${event.start.toLocaleDateString()}\n⏰ Time: ${event.extendedProps.serviceTime || 'TBD'}\n📍 Location: ${event.extendedProps.serviceLocation || 'TBD'}\n👤 Agent: ${event.extendedProps.agentName}\n📊 Status: ${event.extendedProps.status || 'Service Request'}`;
                    info.el.setAttribute('title', tooltip);
                    
                    // Add time badge to event
                    if (event.extendedProps.serviceTime && event.extendedProps.serviceTime !== 'TBD') {
                        const timeBadge = document.createElement('div');
                        timeBadge.className = 'event-time-badge';
                        timeBadge.textContent = event.extendedProps.serviceTime;
                        info.el.appendChild(timeBadge);
                    }
                },
                eventClick: function(info) {
                    const event = info.event;
                    showServiceEventDetails(event);
                },
                viewDidMount: function(info) {
                    updateCurrentDate(info.view.title);
                },
                dayMaxEventRows: 3,
                moreLinkContent: function(arg) {
                    return `+${arg.num} more`;
                }
            });
            
            serviceCalendar.render();
        }

        // Generate service events from call data
        function generateServiceEvents(start, end) {
            const events = [];
            const processedEvents = new Set(); // To track unique events
            
            // Process all call data periods
            Object.values(callData).forEach(periodCalls => {
                periodCalls.forEach(call => {
                    // Only include calls with scheduled services
                    if (call.hasService) {
                        // Use service date if available, otherwise fall back to creation date
                        const eventDate = call.serviceDate ? new Date(call.serviceDate) : new Date(call.date);
                        
                        // Check if event is within the calendar view range
                        if (eventDate >= start && eventDate <= end) {
                            // Create a unique key to prevent duplicates
                            const eventKey = `${call.callId}-${eventDate.toDateString()}`;
                            
                            // Only add if we haven't processed this event yet
                            if (!processedEvents.has(eventKey)) {
                                processedEvents.add(eventKey);
                                
                                const eventType = getEventTypeFromStatus(call.status);
                                const serviceTime = formatServiceTime(call.serviceWindow);
                                
                                // Parse service time for proper time slot positioning
                                const timeSlot = parseServiceTimeToSlot(serviceTime);
                                
                                // Create event with proper time positioning
                                const eventStart = timeSlot ? 
                                    new Date(eventDate.getFullYear(), eventDate.getMonth(), eventDate.getDate(), timeSlot.hours, timeSlot.minutes) : 
                                    eventDate;
                                
                                // Add duration for better visual representation
                                const eventEnd = timeSlot ? 
                                    new Date(eventStart.getTime() + (30 * 60000)) : // 30 minutes duration
                                    new Date(eventStart.getTime() + (24 * 60 * 60000)); // All day
                                
                                events.push({
                                    id: call.callId,
                                    title: `${call.callOutcome}`,
                                    start: eventStart,
                                    end: eventEnd,
                                    allDay: !timeSlot,
                                    extendedProps: {
                                        status: eventType,
                                        serviceTime: serviceTime,
                                        serviceLocation: call.serviceLocation,
                                        agentName: call.agentName,
                                        callId: call.callId,
                                        callerName: call.callerName,
                                        callerNumber: call.callerNumber,
                                        serviceDate: call.serviceDate,
                                        note: call.note || ''
                                    }
                                });
                            }
                        }
                    }
                });
            });
            
            return events;
        }

        // Format service time to include AM/PM
        function formatServiceTime(serviceWindow) {
            if (!serviceWindow || serviceWindow === 'TBD' || serviceWindow.trim() === '') {
                return 'TBD';
            }
            
            // Check if it's already in 12-hour format (contains AM/PM)
            if (serviceWindow.includes('AM') || serviceWindow.includes('PM')) {
                return serviceWindow;
            }
            
            // Check if it's in 24-hour format (HH:MM)
            const timeRegex = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/;
            const match = serviceWindow.match(timeRegex);
            
            if (match) {
                const hours = parseInt(match[1]);
                const minutes = match[2];
                let displayHours = hours;
                let ampm = 'AM';
                
                if (hours === 0) {
                    displayHours = 12;
                } else if (hours === 12) {
                    ampm = 'PM';
                } else if (hours > 12) {
                    displayHours = hours - 12;
                    ampm = 'PM';
                }
                
                return `${displayHours}:${minutes} ${ampm}`;
            }
            
            // If it's not a standard time format, return as is
            return serviceWindow;
        }

        // Parse service time to time slot for calendar positioning
        function parseServiceTimeToSlot(serviceTime) {
            if (!serviceTime || serviceTime === 'TBD' || serviceTime.includes('Emergency') || serviceTime.includes('ASAP')) {
                return null;
            }
            
            // Extract time from various formats
            const timeRegex = /(\d{1,2}):(\d{2})\s*(AM|PM)?/i;
            const match = serviceTime.match(timeRegex);
            
            if (match) {
                let hours = parseInt(match[1]);
                const minutes = parseInt(match[2]);
                const ampm = match[3] ? match[3].toUpperCase() : '';
                
                // Convert to 24-hour format
                if (ampm === 'PM' && hours !== 12) {
                    hours += 12;
                } else if (ampm === 'AM' && hours === 12) {
                    hours = 0;
                }
                
                return { hours, minutes };
            }
            
            return null;
        }

        // Get event type from status
        function getEventTypeFromStatus(status) {
            const statusLower = status.toLowerCase();
            if (statusLower.includes('completed')) {
                return 'completed';
            } else if (statusLower.includes('progress') || statusLower.includes('scheduled')) {
                return 'scheduled';
            } else {
                return 'service-request';
            }
        }

        // Show service event details modal
        function showServiceEventDetails(event) {
            const props = event.extendedProps;
            const serviceDate = props.serviceDate ? new Date(props.serviceDate) : event.start;
            const startDate = serviceDate.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            const modalContent = `
                <div class="service-event-details" style="background: rgba(15, 15, 35, 0.95); border-radius: 12px; color: white; padding: 1.5rem;">
                    <h5 style="color: #4f46e5; font-weight: 600; margin-bottom: 1rem;">Service Request Details</h5>
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #10b981;">Service Date:</strong> ${startDate}
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #3b82f6;">Service Time:</strong> ${props.serviceTime || 'To be determined'}
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #8b5cf6;">Status:</strong> 
                        <span class="badge" style="background: ${getStatusColor(props.status)}; color: white; padding: 0.25rem 0.5rem; border-radius: 6px; margin-left: 0.5rem;">
                            ${props.status.charAt(0).toUpperCase() + props.status.slice(1).replace('-', ' ')}
                        </span>
                    </div>
                    ${props.serviceLocation && props.serviceLocation !== '—' ? `
                        <div style="margin-bottom: 0.75rem;">
                            <strong style="color: #f59e0b;">Location:</strong> ${escapeHtml(props.serviceLocation)}
                        </div>
                    ` : ''}
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #ec4899;">Assigned Agent:</strong> ${escapeHtml(props.agentName)}
                    </div>
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #06b6d4;">Call ID:</strong> ${escapeHtml(props.callId)}
                    </div>
                    ${props.note && props.note.trim() !== '' ? `
                    <div style="margin-bottom: 0.75rem;">
                        <strong style="color: #93c5fd;">Note:</strong> ${escapeHtml(props.note)}
                    </div>
                    ` : ''}
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                        <small style="color: rgba(255, 255, 255, 0.7);">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            Your scheduled service request
                        </small>
                    </div>
                </div>
            `;
            
            // Create and show modal
            showEventModal(modalContent);
        }

        // Get status color for badge
        function getStatusColor(status) {
            switch(status) {
                case 'completed':
                    return '#10b981';
                case 'scheduled':
                    return '#3b82f6';
                case 'service-request':
                default:
                    return '#f59e0b';
            }
        }

        // Show event modal
        function showEventModal(content) {
            // Remove existing modal if any
            const existingModal = document.getElementById('serviceEventModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Create modal
            const modal = document.createElement('div');
            modal.id = 'serviceEventModal';
            modal.className = 'modal fade show';
            modal.style.display = 'block';
            modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="background: transparent; border: none; position: relative;">
                        <button type="button" onclick="this.closest('.modal').remove()" style="position: absolute; top: 10px; right: 15px; background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); color: white; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 10; transition: all 0.3s ease;">
                            <i class="fas fa-times"></i>
                        </button>
                        ${content}
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Update current date display
        function updateCurrentDate(title) {
            document.getElementById('currentDate').textContent = title;
        }

        // Calendar navigation and view controls
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize calendar
            initializeServiceCalendar();
            
            // View buttons
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    // Remove active class from all buttons
                    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                    // Add active to clicked button
                    this.classList.add('active');
                    
                    // Change calendar view
                    currentView = this.getAttribute('data-view');
                    serviceCalendar.changeView(currentView);
                });
            });
            
            // Navigation buttons
            document.getElementById('prevBtn').addEventListener('click', function() {
                serviceCalendar.prev();
            });
            
            document.getElementById('nextBtn').addEventListener('click', function() {
                serviceCalendar.next();
            });
            
            document.getElementById('todayBtn').addEventListener('click', function() {
                serviceCalendar.today();
            });
        });

        // Function to update summary data
        function updateSummary(period) {
            const data = summaryData[period];
            document.getElementById('totalCalls').textContent = data.totalCalls.toLocaleString();
            document.getElementById('serviceRequests').textContent = data.serviceRequests.toLocaleString();
            document.getElementById('totalScheduled').textContent = data.totalScheduled.toLocaleString();
            document.getElementById('inProgress').textContent = data.inProgress.toLocaleString();
        }

        // Escape server values before inserting them as HTML
        function escapeHtml(value) {
            return String(value ?? '—').replace(/[&<>"']/g, ch => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[ch]);
        }

        // Function to populate call history table
        function populateCallHistory(period) {
            const tableBody = document.getElementById('callHistoryTable');
            const data = callData[period] || [];
            
            tableBody.innerHTML = '';
            
            if (data.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                        No call records found
                    </td>
                `;
                tableBody.appendChild(row);
                return;
            }
            
            data.forEach(call => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${escapeHtml(call.callId)}</td>
                    <td>${escapeHtml(call.date)}</td>
                    <td>${escapeHtml(call.time)}</td>
                    <td>${escapeHtml(call.callerName)}</td>
                    <td>${escapeHtml(call.callerNumber)}</td>
                    <td>${escapeHtml(call.callOutcome)}</td>
                    <td>${escapeHtml(call.agentName)}</td>
                    <td><span class="status-badge status-${escapeHtml(call.statusTone)}">${escapeHtml(call.status)}</span></td>
                    <td>${escapeHtml(call.serviceLocation)}</td>
                `;
                tableBody.appendChild(row);
            });
        }

        // Event listeners for filter buttons
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('.filter-btn');
            
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Remove active class from all buttons
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    // Get the period from data attribute
                    const period = this.getAttribute('data-period');
                    
                    // Update summary with selected period
                    updateSummary(period);
                    
                    // Call history always shows all calls (not filtered by period)
                    populateCallHistory('all');
                });
            });

            // Initialize with server-provided default periods
            // Set active state on filter buttons for summary
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.getAttribute('data-period') === defaultSummaryPeriod) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // Initialize summary with default period, call history with all calls
            updateSummary(defaultSummaryPeriod);
            populateCallHistory(defaultCallHistoryPeriod);
        });

        // Highlight the nav link for the section currently in view
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.dashboard-nav a[href^="#"]');
            const observer = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    navLinks.forEach(link => {
                        link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
                    });
                });
            }, { rootMargin: '-40% 0px -55% 0px' });

            navLinks.forEach(link => {
                const section = document.querySelector(link.getAttribute('href'));
                if (section) observer.observe(section);
            });
        });

        // Add some interactive features
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to summary cards
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Add click effect to table rows
            const tableRows = document.querySelectorAll('.call-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('click', function() {
                    // Remove highlight from all rows
                    tableRows.forEach(r => r.style.background = '');
                    // Add highlight to clicked row
                    this.style.background = 'rgba(79, 70, 229, 0.1)';
                });
            });
        });
    </script>
</body>
</html> 