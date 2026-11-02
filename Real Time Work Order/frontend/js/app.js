/**
 * js/app.js - Pure Traceability Math & Dynamic Custom Ranges
 */

const CONFIG = {
    API_URL: 'http://localhost/Real Time Work Order/backend/api.php' 
};

const API = {
    async fetchWorkOrders() {
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_work_orders`);
            const json = await res.json();
            return json.status === 'success' ? json.data : [];
        } catch (e) { return []; }
    },
    // Ensure active_tab parameter is sent properly to control hardware
    async fetchMonitorData(woNumber, isActiveTab = false) {
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_monitor_data&wo=${woNumber}&active_tab=${isActiveTab}`);
            const json = await res.json();
            return json.status === 'success' ? json.data : null;
        } catch (e) { 
            console.error("Monitor API Failed:", e);
            return null; 
        }
    },
    async fetchAnalyticsData(woNumber) {
        try {
            const res = await fetch(`${CONFIG.API_URL}?action=get_analytics_data&wo=${woNumber}`);
            const json = await res.json();
            return json.status === 'success' ? json.data : null;
        } catch (e) { return null; }
    }
};

const Analytics = {
    charts: { output: null, operator: null, station: null, yield: null, defect: null }, 
    rawData: [], 
    terminalData: [], 
    defectData: [], 
    lastDataStr: null,

    getFormattedDate(dateObj) {
        const year = dateObj.getFullYear();
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    },

    getTodayString() {
        return this.getFormattedDate(new Date());
    },

    async render(woNumber) {
        if(!woNumber) return;

        const analyticsWoDisplay = document.getElementById('analytics-wo-id');
        if (analyticsWoDisplay) {
            analyticsWoDisplay.innerText = woNumber;
        }

        const data = await API.fetchAnalyticsData(woNumber);
        
        // Pass false so loading Analytics does not accidentally turn on the hardware lights
        const monitorData = await API.fetchMonitorData(woNumber, false); 
        if(!data) return;

        const str = JSON.stringify(data) + JSON.stringify(monitorData);
        if (this.lastDataStr === str) return; 
        this.lastDataStr = str;

        this.rawData = data.raw_transactions || [];
        this.defectData = monitorData ? monitorData.defects : [];
        
        let snMap = {};
        this.rawData.forEach(row => {
            let serial = row.sn ? String(row.sn).trim().toUpperCase() : 'UNKNOWN';
            if (!snMap[serial]) snMap[serial] = [];
            snMap[serial].push(row);
        });

        this.terminalData = [];
        Object.values(snMap).forEach(transactions => {
            transactions.sort((a, b) => new Date(a.date_time) - new Date(b.date_time));
            
            let failedScan = transactions.find(t => (t.result || '').toUpperCase() === 'FAIL');
            let lastScan = transactions[transactions.length - 1];
            let terminalTx = null;

            if (failedScan) {
                terminalTx = failedScan; 
            } else {
                let stName = lastScan.station_name || lastScan.station || '';
                let isEnd = stName.toUpperCase().includes('DUST_PLUG');
                if (isEnd || transactions.length >= 6) {
                    terminalTx = lastScan; 
                }
            }

            if (terminalTx) {
                this.terminalData.push(terminalTx);
            }
        });
        
        const startInput = document.getElementById('chart-filter-start');
        const endInput = document.getElementById('chart-filter-end');

        if (this.rawData.length > 0) {
            const firstDate = new Date(this.rawData[0].date_time);
            const lastDate = new Date(this.rawData[this.rawData.length - 1].date_time);
            
            if (startInput) startInput.value = this.getFormattedDate(firstDate);
            if (endInput) endInput.value = this.getFormattedDate(lastDate);
        } else {
            if (startInput) startInput.value = this.getTodayString();
            if (endInput) endInput.value = this.getTodayString();
        }

        this.checkShiftVisibility();
        this.renderDynamicCharts(); 
        this.renderStaticCharts(); 
    },

    onFilterModeChange() {
        const modeEl = document.querySelector('input[name="filter-mode"]:checked');
        const mode = modeEl ? modeEl.value : 'today';
        const cf = document.getElementById('custom-date-filters');
        if(cf) cf.classList.toggle('hidden', mode !== 'daily');
        
        this.checkShiftVisibility();
        this.renderDynamicCharts();
    },

    checkShiftVisibility() {
        const modeEl = document.querySelector('input[name="filter-mode"]:checked');
        if (!modeEl) return;
        const mode = modeEl.value;
        const shiftFilters = document.getElementById('shift-filters');
        if (!shiftFilters) return;

        if (mode === 'today') {
            shiftFilters.classList.remove('hidden');
            shiftFilters.classList.add('flex');
        } else if (mode === 'daily') {
            const startStr = document.getElementById('chart-filter-start').value;
            const endStr = document.getElementById('chart-filter-end').value;
            if (startStr && startStr === endStr) {
                shiftFilters.classList.remove('hidden');
                shiftFilters.classList.add('flex');
            } else {
                shiftFilters.classList.add('hidden');
                shiftFilters.classList.remove('flex');
            }
        } else {
            shiftFilters.classList.add('hidden');
            shiftFilters.classList.remove('flex');
        }
    },

    renderDynamicCharts() {
        const errorMsg = document.getElementById('chart-error');
        if(errorMsg) errorMsg.classList.add('hidden');
        
        if(this.charts.output) this.charts.output.destroy();
        if(this.charts.yield) this.charts.yield.destroy();

        if (this.terminalData.length === 0) { this.drawEmptyCharts(); return; }

        const modeEl = document.querySelector('input[name="filter-mode"]:checked');
        const mode = modeEl ? modeEl.value : 'today';
        let startDate, endDate, bucketMode = 'hourly'; 

        if (mode === 'today') {
            startDate = new Date();
            endDate = new Date();
            bucketMode = 'hourly'; 
        } 
        else if (mode === 'monthly') {
            startDate = new Date(this.terminalData[0].date_time);
            endDate = new Date(this.terminalData[this.terminalData.length - 1].date_time);
            bucketMode = 'monthly';
        } 
        else if (mode === 'daily') {
            const si = document.getElementById('chart-filter-start');
            const ei = document.getElementById('chart-filter-end');
            startDate = new Date(si ? si.value : this.getTodayString());
            endDate = new Date(ei ? ei.value : this.getTodayString());
            bucketMode = (startDate.getTime() === endDate.getTime()) ? 'hourly' : 'daily';
        }

        if (bucketMode === 'daily' || bucketMode === 'monthly') {
            startDate.setHours(0,0,0,0);
            endDate.setHours(23,59,59,999);
        }

        let labels = [], buckets = {}; 

        if (bucketMode === 'hourly') {
            let baseYear = startDate.getFullYear();
            let baseMonth = startDate.getMonth();
            let baseDate = startDate.getDate();
            
            const shiftEl = document.querySelector('input[name="shift-mode"]:checked');
            const shiftMode = shiftEl ? shiftEl.value : 'all';
            
            let numHours = 24;
            let startHour = 6;
            
            if (shiftMode === 'day') {
                startHour = 6;
                numHours = 12;
                startDate = new Date(baseYear, baseMonth, baseDate, 6, 0, 0, 0);
                endDate = new Date(baseYear, baseMonth, baseDate, 17, 59, 59, 999);
            } else if (shiftMode === 'night') {
                startHour = 18;
                numHours = 12;
                startDate = new Date(baseYear, baseMonth, baseDate, 18, 0, 0, 0);
                endDate = new Date(baseYear, baseMonth, baseDate + 1, 5, 59, 59, 999);
            } else {
                startHour = 6;
                numHours = 24;
                startDate = new Date(baseYear, baseMonth, baseDate, 6, 0, 0, 0);
                endDate = new Date(baseYear, baseMonth, baseDate + 1, 5, 59, 59, 999);
            }

            for(let i=0; i<numHours; i++) {
                let currentHr = (startHour + i) % 24;
                let displayHr = currentHr % 12 || 12;
                let ampm = currentHr >= 12 ? 'PM' : 'AM';
                let label = `${displayHr} ${ampm}`;
                if(!labels.includes(label)) { labels.push(label); buckets[label] = { vol: 0, pass: 0 }; }
            }
        } else if (bucketMode === 'daily') {
            let curr = new Date(startDate);
            while(curr <= endDate) {
                let label = this.getFormattedDate(curr);
                labels.push(label); buckets[label] = { vol: 0, pass: 0 };
                curr.setDate(curr.getDate() + 1);
            }
        } else if (bucketMode === 'monthly') {
            let curr = new Date(startDate); curr.setDate(1);
            while(curr <= endDate) {
                let label = `${curr.getFullYear()}-${String(curr.getMonth()+1).padStart(2,'0')}`;
                labels.push(label); buckets[label] = { vol: 0, pass: 0 };
                curr.setMonth(curr.getMonth() + 1);
            }
        }

        let dataFound = false;

        this.terminalData.forEach(terminalTx => {
            let d = new Date(terminalTx.date_time);
            if (d >= startDate && d <= endDate) {
                dataFound = true;
                let key = '';
                if (bucketMode === 'hourly') {
                    let hr = d.getHours();
                    let displayHr = hr % 12 || 12;
                    let ampm = hr >= 12 ? 'PM' : 'AM';
                    key = `${displayHr} ${ampm}`;
                } else if (bucketMode === 'daily') {
                    key = this.getFormattedDate(d);
                } else if (bucketMode === 'monthly') {
                    key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
                }
                
                if (buckets[key]) {
                    buckets[key].vol++;
                    if ((terminalTx.result || '').toUpperCase() === 'PASS') {
                        buckets[key].pass++;
                    }
                }
            }
        });

        if (!dataFound) { 
            if(errorMsg) errorMsg.classList.remove('hidden'); 
            this.drawEmptyCharts(); 
            return; 
        }

        let vols = labels.map(l => buckets[l].vol);
        let yields = labels.map(l => buckets[l].vol > 0 ? (buckets[l].pass / buckets[l].vol) * 100 : 100);

        const outChartType = document.getElementById('output-chart-type') ? document.getElementById('output-chart-type').value : 'bar';

        const outCtx = document.getElementById('chart-output');
        if (outCtx) {
            this.charts.output = new Chart(outCtx.getContext('2d'), {
                type: outChartType,
                data: { 
                    labels: labels, 
                    datasets: [{ 
                        label: 'Completed Units', 
                        data: vols, 
                        backgroundColor: outChartType === 'line' ? 'rgba(59, 130, 246, 0.1)' : '#3b82f6', 
                        borderColor: '#3b82f6',
                        borderWidth: outChartType === 'line' ? 2 : 0,
                        borderRadius: outChartType === 'bar' ? 4 : 0,
                        fill: outChartType === 'line'
                    }] 
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }

        const yCtx = document.getElementById('chart-yield');
        if (yCtx) {
            this.charts.yield = new Chart(yCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Yield %', data: yields, borderColor: '#10b981', tension: 0.1, fill: false },
                        { label: 'Target', data: Array(labels.length).fill(99.0), borderColor: '#ef4444', borderDash: [2, 2], pointRadius: 0, fill: false }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 80, max: 100 } } }
            });
        }
    },

    drawEmptyCharts() {
        if(this.charts.output) this.charts.output.destroy();
        if(this.charts.yield) this.charts.yield.destroy();
        const oCtx = document.getElementById('chart-output');
        const yCtx = document.getElementById('chart-yield');
        if(oCtx) this.charts.output = new Chart(oCtx.getContext('2d'), { type: 'bar', data: { labels: ['No Data'], datasets: [{ data: [0] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        if(yCtx) this.charts.yield = new Chart(yCtx.getContext('2d'), { type: 'line', data: { labels: ['No Data'], datasets: [{ data: [0] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
    },

    renderDefectChart() {
        if(this.charts.defect) this.charts.defect.destroy();
        
        const ctx = document.getElementById('analyticsDefectChart');
        if(!ctx) return;

        const defectList = this.defectData || [];

        if (defectList.length === 0) {
            this.charts.defect = new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: { labels: ['No Defects Yet'], datasets: [{ data: [0], backgroundColor: '#f1f5f9' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
            return;
        }

        const sel = document.getElementById('analytics-defect-chart-type');
        const type = sel ? sel.value : 'bar';
        const bgColors = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16'];
        
        const dList = defectList.slice(0, 5);

        // INTELLIGENT WORD WRAPPER LOGIC
        const wrapText = (text) => {
            if (text.length <= 12) return text;
            const words = text.split(/(?=[ \/])/); // Split keeping spaces or slashes
            const lines = [];
            let currentLine = '';
            words.forEach(word => {
                if ((currentLine + word).length > 12 && currentLine.length > 0) {
                    lines.push(currentLine.trim());
                    currentLine = word.trim();
                } else {
                    currentLine += word;
                }
            });
            if (currentLine) lines.push(currentLine.trim());
            return lines;
        };

        const wrappedLabels = dList.map(d => wrapText(d.defect_name));
        
        if(type === 'pareto') {
            let total = dList.reduce((a, b) => a + parseInt(b.defect_count), 0);
            let cum = 0;
            let lineData = dList.map(d => { cum += parseInt(d.defect_count); return (cum / total) * 100; });
            this.charts.defect = new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: wrappedLabels,
                    datasets: [
                        { type: 'line', label: 'Cumulative %', data: lineData, borderColor: '#9333ea', backgroundColor: '#9333ea', borderWidth: 2, yAxisID: 'y1', tension: 0.1 },
                        { type: 'bar', label: 'Defect Count', data: dList.map(d => parseInt(d.defect_count)), backgroundColor: bgColors, yAxisID: 'y' }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 0, minRotation: 0, autoSkip: false } }, y1: { position: 'right', max: 100, min: 0 } }, plugins: { legend: { display: false } } }
            });
        } else {
            this.charts.defect = new Chart(ctx.getContext('2d'), {
                type: type,
                data: { 
                    labels: wrappedLabels, 
                    datasets: [{ label: 'Defect Count', data: dList.map(d => parseInt(d.defect_count)), backgroundColor: bgColors, borderWidth: 0 }] 
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: type === 'bar' ? 'top' : 'right' } }, scales: type === 'bar' ? { x: { ticks: { maxRotation: 0, minRotation: 0, autoSkip: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } : {} }
            });
        }
    },

    renderStaticCharts() {
        if(this.charts.operator) this.charts.operator.destroy();
        if(this.charts.station) this.charts.station.destroy();

        let opMap = {};
        let stMap = {};

        this.rawData.forEach(tx => {
            let emp = tx.emp_name || tx.employee || 'Unknown';
            let st = tx.station_name || tx.station || 'Unknown';
            let res = (tx.result || '').toUpperCase();

            if (!opMap[emp]) opMap[emp] = { pass: 0, fail: 0 };
            if (res === 'PASS') opMap[emp].pass++;
            if (res === 'FAIL') opMap[emp].fail++;

            if (res === 'FAIL') {
                if (!stMap[st]) stMap[st] = 0;
                stMap[st]++;
            }
        });

        let opFailLabels = [];
        let opFailCounts = [];
        let opFailColors = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#3b82f6', '#8b5cf6', '#d946ef'];

        let totalFails = 0;
        Object.keys(opMap).forEach(emp => {
            if (opMap[emp].fail > 0) {
                totalFails += opMap[emp].fail;
            }
        });

        Object.keys(opMap).forEach(emp => {
            if (opMap[emp].fail > 0) {
                let perc = ((opMap[emp].fail / totalFails) * 100).toFixed(1);
                opFailLabels.push(`${emp} (${perc}%)`);
                opFailCounts.push(opMap[emp].fail);
            }
        });

        const opCtx = document.getElementById('chart-operator');
        if (opCtx) {
            this.charts.operator = new Chart(opCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: opFailLabels.length ? opFailLabels : ['No Defects'],
                    datasets: [{
                        data: opFailCounts.length ? opFailCounts : [1],
                        backgroundColor: opFailCounts.length ? opFailColors : ['#f1f5f9'],
                        borderWidth: 0
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { position: 'right', display: opFailCounts.length > 0 },
                        tooltip: { enabled: opFailCounts.length > 0 }
                    }
                }
            });
        }

        let stLabels = Object.keys(stMap);
        let stFails = stLabels.map(k => stMap[k]);

        const stCtx = document.getElementById('chart-station');
        if (stCtx) {
            this.charts.station = new Chart(stCtx.getContext('2d'), {
                type: 'bar',
                data: { 
                    labels: stLabels.length ? stLabels : ['No Data'], 
                    datasets: [{ label: 'Fails', data: stLabels.length ? stFails : [0], backgroundColor: '#f43f5e', borderRadius: 4 }] 
                },
                options: { 
                    indexAxis: 'y', 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { legend: { display: false } }, 
                    scales: { x: { suggestedMax: 10, beginAtZero: true, ticks: { precision: 0 } } } 
                }
            });
        }

        this.renderDefectChart(); 
    }
};

const Monitor = {
    currentWO: null,
    pollingTimer: null,
    chart: null,
    lastStr: null,
    lastDefects: [], 

    start(wo) {
        this.stop();
        this.currentWO = wo;
        const ind = document.getElementById('live-indicator');
        if(ind) ind.style.display = 'block';
        
        const woIdEl = document.getElementById('monitor-wo-id');
        if(woIdEl) woIdEl.innerText = wo;
        
        this.refresh();
        this.pollingTimer = setInterval(() => this.refresh(), 3000);
    },

    stop() {
        if(this.pollingTimer) clearInterval(this.pollingTimer);
        const ind = document.getElementById('live-indicator');
        if(ind) ind.style.display = 'none';
        
        // FIX: Removed the network kill switch here to prevent race conditions during start().
        // The App.navTo() function perfectly handles shutting off the hardware when leaving the page.
    },

    async refresh() {
        if(!this.currentWO) return;

        // Verify if we are physically looking at the Monitor tab to allow lights
        const isMonitorPageActive = document.getElementById('page-monitor')?.classList.contains('active') || false;
        
        const data = await API.fetchMonitorData(this.currentWO, isMonitorPageActive);
        if(!data) return;

        const s = JSON.stringify(data);
        if(this.lastStr === s) return;
        this.lastStr = s;

        const c = data.counters;
        const yieldVal = c.total > 0 ? (c.passed / c.total * 100) : 100;

        const pCur = document.getElementById('prog-current');
        const pTgt = document.getElementById('prog-target');
        const pBar = document.getElementById('prog-bar');
        const mOut = document.getElementById('monitor-output');
        const passC = document.getElementById('pass-count');
        const failC = document.getElementById('fail-count');
        const topD = document.getElementById('top-defect');

        if(pCur) pCur.innerText = c.total;
        if(pTgt) pTgt.innerText = c.target;
        if(pBar) pBar.style.width = Math.min((c.total / c.target * 100), 100) + "%";
        if(mOut) mOut.innerText = c.total;
        if(passC) passC.innerText = c.passed;
        if(failC) failC.innerText = c.failed;
        if(topD) topD.innerText = data.defects[0]?.defect_name || "NONE";

        const yText = document.getElementById('monitor-yield');
        const yCard = document.getElementById('monitor-yield-card');
        
        if(yText && yCard) {
            yText.innerText = yieldVal.toFixed(1) + "%";
            
            if (!document.getElementById('yellow-pulse-style')) {
                const style = document.createElement('style');
                style.id = 'yellow-pulse-style';
                style.innerHTML = `
                    @keyframes pulse-yellow { 0% { box-shadow: 0 0 0 0 rgba(234, 179, 8, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(234, 179, 8, 0); } 100% { box-shadow: 0 0 0 0 rgba(234, 179, 8, 0); } }
                    .warning-active { animation: pulse-yellow 2s infinite !important; border-color: #eab308 !important; background-color: #fefce8 !important; }
                `;
                document.head.appendChild(style);
            }

            const targetYield = 99.5;
            const currentTarget = parseInt(c.target) || 2000;
            const currentFailed = parseInt(c.failed) || 0;
            const maxAllowedFails = Math.floor(currentTarget * ((100 - targetYield) / 100));

            yCard.classList.remove('border-green-500', 'border-yellow-500', 'border-red-500', 'alarm-active', 'warning-active');

            if (currentFailed > maxAllowedFails) {
                yCard.classList.add('border-red-500', 'alarm-active');
                yText.className = 'text-4xl lg:text-5xl font-black text-red-600';
            } else if (yieldVal < targetYield && currentFailed > 0) {
                yCard.classList.add('border-yellow-500', 'warning-active');
                yText.className = 'text-4xl lg:text-5xl font-black text-yellow-500';
            } else {
                yCard.classList.add('border-green-500');
                yText.className = 'text-4xl lg:text-5xl font-black text-green-600';
            }
        }

        this.lastDefects = data.defects || [];
        this.renderDefectChart();
        
        document.querySelectorAll('.st-target').forEach(el => el.innerText = c.target);

        const analyticsData = await API.fetchAnalyticsData(this.currentWO);
        if (analyticsData && analyticsData.raw_transactions) {
            let stStats = {
                'FIN#CS-ENTRY': { sns: {} },
                'FIN#CS-INSPECTION_ASSEMBLY': { sns: {} },
                'FIN#CS-IPQC': { sns: {} },
                'FIN#CS-SEMI_AUTO_SWAGING': { sns: {} },
                'FIN#CS-VMI_SWAGING': { sns: {} },
                'FIN#CS-DUST_PLUG': { sns: {} }
            };

            analyticsData.raw_transactions.forEach(tx => {
                let st = tx.station_name;
                if (stStats[st]) {
                    if (!stStats[st].sns[tx.sn] || new Date(tx.date_time) > new Date(stStats[st].sns[tx.sn].date_time)) {
                        stStats[st].sns[tx.sn] = (tx.result || '').toUpperCase();
                    }
                }
            });

            const stations = ['FIN#CS-ENTRY', 'FIN#CS-INSPECTION_ASSEMBLY', 'FIN#CS-IPQC', 'FIN#CS-SEMI_AUTO_SWAGING', 'FIN#CS-VMI_SWAGING', 'FIN#CS-DUST_PLUG'];

            stations.forEach((st, idx) => {
                let passCount = 0;
                let failCount = 0;
                
                Object.values(stStats[st].sns).forEach(res => {
                    if (res === 'FAIL') failCount++;
                    else passCount++; 
                });

                let validOutput = passCount;
                let safeTarget = c.target > 0 ? c.target : 1; 
                let pct = Math.min((validOutput / safeTarget * 100), 100);

                let elCount = document.getElementById(`st-qty-${idx+1}`);
                let elBar = document.getElementById(`st-bar-${idx+1}`);
                let elPass = document.getElementById(`st-pass-${idx+1}`);
                let elFail = document.getElementById(`st-fail-${idx+1}`);

                if(elCount) elCount.innerText = validOutput;
                if(elBar) elBar.style.width = pct + '%';
                if(elPass) elPass.innerText = passCount;
                if(elFail) elFail.innerText = failCount;
            });
        }

        const pAna = document.getElementById('page-analytics');
        if(pAna && pAna.classList.contains('active')) {
            Analytics.render(this.currentWO);
        }
    },

    renderDefectChart() {
        const ctx = document.getElementById('monitorDefectChart');
        if (!ctx) return;
        if(this.chart) this.chart.destroy();

        const dList = this.lastDefects.slice(0, 5);
        
        if(dList.length > 0) {
            const sel = document.getElementById('monitor-defect-chart-type');
            const type = sel ? sel.value : 'bar';
            const bgColors = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16'];

            // INTELLIGENT WORD WRAPPER LOGIC
            const wrapText = (text) => {
                if (text.length <= 12) return text;
                const words = text.split(/(?=[ \/])/); // Split keeping spaces or slashes
                const lines = [];
                let currentLine = '';
                words.forEach(word => {
                    if ((currentLine + word).length > 12 && currentLine.length > 0) {
                        lines.push(currentLine.trim());
                        currentLine = word.trim();
                    } else {
                        currentLine += word;
                    }
                });
                if (currentLine) lines.push(currentLine.trim());
                return lines;
            };

            const wrappedLabels = dList.map(d => wrapText(d.defect_name));
            
            if (type === 'pareto') {
                let total = dList.reduce((a, b) => a + parseInt(b.defect_count), 0);
                let cum = 0;
                let lineData = dList.map(d => { cum += parseInt(d.defect_count); return (cum / total) * 100; });
                
                this.chart = new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: wrappedLabels,
                        datasets: [
                            { type: 'line', label: 'Cumulative %', data: lineData, borderColor: '#9333ea', backgroundColor: '#9333ea', borderWidth: 2, yAxisID: 'y1', tension: 0.1 },
                            { type: 'bar', label: 'Defect Count', data: dList.map(d => parseInt(d.defect_count)), backgroundColor: bgColors, yAxisID: 'y' }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 0, minRotation: 0, autoSkip: false } }, y1: { position: 'right', max: 100, min: 0 } }, plugins: { legend: { display: false } } }
                });
            } else {
                this.chart = new Chart(ctx.getContext('2d'), {
                    type: type,
                    data: { 
                        labels: wrappedLabels, 
                        datasets: [{ label: 'Defect Count', data: dList.map(d => parseInt(d.defect_count)), backgroundColor: bgColors, borderWidth: 0 }] 
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: type === 'bar' ? 'top' : 'right' } }, scales: type === 'bar' ? { x: { ticks: { maxRotation: 0, minRotation: 0, autoSkip: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } : {} }
                });
            }
        } else {
            this.chart = new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: { labels: ['No Defects Yet'], datasets: [{ data: [0], backgroundColor: '#f1f5f9' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }
    }
};

const App = {
    woList: [],
    poll: null,
    lastWOString: null,

    async init() {
        this.navTo('page-wo');
        document.getElementById('wo-search')?.addEventListener('keyup', () => this.renderWOList());
        document.getElementById('wo-sort')?.addEventListener('change', () => this.renderWOList());
    },

    navTo(pageId) {
        if ((pageId === 'page-monitor' || pageId === 'page-analytics') && !Monitor.currentWO) {
            const toast = document.getElementById('toast-msg');
            if (toast) {
                toast.classList.remove('translate-x-full', 'opacity-0');
                setTimeout(() => toast.classList.add('translate-x-full', 'opacity-0'), 3000);
            }
            return;
        }

        // KILL SWITCH: Send backend off signal when leaving the monitor page
        const wasMonitorActive = document.getElementById('page-monitor')?.classList.contains('active');
        if (wasMonitorActive && pageId !== 'page-monitor') {
            fetch(`${CONFIG.API_URL}?action=clear_active_wo`).catch(()=>{});
        }

        document.querySelectorAll('.page-section').forEach(p => p.classList.remove('active'));
        document.getElementById(pageId)?.classList.add('active');

        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.getElementById(pageId.replace('page-', 'nav-'))?.classList.add('active');

        if(pageId === 'page-wo') { 
            this.startPoll(); 
            Monitor.stop(); 
        } else if (pageId === 'page-monitor') {
            this.stopPoll(); 
            Monitor.start(Monitor.currentWO); 
        } else if (pageId === 'page-analytics') {
            this.stopPoll();
            Monitor.stop(); // Stop the monitor from running background fetches!
            Analytics.render(Monitor.currentWO);
        }
    },

    startPoll() { this.stopPoll(); this.load(); this.poll = setInterval(() => this.load(), 3000); },
    stopPoll() { if(this.poll) clearInterval(this.poll); },
    
    async load() { 
        const wos = await API.fetchWorkOrders(); 
        const s = JSON.stringify(wos);
        if (this.lastWOString !== s) {
            this.lastWOString = s;
            this.woList = wos;
            this.renderWOList();
        }
    },

    renderWOList() {
        const container = document.getElementById('wo-list-container');
        if(!container) return;
        const searchInput = document.getElementById('wo-search');
        const sortInput = document.getElementById('wo-sort');
        const term = searchInput ? searchInput.value.toLowerCase() : '';
        const sort = sortInput ? sortInput.value : 'newest';
        
        let filtered = this.woList.filter(wo => wo.id.toLowerCase().includes(term));
        
        filtered.sort((a, b) => {
            let dateA = new Date(a.created_at || 0).getTime();
            let dateB = new Date(b.created_at || 0).getTime();
            return sort === 'newest' ? dateB - dateA : dateA - dateB;
        });
        
        container.innerHTML = filtered.length ? '' : '<div class="p-8 text-center text-slate-400 font-bold">No Work Orders found.</div>';
        
        filtered.forEach(wo => {
            const produced = parseInt(wo.produced) || 0;
            const qty = parseInt(wo.qty) || 1;
            const pct = Math.min(Math.round((produced / qty) * 100), 100);
            
            let dateStr = "Date Not Set";
            if(wo.created_at) {
                let d = new Date(wo.created_at);
                dateStr = d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
            }

            let statusStr = "PENDING";
            let statusColor = "bg-slate-100 text-slate-600 border border-slate-200";
            if (produced >= qty) {
                statusStr = "COMPLETED";
                statusColor = "bg-emerald-50 text-emerald-600 border border-emerald-200";
            } else if (produced > 0) {
                statusStr = "IN PROGRESS";
                statusColor = "bg-blue-50 text-blue-600 border border-blue-200";
            }

            container.innerHTML += `
            <div class="bg-white p-4 rounded-xl border flex flex-col gap-3 transition-all shadow-sm hover:shadow-md">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-blue-50 text-blue-500 w-10 h-10 rounded-full flex items-center justify-center text-lg"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-xl font-bold">${wo.id}</h3>
                                <span class="${statusColor} px-2 py-0.5 rounded-full text-[10px] font-black tracking-wider uppercase shadow-sm">${statusStr}</span>
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">Target: ${wo.qty} <span class="mx-1">•</span> Created: ${dateStr}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="Monitor.currentWO='${wo.id}'; App.navTo('page-monitor')" class="bg-blue-50 hover:bg-blue-600 text-blue-600 hover:text-white px-4 py-2 rounded-lg font-bold text-sm transition border border-blue-100">Monitor</button>
                        <button onclick="Monitor.currentWO='${wo.id}'; App.navTo('page-analytics')" class="bg-purple-50 hover:bg-purple-600 text-purple-600 hover:text-white px-4 py-2 rounded-lg font-bold text-sm transition border border-purple-100">Analytics</button>
                    </div>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2 mt-1"><div class="bg-blue-600 h-2 rounded-full" style="width: ${pct}%"></div></div>
            </div>`;
        });
    }
};

window.onload = () => App.init();

// --- CHATBOT & AI LOGIC ---

function toggleChat() {
    const chatWindow = document.getElementById('chat-window');
    if (chatWindow.classList.contains('hidden')) {
        chatWindow.classList.remove('hidden');
        chatWindow.classList.add('flex');
    } else {
        chatWindow.classList.add('hidden');
        chatWindow.classList.remove('flex');
    }
}

function toggleFullscreenChat() {
    const chatWindow = document.getElementById('chat-window');
    const icon = document.getElementById('chat-fullscreen-icon');
    
    if (chatWindow.style.height === '100vh') {
        // Revert to normal
        chatWindow.style.height = '600px';
        chatWindow.style.maxHeight = 'calc(100vh - 8rem)';
        chatWindow.style.width = '24rem'; 
        chatWindow.style.bottom = '7rem'; 
        chatWindow.style.left = '1.5rem'; 
        chatWindow.style.top = 'auto'; // Clear dragged top position
        chatWindow.style.maxWidth = 'calc(100vw - 3rem)';
        icon.classList.remove('fa-compress');
        icon.classList.add('fa-expand');
    } else {
        // Fullscreen
        chatWindow.style.height = '100vh';
        chatWindow.style.maxHeight = '100vh';
        chatWindow.style.width = '100vw';
        chatWindow.style.maxWidth = '100vw';
        chatWindow.style.bottom = '0';
        chatWindow.style.left = '0';
        chatWindow.style.top = '0'; // Snap to top
        icon.classList.remove('fa-expand');
        icon.classList.add('fa-compress');
    }
}

function appendChatMessage(msg, isUser = false, isHtml = false) {
    const msgs = document.getElementById('chat-messages');
    let html = '';
    
    if (isUser) {
        html = `
        <div class="flex items-start gap-2 justify-end">
            <div class="bg-purple-600 p-3 rounded-2xl rounded-tr-none shadow-sm text-sm text-white max-w-[85%] font-medium">
                ${msg}
            </div>
        </div>`;
    } else {
        html = `
        <div class="flex items-start gap-2">
            <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center shrink-0 border border-purple-200">
                <i class="fa-solid fa-robot"></i>
            </div>
            <div class="bg-white border border-slate-200 p-3 rounded-2xl rounded-tl-none shadow-sm text-sm text-slate-700 max-w-[85%] font-medium overflow-x-auto">
                ${isHtml ? msg : msg.replace(/\n/g, '<br>')}
            </div>
        </div>`;
    }
    
    msgs.innerHTML += html;
    msgs.scrollTop = msgs.scrollHeight;
}

async function handleSendChat() {
    const input = document.getElementById('chat-input');
    const question = input.value.trim();
    if (!question) return;

    appendChatMessage(question, true);
    input.value = '';
    
    // Add typing indicator
    const msgs = document.getElementById('chat-messages');
    const typingId = 'typing-' + Date.now();
    msgs.innerHTML += `
        <div id="${typingId}" class="flex items-start gap-2">
            <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center shrink-0 border border-purple-200">
                <i class="fa-solid fa-robot"></i>
            </div>
            <div class="bg-white border border-slate-200 p-3 rounded-2xl rounded-tl-none shadow-sm flex gap-1 items-center">
                <div class="w-2 h-2 bg-purple-400 rounded-full typing-dot"></div>
                <div class="w-2 h-2 bg-purple-400 rounded-full typing-dot" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 bg-purple-400 rounded-full typing-dot" style="animation-delay: 0.4s"></div>
            </div>
        </div>`;
    msgs.scrollTop = msgs.scrollHeight;

    try {
        const res = await fetch(`${CONFIG.API_URL.replace('api.php', 'ai_agent.php')}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'chat', question: question })
        });
        const data = await res.json();
        
        document.getElementById(typingId).remove();
        
        if (data.status === 'success') {
            if (data.type === 'chat') {
                appendChatMessage(data.message);
            } else if (data.type === 'sql') {
                let htmlRes = `<p class="mb-2">${data.message}</p>`;
                if (data.data && data.data.length > 0) {
                    htmlRes += `<table class="ai-table w-full text-left border-collapse mt-2"><thead><tr>`;
                    Object.keys(data.data[0]).forEach(k => htmlRes += `<th>${k}</th>`);
                    htmlRes += `</tr></thead><tbody>`;
                    data.data.forEach(row => {
                        htmlRes += `<tr>`;
                        Object.values(row).forEach(v => htmlRes += `<td>${v}</td>`);
                        htmlRes += `</tr>`;
                    });
                    htmlRes += `</tbody></table>`;
                } else {
                    htmlRes += `<p class="text-slate-400 italic">No results found.</p>`;
                }
                appendChatMessage(htmlRes, false, true);
            }
        } else {
            appendChatMessage(`Error: ${data.message}`);
        }
    } catch (e) {
        document.getElementById(typingId)?.remove();
        appendChatMessage("Network error connecting to AI Agent.");
    }
}

function sendQuickChat(msg) {
    const input = document.getElementById('chat-input');
    input.value = msg;
    handleSendChat();
}

async function triggerReportButton() {
    const modal = document.getElementById('report-modal');
    const content = document.getElementById('report-content');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    content.innerHTML = `<div class="flex flex-col items-center justify-center h-64 text-slate-500">
        <i class="fa-solid fa-spinner fa-spin text-4xl text-blue-500 mb-4"></i>
        <p class="font-bold animate-pulse">AI is analyzing factory data and writing report...</p>
    </div>`;

    try {
        const res = await fetch(`${CONFIG.API_URL.replace('api.php', 'ai_agent.php')}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'generate_report' })
        });
        const data = await res.json();
        if (data.status === 'success') {
            content.innerHTML = `<div class="report-container">${data.html}</div>`;
        } else {
            content.innerHTML = `<div class="text-red-500 font-bold text-center">Error: ${data.message}</div>`;
        }
    } catch (e) {
        content.innerHTML = `<div class="text-red-500 font-bold text-center">Network error.</div>`;
    }
}

function downloadReport(type, contentId) {
    const element = document.getElementById(contentId);
    if (type === 'pdf') {
        const opt = {
            margin: 0.5,
            filename: 'Factory_Intelligence_Report.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    } else {
        const preHtml = "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>Export HTML To Doc</title></head><body>";
        const postHtml = "</body></html>";
        const html = preHtml + element.innerHTML + postHtml;
        const blob = new Blob(['\ufeff', html], { type: 'application/msword' });
        const url = 'data:application/vnd.ms-word;charset=utf-8,' + encodeURIComponent(html);
        const downloadLink = document.createElement("a");
        document.body.appendChild(downloadLink);
        if (navigator.msSaveOrOpenBlob) {
            navigator.msSaveOrOpenBlob(blob, 'Factory_Report.doc');
        } else {
            downloadLink.href = url;
            downloadLink.download = 'Factory_Report.doc';
            downloadLink.click();
        }
        document.body.removeChild(downloadLink);
    }
}

function exportLastTableToCSV() {
    const tables = document.querySelectorAll('#chat-messages table');
    if (tables.length === 0) {
        alert("No table found in the chat to export!");
        return;
    }
    const table = tables[tables.length - 1];
    let csv = [];
    const rows = table.querySelectorAll('tr');
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) 
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        csv.push(row.join(','));
    }
    const csvFile = new Blob([csv.join('\n')], {type: 'text/csv'});
    const downloadLink = document.createElement("a");
    downloadLink.download = 'export.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

// --- CHATBOX DRAG LOGIC ---
function makeChatDraggable() {
    const chatWindow = document.getElementById('chat-window');
    const chatHeader = document.getElementById('chat-header');
    
    if (!chatWindow || !chatHeader) return;

    let isDragging = false;
    let offsetX, offsetY;

    chatHeader.addEventListener('mousedown', (e) => {
        // Ignore drag if clicking buttons inside the header
        if (e.target.closest('button')) return;

        isDragging = true;
        const rect = chatWindow.getBoundingClientRect();
        
        // Calculate offset of mouse relative to the top-left of the chat window
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;
        
        chatHeader.classList.remove('cursor-grab');
        chatHeader.style.cursor = 'grabbing';
        
        // Prevent text selection while dragging
        e.preventDefault();
    });

    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;

        let newX = e.clientX - offsetX;
        let newY = e.clientY - offsetY;

        // Keep chat window strictly within the viewport bounds
        const maxX = window.innerWidth - chatWindow.offsetWidth;
        const maxY = window.innerHeight - chatWindow.offsetHeight;

        newX = Math.max(0, Math.min(newX, maxX));
        newY = Math.max(0, Math.min(newY, maxY));

        // Apply new coordinates
        chatWindow.style.left = newX + 'px';
        chatWindow.style.top = newY + 'px';
        
        // Disable bottom and right constraints that would interfere with dragging
        chatWindow.style.bottom = 'auto';
        chatWindow.style.right = 'auto';
    });

    document.addEventListener('mouseup', () => {
        if (isDragging) {
            isDragging = false;
            chatHeader.classList.add('cursor-grab');
            chatHeader.style.cursor = '';
        }
    });
}

// Initialize drag functionality when DOM is ready
document.addEventListener('DOMContentLoaded', makeChatDraggable);