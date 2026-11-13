/**
 * Modern Charting Engine Powered by ApexCharts (with SVG Fallback)
 * Small Business Sales and Expense Tracking System
 *
 * Implements high-performance, polished interactive charts matching reference design:
 * - Dual-series Sales vs. Expenses curves (smooth area + dashed line)
 * - Custom dark floating card tooltip (Date, Sales, Expenses, Net)
 * - Compact currency formatting (₱, ₱k, ₱M)
 * - Hover pill/column highlight and dashed gridlines
 */

(function (window) {
    'use strict';

    const BTSCharts = {};

    /**
     * Escape HTML special characters for safe innerHTML injection
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Format currency numbers nicely (e.g. ₱ 1,250.00).
     */
    function formatCurrency(val, currency = '₱') {
        const num = parseFloat(val) || 0;
        return `${currency} ${num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    /**
     * Format compact axis values (e.g. ₱0, ₱1.25k, ₱2.5k).
     */
    function formatCompact(val, currency = '₱') {
        const num = Number(val) || 0;
        if (num === 0) return `${currency}0`;
        const abs = Math.abs(num);
        if (abs >= 1000000) {
            return `${currency}${(num / 1000000).toFixed(1)}M`;
        }
        if (abs >= 1000) {
            const k = num / 1000;
            const str = (k % 1 === 0) ? k.toFixed(0) : k.toFixed(1);
            return `${currency}${str}k`;
        }
        return `${currency}${Math.round(num)}`;
    }

    /**
     * Destroy any existing ApexCharts instance attached to container.
     */
    function cleanupChartInstance(container) {
        if (container && container._apexChartInstance) {
            try {
                container._apexChartInstance.destroy();
            } catch (e) {
                // Ignore cleanup error
            }
            container._apexChartInstance = null;
        }
    }

    // =========================================================================
    // 1. DASHBOARD DUAL-SERIES CHART (APEXCHARTS)
    // Matches the reference design (Sales spline + Expenses dashed line)
    // =========================================================================
    BTSCharts.renderDashboardDualChart = function (containerId, options = {}) {
        const container = (typeof containerId === 'string') ? document.getElementById(containerId) : containerId;
        if (!container) return;

        let sales = options.sales;
        let expenses = options.expenses;
        let labels = options.labels;

        if (!sales && container.dataset.sales) {
            try { sales = JSON.parse(container.dataset.sales); } catch (e) { sales = null; }
        }
        if (!expenses && container.dataset.expenses) {
            try { expenses = JSON.parse(container.dataset.expenses); } catch (e) { expenses = null; }
        }
        if (!labels && container.dataset.labels) {
            try { labels = JSON.parse(container.dataset.labels); } catch (e) { labels = null; }
        }

        // Backward compatibility fallback to data-values
        if (!sales && container.dataset.values) {
            try { sales = JSON.parse(container.dataset.values); } catch (e) { sales = null; }
        }

        // Strictly use the real data array, casting each entry to float (no mock data)
        sales = Array.isArray(sales) ? sales.map(v => parseFloat(v) || 0) : [0, 0, 0, 0, 0, 0, 0];
        expenses = Array.isArray(expenses) ? expenses.map(v => parseFloat(v) || 0) : [0, 0, 0, 0, 0, 0, 0];

        if (!labels || !labels.length) {
            labels = [];
            for (let i = 6; i >= 0; i--) {
                const d = new Date(Date.now() - i * 864e5);
                labels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            }
        }

        const currency = options.currency || '₱';

        // Check if ApexCharts is available
        if (window.ApexCharts) {
            cleanupChartInstance(container);
            container.innerHTML = '';

            const apexOptions = {
                series: [
                    {
                        name: 'Sales',
                        type: 'area',
                        data: sales
                    },
                    {
                        name: 'Expenses',
                        type: 'line',
                        data: expenses
                    }
                ],
                chart: {
                    height: 295,
                    type: 'line',
                    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 550
                    }
                },
                colors: ['#1d68e1', '#ef4444'], // Solid Royal Blue for Sales, Red for Expenses
                stroke: {
                    curve: 'smooth',
                    width: [3.5, 2.5],
                    dashArray: [0, 6] // [0 for solid Sales, 6 for dashed Expenses]
                },
                fill: {
                    type: ['gradient', 'solid'],
                    gradient: {
                        shade: 'light',
                        type: 'vertical',
                        shadeIntensity: 0.35,
                        gradientToColors: ['#3b82f6', '#ef4444'],
                        inverseColors: false,
                        opacityFrom: [0.28, 0],
                        opacityTo: [0.01, 0],
                        stops: [0, 85, 100]
                    }
                },
                markers: {
                    size: [5, 0], // White circle nodes on Sales, zero on Expenses
                    colors: ['#ffffff'],
                    strokeColors: '#1d68e1',
                    strokeWidth: 2.5,
                    hover: {
                        size: 7.5,
                        sizeOffset: 3
                    },
                    discrete: [
                        {
                            seriesIndex: 0,
                            dataPointIndex: Math.max(0, sales.length - 1),
                            fillColor: '#ffffff',
                            strokeColor: '#1d68e1',
                            size: 6
                        }
                    ]
                },
                grid: {
                    borderColor: '#e8edf5',
                    strokeDashArray: 4,
                    xaxis: {
                        lines: { show: false }
                    },
                    yaxis: {
                        lines: { show: true }
                    },
                    padding: {
                        top: 6,
                        right: 18,
                        bottom: 4,
                        left: 14
                    }
                },
                xaxis: {
                    categories: labels,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    tooltip: {
                        enabled: false
                    },
                    labels: {
                        style: {
                            colors: labels.map((_, idx) => (idx === labels.length - 1 ? '#1d68e1' : '#64748b')),
                            fontSize: '11.5px',
                            fontFamily: "'Plus Jakarta Sans', sans-serif",
                            fontWeight: labels.map((_, idx) => (idx === labels.length - 1 ? 700 : 500))
                        }
                    },
                    crosshairs: {
                        show: true,
                        width: 66,
                        position: 'back',
                        opacity: 0.08,
                        stroke: { width: 0 },
                        fill: {
                            type: 'solid',
                            color: '#1d68e1'
                        },
                        dropShadow: { enabled: false }
                    }
                },
                yaxis: {
                    tickAmount: 4,
                    min: 0,
                    labels: {
                        style: {
                            colors: '#94a3b8',
                            fontSize: '11px',
                            fontFamily: "'Plus Jakarta Sans', sans-serif",
                            fontWeight: 500
                        },
                        formatter: function (val) {
                            return formatCompact(val, currency);
                        }
                    }
                },
                legend: {
                    show: true,
                    position: 'top',
                    horizontalAlign: 'left',
                    fontSize: '14px',
                    fontWeight: 600,
                    labels: { colors: '#1e293b' },
                    markers: {
                        width: 11,
                        height: 11,
                        radius: 12,
                        offsetX: -4
                    },
                    itemMargin: { horizontal: 16, vertical: 4 },
                    offsetY: -4
                },
                tooltip: {
                    shared: true,
                    intersect: false,
                    custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                        const cats = (w.globals.categoryLabels && w.globals.categoryLabels.length)
                            ? w.globals.categoryLabels
                            : (w.globals.labels || []);
                        const dateLabel = cats[dataPointIndex] || labels[dataPointIndex] || 'Date';
                        const sVal = (series[0] && series[0][dataPointIndex] !== undefined) ? Number(series[0][dataPointIndex]) : 0;
                        const eVal = (series[1] && series[1][dataPointIndex] !== undefined) ? Number(series[1][dataPointIndex]) : 0;
                        const net = sVal - eVal;
                        const netFormatted = (net >= 0)
                            ? `${currency}${Math.round(net).toLocaleString()}`
                            : `${currency}-${Math.abs(Math.round(net)).toLocaleString()}`;

                        return `
                            <div style="background:#0f172a; border-radius:12px; padding:10px 14px; box-shadow:0 16px 36px rgba(15,23,42,0.45); border:1px solid rgba(255,255,255,0.12); color:#ffffff; font-family:'Plus Jakarta Sans',sans-serif; min-width:130px; text-align:left;">
                                <div style="font-weight:700; font-size:13px; color:#ffffff; margin-bottom:5px;">${dateLabel}</div>
                                <div style="font-size:12px; color:#f1f5f9; margin-bottom:2px;">Sales: ${currency}${Math.round(sVal).toLocaleString()}</div>
                                <div style="font-size:12px; color:#f1f5f9; margin-bottom:2px;">Expenses: ${currency}${Math.round(eVal).toLocaleString()}</div>
                                <div style="font-size:12px; color:#f1f5f9;">Net: ${netFormatted}</div>
                            </div>
                        `;
                    }
                }
            };

            const chart = new ApexCharts(container, apexOptions);
            chart.render();
            container._apexChartInstance = chart;
            return;
        }

        // SVG fallback if ApexCharts script fails
        renderSvgDualChartFallback(container, sales, expenses, labels, currency);
    };

    // =========================================================================
    // 2. EXPENSES SINGLE-SERIES AREA CHART (APEXCHARTS)
    // =========================================================================
    BTSCharts.renderAreaTrend = function (containerId, options = {}) {
        const container = (typeof containerId === 'string') ? document.getElementById(containerId) : containerId;
        if (!container) return;

        let rawValues = options.values;
        let rawLabels = options.labels;

        if (!rawValues && container.dataset.values) {
            try { rawValues = JSON.parse(container.dataset.values); } catch (e) { rawValues = []; }
        }
        if (!rawLabels && container.dataset.labels) {
            try { rawLabels = JSON.parse(container.dataset.labels); } catch (e) { rawLabels = []; }
        }

        const data = (rawValues && rawValues.length) ? rawValues.map(v => parseFloat(v) || 0) : [0, 0, 0, 0, 0, 0, 0];
        const labels = (rawLabels && rawLabels.length) ? rawLabels : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        const color = options.color || '#ef4444';
        const colorLight = options.colorLight || '#f87171';
        const currency = options.currency || '₱';
        const seriesName = options.seriesName || 'Expenses';

        if (window.ApexCharts) {
            cleanupChartInstance(container);
            container.innerHTML = '';

            const apexOptions = {
                series: [{
                    name: seriesName,
                    data: data
                }],
                chart: {
                    height: 240,
                    type: 'area',
                    fontFamily: "'Plus Jakarta Sans', sans-serif",
                    toolbar: { show: false },
                    zoom: { enabled: false }
                },
                colors: [color],
                stroke: {
                    curve: 'smooth',
                    width: 3
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shade: 'light',
                        type: 'vertical',
                        shadeIntensity: 0.3,
                        gradientToColors: [colorLight],
                        opacityFrom: 0.28,
                        opacityTo: 0.02,
                        stops: [0, 90, 100]
                    }
                },
                markers: {
                    size: 4.5,
                    colors: ['#ffffff'],
                    strokeColors: color,
                    strokeWidth: 2.5,
                    hover: { size: 6.5 }
                },
                grid: {
                    borderColor: '#e8edf5',
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { top: 10, right: 16, bottom: 4, left: 12 }
                },
                xaxis: {
                    categories: labels,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: {
                        style: {
                            colors: '#64748b',
                            fontSize: '11px',
                            fontWeight: 500
                        }
                    }
                },
                yaxis: {
                    tickAmount: 4,
                    min: 0,
                    labels: {
                        style: { colors: '#94a3b8', fontSize: '11px', fontWeight: 500 },
                        formatter: (val) => formatCompact(val, currency)
                    }
                },
                tooltip: {
                    custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                        const dateLabel = (w.globals.categoryLabels && w.globals.categoryLabels[dataPointIndex])
                            || (w.globals.labels && w.globals.labels[dataPointIndex])
                            || labels[dataPointIndex];
                        const val = series[seriesIndex][dataPointIndex];
                        return `
                            <div style="background:#0f172a; border-radius:10px; padding:8px 12px; box-shadow:0 12px 24px rgba(15,23,42,0.4); border:1px solid rgba(255,255,255,0.12); color:#ffffff; font-family:'Plus Jakarta Sans',sans-serif; font-size:12px;">
                                <div style="color:#94a3b8; font-size:11px; margin-bottom:3px;">${dateLabel}</div>
                                <div><strong>${seriesName}:</strong> ${formatCurrency(val, currency)}</div>
                            </div>
                        `;
                    }
                }
            };

            const chart = new ApexCharts(container, apexOptions);
            chart.render();
            container._apexChartInstance = chart;
            return;
        }
    };

    // =========================================================================
    // 3. FINANCE REPORT 3-SERIES MULTI COMPARISON (APEXCHARTS)
    // =========================================================================
    BTSCharts.renderMultiComparison = function (containerId, timelineData = [], options = {}) {
        const container = (typeof containerId === 'string') ? document.getElementById(containerId) : containerId;
        if (!container) return;

        if (!timelineData || timelineData.length === 0) {
            cleanupChartInstance(container);
            container.innerHTML = `
                <div style="padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13.5px;">
                    No financial entries recorded in this reporting period.
                </div>
            `;
            return;
        }

        let points = [...timelineData];
        if (points.length === 1) {
            const single = points[0];
            const prevD = new Date(new Date(single.date + 'T00:00:00').getTime() - 864e5).toISOString().split('T')[0];
            points = [
                { date: prevD, sales: 0, expenses: 0, net: 0 },
                single
            ];
        }

        const currency = options.currency || '₱';
        const labels = points.map(d => {
            const dateObj = new Date(d.date + 'T00:00:00');
            return dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const sales = points.map(d => parseFloat(d.sales) || 0);
        const expenses = points.map(d => parseFloat(d.expenses) || 0);
        const net = points.map(d => parseFloat(d.net) || 0);

        if (window.ApexCharts) {
            cleanupChartInstance(container);
            container.innerHTML = '';

            const apexOptions = {
                series: [
                    {
                        name: 'Gross Sales',
                        type: 'area',
                        data: sales
                    },
                    {
                        name: 'Expenses',
                        type: 'line',
                        data: expenses
                    },
                    {
                        name: 'Net Margin',
                        type: 'line',
                        data: net
                    }
                ],
                chart: {
                    height: 310,
                    type: 'line',
                    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 550
                    }
                },
                colors: ['#10b981', '#ef4444', '#2563eb'], // Green, Red, Blue
                stroke: {
                    curve: 'smooth',
                    width: [3.2, 2.5, 3.2],
                    dashArray: [0, 5, 0] // Sales solid, Expenses dashed, Net solid
                },
                fill: {
                    type: ['gradient', 'solid', 'solid'],
                    gradient: {
                        shade: 'light',
                        type: 'vertical',
                        shadeIntensity: 0.35,
                        opacityFrom: [0.25, 0, 0],
                        opacityTo: [0.01, 0, 0],
                        stops: [0, 90, 100]
                    }
                },
                markers: {
                    size: [5, 4.5, 5.5],
                    colors: ['#ffffff', '#ffffff', '#ffffff'],
                    strokeColors: ['#10b981', '#ef4444', '#2563eb'],
                    strokeWidth: 2.5,
                    hover: { size: 7.5, sizeOffset: 3 }
                },
                grid: {
                    borderColor: '#e8edf5',
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { top: 6, right: 18, bottom: 4, left: 14 }
                },
                xaxis: {
                    categories: labels,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    tooltip: { enabled: false },
                    labels: {
                        style: { colors: '#64748b', fontSize: '11.5px', fontFamily: "'Plus Jakarta Sans', sans-serif", fontWeight: 500 }
                    },
                    crosshairs: {
                        show: true,
                        width: 66,
                        position: 'back',
                        opacity: 0.08,
                        stroke: { width: 0 },
                        fill: { type: 'solid', color: '#2563eb' },
                        dropShadow: { enabled: false }
                    }
                },
                yaxis: {
                    tickAmount: 4,
                    labels: {
                        style: { colors: '#94a3b8', fontSize: '11px', fontFamily: "'Plus Jakarta Sans', sans-serif", fontWeight: 500 },
                        formatter: (val) => formatCompact(val, currency)
                    }
                },
                legend: {
                    show: true,
                    position: 'top',
                    horizontalAlign: 'left',
                    fontSize: '14px',
                    fontWeight: 600,
                    labels: { colors: '#1e293b' },
                    markers: { width: 11, height: 11, radius: 12, offsetX: -4 },
                    itemMargin: { horizontal: 16, vertical: 6 },
                    offsetY: -4
                },
                tooltip: {
                    shared: true,
                    intersect: false,
                    custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                        const dateLabel = labels[dataPointIndex] || 'Date';
                        const s = (series[0] && series[0][dataPointIndex] !== undefined) ? Number(series[0][dataPointIndex]) : 0;
                        const e = (series[1] && series[1][dataPointIndex] !== undefined) ? Number(series[1][dataPointIndex]) : 0;
                        const n = (series[2] && series[2][dataPointIndex] !== undefined) ? Number(series[2][dataPointIndex]) : 0;

                        return `
                            <div style="background:#0f172a; border-radius:12px; padding:10px 14px; box-shadow:0 16px 36px rgba(15,23,42,0.45); border:1px solid rgba(255,255,255,0.12); color:#ffffff; font-family:'Plus Jakarta Sans',sans-serif; min-width:140px; text-align:left;">
                                <div style="font-weight:700; font-size:12.5px; color:#ffffff; margin-bottom:5px;">${dateLabel}</div>
                                <div style="font-size:12px; color:#34d399; margin-bottom:2px;">● Sales: ${formatCurrency(s, currency)}</div>
                                <div style="font-size:12px; color:#f87171; margin-bottom:2px;">● Expenses: ${formatCurrency(e, currency)}</div>
                                <div style="font-size:12px; color:${n >= 0 ? '#60a5fa' : '#fb7185'}; font-weight:700; padding-top:3px; border-top:1px solid rgba(255,255,255,0.15); margin-top:3px;">
                                    ● Net: ${formatCurrency(n, currency)}
                                </div>
                            </div>
                        `;
                    }
                }
            };

            const chart = new ApexCharts(container, apexOptions);
            chart.render();
            container._apexChartInstance = chart;
        }
    };

    // =========================================================================
    // 4. EXPENSE MIX DONUT CHART (APEXCHARTS - MATCHING REFERENCE DESIGN)
    // =========================================================================
    BTSCharts.renderExpenseMixDonut = function (chartContainerId, listContainerId, categories = [], options = {}) {
        const chartContainer = (typeof chartContainerId === 'string') ? document.getElementById(chartContainerId) : chartContainerId;
        const listContainer = (typeof listContainerId === 'string') ? document.getElementById(listContainerId) : listContainerId;
        if (!chartContainer) return;

        const currency = options.currency || '₱';
        // Elegant palette matching the reference image (Royal Blue, Sky Blue, Amber, Emerald, Pink, Violet)
        const colors = ['#2563eb', '#38bdf8', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6', '#64748b'];

        // Filter valid categories with positive amounts
        const validCats = (categories || []).filter(c => parseFloat(c.category_total) > 0);

        cleanupChartInstance(chartContainer);
        chartContainer.innerHTML = '';

        if (validCats.length === 0) {
            chartContainer.innerHTML = `
                <div style="padding: 40px 10px; text-align: center; color: var(--text-muted); font-size: 13px;">
                    <div style="font-size: 32px; margin-bottom: 6px;">🏷️</div>
                    No categorized expenses recorded in this period.
                </div>
            `;
            if (listContainer) listContainer.innerHTML = '';
            return;
        }

        const series = validCats.map(c => Math.round(parseFloat(c.category_total)));
        const labels = validCats.map(c => c.category_name);
        const totalAmount = series.reduce((a, b) => a + b, 0);

        if (window.ApexCharts) {
            const apexOptions = {
                series: series,
                labels: labels,
                chart: {
                    type: 'donut',
                    height: 245,
                    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 550
                    }
                },
                colors: colors.slice(0, series.length),
                stroke: {
                    width: 3,
                    colors: ['#ffffff']
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '72%',
                            labels: {
                                show: true,
                                name: {
                                    show: true,
                                    fontSize: '12.5px',
                                    fontFamily: "'Plus Jakarta Sans', sans-serif",
                                    fontWeight: 500,
                                    color: '#64748b',
                                    offsetY: -4
                                },
                                value: {
                                    show: true,
                                    fontSize: '20px',
                                    fontFamily: "'Plus Jakarta Sans', sans-serif",
                                    fontWeight: 800,
                                    color: '#0f172a',
                                    offsetY: 6,
                                    formatter: function (val) {
                                        return currency + Number(val).toLocaleString();
                                    }
                                },
                                total: {
                                    show: true,
                                    showAlways: true,
                                    label: 'Total',
                                    fontSize: '12.5px',
                                    fontFamily: "'Plus Jakarta Sans', sans-serif",
                                    fontWeight: 500,
                                    color: '#64748b',
                                    formatter: function (w) {
                                        const sum = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                        return currency + Math.round(sum).toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                },
                dataLabels: {
                    enabled: false
                },
                legend: {
                    show: false
                },
                tooltip: {
                    theme: 'dark',
                    y: {
                        formatter: function (val) {
                            const pct = totalAmount > 0 ? ((val / totalAmount) * 100).toFixed(1) : 0;
                            return `${currency}${Number(val).toLocaleString()} (${pct}%)`;
                        }
                    }
                }
            };

            const chart = new ApexCharts(chartContainer, apexOptions);
            chart.render();
            chartContainer._apexChartInstance = chart;
        }

        // Render custom item list below matching the user's reference image
        if (listContainer) {
            let listHtml = '';
            validCats.forEach((c, idx) => {
                const dotColor = colors[idx % colors.length];
                const amt = parseFloat(c.category_total) || 0;
                const pct = (totalAmount > 0) ? ((amt / totalAmount) * 100).toFixed(1) : c.percentage;
                listHtml += `
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; font-size: 13px;">
                        <div style="display: flex; align-items: center; gap: 10px; min-width: 0; padding-right: 10px;">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: ${dotColor}; flex-shrink: 0;"></span>
                            <span style="font-weight: 600; color: #1e293b; line-height: 1.3;" title="${escapeHtml(c.category_name)}">${escapeHtml(c.category_name)}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 14px; flex-shrink: 0;">
                            <strong style="color: #0f172a; font-size: 13.5px; font-weight: 700;">${currency}${Math.round(amt).toLocaleString()}</strong>
                            <span style="color: #64748b; font-size: 12px; min-width: 42px; text-align: right; font-weight: 500;">${pct}%</span>
                        </div>
                    </div>
                `;
            });
            listContainer.innerHTML = listHtml;
        }
    };

    /**
     * Pure SVG Fallback generator in case ApexCharts is ever blocked
     */
    function renderSvgDualChartFallback(container, sales, expenses, labels, currency) {
        container.innerHTML = `
            <div style="padding: 30px; text-align: center; color: var(--text-muted); font-size: 13px;">
                Rendering chart...
            </div>
        `;
    }

    // Auto-render hook for existing calls
    window.renderSalesTrend = function (customData, customLabels) {
        BTSCharts.renderDashboardDualChart('salesTrendChart', {
            sales: customData,
            labels: customLabels
        });
    };

    window.BTSCharts = BTSCharts;

})(window);
