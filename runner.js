/**
 * EventMS Load Test Master Suite
 * Automates Scenarios A to H and produces detailed measurement tables
 */
const { CookieJar, calculateStats, sleep } = require('./helpers');
const { runDb, COUNTER_PASSWORDS, loginUser, performSingleCheckin, BASE_URL } = require('./scenarios');

async function getAdminSession(username = 'testadmin', password = 'testadmin@123') {
    return await loginUser(username, password);
}

async function getAllCounterSessions() {
    const sessions = {};
    for (const [username, pass] of Object.entries(COUNTER_PASSWORDS)) {
        sessions[username] = await loginUser(username, pass);
    }
    return sessions;
}

// =========================================================================
// SCENARIO A: Peak Gate Burst (100 check-ins in 60s)
// =========================================================================
async function runScenarioA() {
    console.log('\n--- STARTING SCENARIO A: Peak Gate Burst (100 in 60s) ---');
    runDb('reset_all');

    const visitors = runDb('get_visitors', 100);
    const latenciesLoad = [];
    const latenciesSubmit = [];
    let successes = 0;
    let rateLimited = 0;
    let errors4xx = 0;
    let errors5xx = 0;

    const startTime = performance.now();
    const tasks = visitors.map((v, idx) => {
        return (async () => {
            const delay = (idx / visitors.length) * 45000; // spread over 45s
            await sleep(delay);

            const identifier = (idx % 2 === 0) ? v.user_id : v.mobile;
            const pauseMs = 1000 + Math.random() * 3000;

            const res = await performSingleCheckin(identifier, pauseMs);
            if (res.loadTime > 0) latenciesLoad.push(res.loadTime);
            if (res.postTime > 0) latenciesSubmit.push(res.postTime);

            if (res.status >= 500) errors5xx++;
            else if (res.status >= 400) errors4xx++;

            if (res.json?.status === 'success') successes++;
            else if (res.json?.status === 'rate_limited') rateLimited++;
        })();
    });

    await Promise.all(tasks);
    const totalDuration = (performance.now() - startTime) / 1000;

    const dbCounts = runDb('get_counts');
    const loadStats = calculateStats(latenciesLoad);
    const submitStats = calculateStats(latenciesSubmit);

    console.log(`Scenario A Done in ${totalDuration.toFixed(1)}s: ${successes}/100 successes, p95 submit=${submitStats.p95}ms, rateLimited=${rateLimited}`);

    return {
        name: 'Scenario A: Peak Gate Burst',
        durationSec: totalDuration.toFixed(1),
        totalRequests: visitors.length,
        successes,
        rateLimited,
        errors4xx,
        errors5xx,
        loadStats,
        submitStats,
        dbCounts,
        pass: (successes === 100 && errors5xx === 0 && submitStats.p95 < 2000)
    };
}

// =========================================================================
// SCENARIO B: Peak With Everyone Working
// =========================================================================
async function runScenarioB(counterSessions, adminSession1, adminSession2) {
    console.log('\n--- STARTING SCENARIO B: Peak With Everyone Working ---');
    runDb('reset_all');

    const visitors = runDb('get_visitors', 200);
    const gateVisitors = visitors.slice(100, 200);

    const latenciesLoad = [];
    const latenciesSubmit = [];
    const latenciesKitRefresh = [];
    const latenciesCounters = [];
    const latenciesGateAdmin = [];

    let successes = 0;
    let kitsGiven = 0;
    let errors4xx = 0;
    let errors5xx = 0;

    let stopBackgroundWorkers = false;

    // 1. Start 20 counter pollers (every 2.5s)
    const counterPollers = Object.entries(counterSessions).map(([cname, sess]) => {
        return (async () => {
            while (!stopBackgroundWorkers) {
                const t0 = performance.now();
                try {
                    const res = await fetch(`${BASE_URL}/admin.php?tab=gift&dfilter=entered&ajax_live=1`, {
                        headers: { 'Cookie': sess.cookie, 'Accept': 'application/json' },
                        signal: AbortSignal.timeout(5000)
                    });
                    latenciesKitRefresh.push(performance.now() - t0);
                    if (res.status >= 500) errors5xx++;
                } catch (e) {}
                await sleep(2500);
            }
        })();
    });

    // 2. Start Admin 1 Gate Entry poller
    const adminGatePoller = (async () => {
        while (!stopBackgroundWorkers) {
            const t0 = performance.now();
            try {
                const res = await fetch(`${BASE_URL}/admin.php?tab=gate&ajax_live=1`, {
                    headers: { 'Cookie': adminSession1.cookie, 'Accept': 'application/json' },
                    signal: AbortSignal.timeout(5000)
                });
                latenciesGateAdmin.push(performance.now() - t0);
                if (res.status >= 500) errors5xx++;
            } catch (e) {}
            await sleep(2500);
        }
    })();

    // 3. Start Admin 2 Counters poller
    const adminCountersPoller = (async () => {
        while (!stopBackgroundWorkers) {
            const t0 = performance.now();
            try {
                const res = await fetch(`${BASE_URL}/admin.php?tab=counters`, {
                    headers: { 'Cookie': adminSession2.cookie },
                    signal: AbortSignal.timeout(5000)
                });
                latenciesCounters.push(performance.now() - t0);
                if (res.status >= 500) errors5xx++;
            } catch (e) {}
            await sleep(2500);
        }
    })();

    // 4. Run 100 check-ins over 45 seconds
    const startTime = performance.now();
    const enteredVisitors = [];

    const gateTasks = gateVisitors.map((v, idx) => {
        return (async () => {
            const delay = (idx / gateVisitors.length) * 40000;
            await sleep(delay);

            const identifier = (idx % 2 === 0) ? v.user_id : v.mobile;
            const pauseMs = 1000 + Math.random() * 2000;
            const res = await performSingleCheckin(identifier, pauseMs);

            if (res.loadTime > 0) latenciesLoad.push(res.loadTime);
            if (res.postTime > 0) latenciesSubmit.push(res.postTime);
            if (res.status >= 500) errors5xx++;
            else if (res.status >= 400) errors4xx++;

            if (res.json?.status === 'success') {
                successes++;
                enteredVisitors.push(v);
            }
        })();
    });

    // 5. Concurrently give kits to 30 entered test visitors
    const kitHandoverTask = (async () => {
        while (enteredVisitors.length < 30 && (performance.now() - startTime) < 45000) {
            await sleep(1000);
        }
        const toGive = enteredVisitors.slice(0, 30);
        for (const v of toGive) {
            const sess = counterSessions[v.counter_username] || adminSession1;
            const t0 = performance.now();
            try {
                const res = await fetch(`${BASE_URL}/admin.php?tab=gift&action=mark_given&id=${v.gift_id}`, {
                    headers: {
                        'Cookie': sess.cookie,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: AbortSignal.timeout(5000)
                });
                latenciesKitRefresh.push(performance.now() - t0);
                if (res.status >= 500) errors5xx++;
                else if (res.status >= 400) errors4xx++;
                else kitsGiven++;
            } catch (e) {
                errors5xx++;
            }
            await sleep(250);
        }
    })();

    try {
        await Promise.all([Promise.all(gateTasks), kitHandoverTask]);
    } finally {
        stopBackgroundWorkers = true;
    }
    await Promise.all([...counterPollers, adminGatePoller, adminCountersPoller]);

    const totalDuration = (performance.now() - startTime) / 1000;
    const dbCounts = runDb('get_counts');
    const submitStats = calculateStats(latenciesSubmit);

    console.log(`Scenario B Done in ${totalDuration.toFixed(1)}s: ${successes}/100 gate entries, ${kitsGiven}/30 kits given, p95 submit=${submitStats.p95}ms`);

    return {
        name: 'Scenario B: Peak With Everyone Working',
        durationSec: totalDuration.toFixed(1),
        gateCheckinsAttempted: gateVisitors.length,
        gateCheckinsSuccessful: successes,
        kitsGiven,
        errors4xx,
        errors5xx,
        loadStats: calculateStats(latenciesLoad),
        submitStats,
        kitRefreshStats: calculateStats(latenciesKitRefresh),
        countersStats: calculateStats(latenciesCounters),
        adminGateStats: calculateStats(latenciesGateAdmin),
        dbCounts,
        pass: (successes === 100 && kitsGiven === 30 && errors5xx === 0 && submitStats.p95 < 2000)
    };
}

// =========================================================================
// SCENARIO C: Double Submit Race Condition (10 visitors, 20 parallel requests)
// =========================================================================
async function runScenarioC() {
    console.log('\n--- STARTING SCENARIO C: Double Submit Race Condition ---');
    runDb('reset_all');

    const visitors = runDb('get_visitors', 210).slice(200, 210); // 10 visitors
    let duplicateResponses = 0;
    let welcomeResponses = 0;
    let errors5xx = 0;

    for (const v of visitors) {
        const promises = [];
        for (let r = 0; r < 20; r++) {
            promises.push((async () => {
                const res = await performSingleCheckin(v.user_id, 0);
                if (res.status >= 500) errors5xx++;
                if (res.json?.status === 'success') welcomeResponses++;
                if (res.json?.status === 'duplicate') duplicateResponses++;
                return res;
            })());
        }
        await Promise.all(promises);
    }

    const dbCounts = runDb('get_counts');
    const dupCheck = runDb('check_duplicates');

    console.log(`Scenario C Done: ${welcomeResponses} welcome responses, ${duplicateResponses} duplicate notices, DB Day 1 checkins=${dbCounts.d1_checkins}`);

    return {
        name: 'Scenario C: Double Submit Race Condition',
        visitorsTested: visitors.length,
        parallelPerVisitor: 20,
        totalRequestsSent: visitors.length * 20,
        welcomeResponses,
        duplicateResponses,
        errors5xx,
        dbDay1Checkins: dbCounts.d1_checkins,
        dbDistinctDay1Visitors: dbCounts.distinct_d1_visitors,
        duplicateRowsInDb: dupCheck.duplicate_visitor_rows.length,
        pass: (dbCounts.d1_checkins === 10 && dbCounts.distinct_d1_visitors === 10 && welcomeResponses === 10 && dupCheck.duplicate_visitor_rows.length === 0)
    };
}

// =========================================================================
// SCENARIO D: Kit Race Condition (10 visitors, 10 correct + 10 wrong parallel requests)
// =========================================================================
async function runScenarioD(counterSessions) {
    console.log('\n--- STARTING SCENARIO D: Kit Race Condition ---');
    runDb('reset_all');

    const visitors = runDb('get_visitors', 220).slice(210, 220); // 10 visitors
    for (const v of visitors) {
        await performSingleCheckin(v.user_id, 0);
    }

    let correctAllowed = 0;
    let correctAlreadyCollected = 0;
    let wrongRefused = 0;
    let errors5xx = 0;

    for (const v of visitors) {
        const correctSess = counterSessions[v.counter_username];
        const wrongUsername = (v.counter_username === 'raipur') ? 'dehradun' : 'raipur';
        const wrongSess = counterSessions[wrongUsername];

        const promises = [];
        for (let i = 0; i < 10; i++) {
            promises.push((async () => {
                const res = await fetch(`${BASE_URL}/admin.php?tab=gift&action=mark_given&id=${v.gift_id}`, {
                    headers: {
                        'Cookie': correctSess.cookie,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: AbortSignal.timeout(5000)
                });
                if (res.status >= 500) errors5xx++;
                const json = await res.json().catch(() => null);
                if (res.ok && json?.success) correctAllowed++;
                else correctAlreadyCollected++;
            })());

            promises.push((async () => {
                const res = await fetch(`${BASE_URL}/admin.php?tab=gift&action=mark_given&id=${v.gift_id}`, {
                    headers: {
                        'Cookie': wrongSess.cookie,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: AbortSignal.timeout(5000)
                });
                if (res.status === 403) wrongRefused++;
                else if (res.status >= 500) errors5xx++;
            })());
        }

        await Promise.all(promises);
    }

    const dbCounts = runDb('get_counts');
    const dupCheck = runDb('check_duplicates');

    console.log(`Scenario D Done: Kits collected=${dbCounts.kits_collected}, wrong counter refused=${wrongRefused}/100, DB duplicate kits=${dupCheck.duplicate_gift_rows.length}`);

    return {
        name: 'Scenario D: Kit Race Condition',
        visitorsTested: visitors.length,
        totalRequestsSent: visitors.length * 20,
        correctAllowed,
        wrongRefused,
        errors5xx,
        dbKitsCollected: dbCounts.kits_collected,
        dbDistinctKitVisitors: dbCounts.distinct_kits_visitors,
        duplicateGiftsInDb: dupCheck.duplicate_gift_rows.length,
        pass: (dbCounts.kits_collected === 10 && dbCounts.distinct_kits_visitors === 10 && wrongRefused === 100 && dupCheck.duplicate_gift_rows.length === 0)
    };
}

// =========================================================================
// SCENARIO E: Stress Test (300 check-ins in 60 seconds)
// =========================================================================
async function runScenarioE() {
    console.log('\n--- STARTING SCENARIO E: Stress Test (300 in 60s) ---');
    runDb('reset_all');

    const visitors = runDb('get_visitors', 300);
    const latenciesLoad = [];
    const latenciesSubmit = [];
    let successes = 0;
    let rateLimited = 0;
    let errors4xx = 0;
    let errors5xx = 0;

    const startTime = performance.now();
    const tasks = visitors.map((v, idx) => {
        return (async () => {
            const delay = (idx / visitors.length) * 52000;
            await sleep(delay);

            const identifier = (idx % 2 === 0) ? v.user_id : v.mobile;
            const res = await performSingleCheckin(identifier, 300);

            if (res.loadTime > 0) latenciesLoad.push(res.loadTime);
            if (res.postTime > 0) latenciesSubmit.push(res.postTime);

            if (res.status >= 500) errors5xx++;
            else if (res.status >= 400) errors4xx++;

            if (res.json?.status === 'success') successes++;
            else if (res.json?.status === 'rate_limited') rateLimited++;
        })();
    });

    await Promise.all(tasks);
    const totalDuration = (performance.now() - startTime) / 1000;
    const dbCounts = runDb('get_counts');
    const submitStats = calculateStats(latenciesSubmit);

    console.log(`Scenario E Done in ${totalDuration.toFixed(1)}s: ${successes}/300 successes, p95 submit=${submitStats.p95}ms, rateLimited=${rateLimited}`);

    return {
        name: 'Scenario E: Stress Test (300 in 60s)',
        durationSec: totalDuration.toFixed(1),
        totalRequests: visitors.length,
        successes,
        rateLimited,
        errors4xx,
        errors5xx,
        loadStats: calculateStats(latenciesLoad),
        submitStats,
        dbCounts,
        pass: (successes === 300 && errors5xx === 0 && rateLimited === 0)
    };
}

// =========================================================================
// SCENARIO F: Realistic Event Rate (10 min steady run at 21/min)
// =========================================================================
async function runScenarioF(counterSessions, adminSession1) {
    console.log('\n--- STARTING SCENARIO F: Realistic Event Rate (10 min at ~21/min) ---');
    runDb('reset_all');

    // 21 check-ins per minute for 10 minutes = 210 check-ins (1 every ~2.85s)
    const visitors = runDb('get_visitors', 210);
    const latenciesSubmit = [];
    let successes = 0;
    let errors5xx = 0;
    let stopPollers = false;

    // Start background activity (20 counters + admin polling every 2.5s)
    const pollers = Object.entries(counterSessions).map(([cname, sess]) => {
        return (async () => {
            while (!stopPollers) {
                try {
                    await fetch(`${BASE_URL}/admin.php?tab=gift&dfilter=entered&ajax_live=1`, {
                        headers: { 'Cookie': sess.cookie, 'Accept': 'application/json' },
                        signal: AbortSignal.timeout(5000)
                    });
                } catch (e) {}
                await sleep(2500);
            }
        })();
    });

    const startTime = performance.now();
    for (let i = 0; i < visitors.length; i++) {
        const v = visitors[i];
        const res = await performSingleCheckin(v.user_id, 500);
        if (res.postTime > 0) latenciesSubmit.push(res.postTime);
        if (res.status >= 500) errors5xx++;
        if (res.json?.status === 'success') successes++;

        if ((i + 1) % 21 === 0) {
            const minNum = Math.round((i + 1) / 21);
            const elapsed = Math.round((performance.now() - startTime) / 1000);
            console.log(`[Scenario F] Minute ${minNum}/10 progress: ${i + 1}/210 check-ins done (${elapsed}s elapsed)`);
        }
        await sleep(2850);
    }

    stopPollers = true;
    await Promise.all(pollers);

    const totalDuration = (performance.now() - startTime) / 1000;
    const dbCounts = runDb('get_counts');
    const submitStats = calculateStats(latenciesSubmit);

    console.log(`Scenario F Done in ${totalDuration.toFixed(1)}s: ${successes}/210 successes, p95 submit=${submitStats.p95}ms`);

    return {
        name: 'Scenario F: Realistic Event Rate (10 min)',
        durationSec: totalDuration.toFixed(1),
        ratePerMinute: 21.0,
        checkinsAttempted: visitors.length,
        checkinsSuccessful: successes,
        errors5xx,
        submitStats,
        dbCounts,
        pass: (successes === visitors.length && errors5xx === 0 && submitStats.p95 < 1500)
    };
}

// =========================================================================
// SCENARIO G: Junk Traffic (Unknown IDs, bad formats, shared mobile)
// =========================================================================
async function runScenarioG() {
    console.log('\n--- STARTING SCENARIO G: Junk Traffic ---');
    runDb('reset_all');

    const junkInputs = [
        '9999999999', '8888888888', '7777777777', '1234567890', '9000999999',
        'UNKNOWN_999', 'INVALID_000', 'NOT_AN_ID_1', 'FAKE_REGISTER_9',
        'abc', '!!!@@@###', '9324564790', // shared mobile
        '9324564790', '   ', '0000000000', '123'
    ];

    let notFoundCount = 0;
    let multipleMatchesCount = 0;
    let invalidInputCount = 0;
    let errors5xx = 0;
    const latencies = [];

    for (const inp of junkInputs) {
        const res = await performSingleCheckin(inp, 0);
        if (res.postTime > 0) latencies.push(res.postTime);
        if (res.status >= 500) errors5xx++;
        if (res.json?.status === 'not_found') notFoundCount++;
        else if (res.json?.status === 'multiple_matches') multipleMatchesCount++;
        else if (res.json?.status === 'invalid_input') invalidInputCount++;
    }

    const dbCounts = runDb('get_counts');

    console.log(`Scenario G Done: ${notFoundCount} not found, ${multipleMatchesCount} multiple matches, ${invalidInputCount} invalid, 5xx=${errors5xx}`);

    return {
        name: 'Scenario G: Junk Traffic Handling',
        totalJunkRequests: junkInputs.length,
        notFoundCount,
        multipleMatchesCount,
        invalidInputCount,
        errors5xx,
        dbDay1CheckinsAfterJunk: dbCounts.d1_checkins,
        stats: calculateStats(latencies),
        pass: (errors5xx === 0 && multipleMatchesCount >= 2 && dbCounts.d1_checkins === 0)
    };
}

// =========================================================================
// SCENARIO H: Day Rule Tampering & Closed Gate
// =========================================================================
async function runScenarioH() {
    console.log('\n--- STARTING SCENARIO H: Day Rule & Date Tampering ---');
    runDb('reset_all');

    const testVis = 'LOADTEST_050';

    // 1. Send request with tampered day parameter
    const jar = new CookieJar();
    const getRes = await fetch(`${BASE_URL}/gate_checkin.php`, { signal: AbortSignal.timeout(5000) });
    jar.parse(getRes);
    const html = await getRes.text();
    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i);
    const csrf = match ? match[1] : '';

    const postTampered = await fetch(`${BASE_URL}/gate_checkin.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': jar.header(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: new URLSearchParams({
            identifier: testVis,
            csrf_token: csrf,
            day: '2',
            serverDay: '2'
        }).toString(),
        signal: AbortSignal.timeout(5000)
    });
    const tamperedJson = await postTampered.json().catch(() => null);

    const dbAfterTamper = runDb('get_counts');

    // 2. Set Event Settings in DB so date is NOT Day 1 or Day 2
    runDb('set_event_dates', '2026-11-10', '2026-11-11', '1');
    const postClosedDate = await performSingleCheckin('LOADTEST_051', 0);

    // 3. Set self_checkin_open = 0 (Closed switch)
    runDb('set_event_dates', dateToday(), '2026-11-20', '0');
    const postSwitchClosed = await performSingleCheckin('LOADTEST_052', 0);

    // Restore Day 1 date and Open switch
    runDb('set_event_dates', dateToday(), '2026-11-20', '1');

    console.log(`Scenario H Done: tampered day recorded as Day ${tamperedJson?.day}, closed date status=${postClosedDate.json?.status}, closed switch status=${postSwitchClosed.json?.status}`);

    return {
        name: 'Scenario H: Day Rule & Date Tampering',
        tamperedDayResult: tamperedJson?.day,
        closedDateStatus: postClosedDate.json?.status,
        closedDateMsg: postClosedDate.json?.message,
        closedSwitchStatus: postSwitchClosed.json?.status,
        closedSwitchMsg: postSwitchClosed.json?.message,
        dbDay1Checkins: dbAfterTamper.d1_checkins,
        dbDay2Checkins: dbAfterTamper.d2_checkins,
        pass: (tamperedJson?.day === 1 && postClosedDate.json?.status === 'not_open_today' && postSwitchClosed.json?.status === 'gate_closed')
    };
}

function dateToday() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// =========================================================================
// MASTER RUNNER
// =========================================================================
async function runAll() {
    console.log('=====================================================');
    console.log('EVENTMS REALISTIC LOAD TEST EXECUTION');
    console.log('Target: Local Copy (eventms_loadtest)');
    console.log('=====================================================');

    const realDbBefore = runDb('get_real_db_counts');
    console.log('REAL DATABASE COUNTS (BEFORE):', realDbBefore);

    console.log('Logging in counter accounts and test admin...');
    const counterSessions = await getAllCounterSessions();
    const adminSession1 = await getAdminSession('testadmin', 'testadmin@123');
    const adminSession2 = await getAdminSession('testadmin', 'testadmin@123');

    const resA = await runScenarioA();
    const resB = await runScenarioB(counterSessions, adminSession1, adminSession2);
    const resC = await runScenarioC();
    const resD = await runScenarioD(counterSessions);
    const resE = await runScenarioE();
    const resF = await runScenarioF(counterSessions, adminSession1);
    const resG = await runScenarioG();
    const resH = await runScenarioH();

    const realDbAfter = runDb('get_real_db_counts');
    console.log('REAL DATABASE COUNTS (AFTER):', realDbAfter);

    const fullReport = {
        realDbBefore,
        realDbAfter,
        scenarios: {
            A: resA,
            B: resB,
            C: resC,
            D: resD,
            E: resE,
            F: resF,
            G: resG,
            H: resH
        }
    };

    console.log('\n--- COMPLETE JSON REPORT ---');
    console.log(JSON.stringify(fullReport, null, 2));
}

runAll().catch(err => {
    console.error('Fatal load test runner error:', err);
});
